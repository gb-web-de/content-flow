/*
 * B4: the Visual Editor's persistent task select, plus the hover markers
 * that flag content already claimed by a *different* task.
 *
 * friendsoftypo3/visual-editor renders its own toolbar and content area
 * entirely inside `#typo3-contentIframe` (a persistent iframe TYPO3's
 * backend chrome reuses across module navigation) as a set of Lit web
 * components (ve-auto-save-toggle, ve-backend-save-button, ...) with no
 * server-side extension point for another package to hook into - see
 * Backend/index.js in the vendored package. wizard.js itself is never
 * loaded inside that iframe (LoadWizardModuleEventListener's
 * AfterBackendPageRenderEvent does not fire for VE's own module render), so
 * this runs entirely from the top chrome document and reaches into the
 * iframe's document directly - same-origin DOM access, not postMessage.
 *
 * Picking a task here is deliberately a *before-the-fact* declaration
 * ("this page's edits go to this task"), not a reaction to a save - VE can
 * autosave on every few keystrokes (ve-auto-save-toggle.js's debounced
 * doSave()), so a modal popping up after each one would make editing
 * unusable. See TaskAjaxController::setActiveTaskForPageAction() and
 * TaskAutoCreationService::resolveActiveTaskOverride() for the other half.
 *
 * Each editable field is its own <ve-editable-text>/<ve-editable-rich-text>
 * element carrying `table`/`uid` attributes for the record it edits (see
 * friendsoftypo3/visual-editor's Render/TextViewHelper.php) - that is what
 * the hover markers key off, no extra tagging needed on content_flow's side.
 */
import AjaxRequest from '@typo3/core/ajax/ajax-request.js'
import Notification from '@typo3/backend/notification.js'

const EDITABLE_SELECTOR = 've-editable-text, ve-editable-rich-text'

function hueForTaskUid(taskUid) {
  // A stable, distinct-enough hue per task without a stored color column -
  // the golden-angle step keeps consecutive uids visually apart.
  return (Number(taskUid) * 137.508) % 360
}

function memberKey(table, uid) {
  return table + ':' + uid
}

function isVisualEditorDocument(doc) {
  return doc?.querySelector('ve-auto-save-toggle') != null
}

function pageUidFromIframe(iframe) {
  try {
    const value = parseInt(new URLSearchParams(iframe.contentWindow.location.search).get('id'), 10)
    return value > 0 ? value : 0
  } catch (error) {
    return 0
  }
}

class VisualEditorTaskSelect {
  constructor(doc, pageUid) {
    this.doc = doc
    this.pageUid = pageUid
    this.activeTaskUid = null
    this.tooltip = null
    this.select = null
    this.dismissCommentPopover = null
  }

  async mount() {
    const anchor = this.doc.querySelector('ve-auto-save-toggle')
    if (!anchor?.parentElement) {
      return
    }

    const wrapper = this.doc.createElement('span')
    wrapper.className = 'contentflow-ve-task-select'
    wrapper.style.cssText = 'display:inline-flex; align-items:center; margin-right:.5em;'

    const select = this.doc.createElement('select')
    select.className = 'form-select form-select-sm'
    select.style.width = 'auto'
    select.title = 'Content Flow task'

    const placeholder = this.doc.createElement('option')
    placeholder.value = ''
    placeholder.textContent = 'Task'
    placeholder.disabled = true
    placeholder.selected = true
    select.append(placeholder)

    wrapper.append(select)
    anchor.parentElement.insertBefore(wrapper, anchor)
    this.select = select

    select.addEventListener('change', () => this.onChange())

    await this.reloadTasks()
    await this.reloadMarkers()
  }

  async reloadTasks() {
    if (!TYPO3.settings?.ajaxUrls?.contentflow_task_list_open_for_page) {
      return
    }
    try {
      const response = await new AjaxRequest(TYPO3.settings.ajaxUrls.contentflow_task_list_open_for_page)
        .withQueryArguments({ pageUid: this.pageUid })
        .get()
      const result = await response.resolve()
      const tasks = result.success === true && Array.isArray(result.tasks) ? result.tasks : []

      const current = this.select.value
      this.select.querySelectorAll('option[data-task]').forEach((option) => option.remove())

      tasks.forEach((task) => {
        const option = this.doc.createElement('option')
        option.value = String(task.uid)
        option.dataset.task = '1'
        option.textContent = task.title + ' (' + task.stageLabel + ')'
        this.select.append(option)
      })

      const createOption = this.doc.createElement('option')
      createOption.value = '__create__'
      createOption.dataset.task = '1'
      createOption.textContent = '+ Create new task'
      this.select.append(createOption)

      if (current && [...this.select.options].some((option) => option.value === current)) {
        this.select.value = current
      }
    } catch (error) {
      // Silent - the select just stays on its placeholder.
    }
  }

  async onChange() {
    const value = this.select.value
    if (value === '__create__') {
      await this.createTask()
      return
    }

    const taskUid = parseInt(value, 10)
    if (!taskUid) {
      return
    }

    this.select.disabled = true
    try {
      const response = await new AjaxRequest(TYPO3.settings.ajaxUrls.contentflow_task_set_active_for_page).post({
        pageUid: this.pageUid,
        taskUid,
      })
      const result = await response.resolve()
      if (result.success !== true) {
        Notification.error('Content Flow', result.message || 'Could not select this task.')
        return
      }

      this.activeTaskUid = taskUid
      const selectedOption = this.select.querySelector('option[value="' + taskUid + '"]')
      if (selectedOption) {
        selectedOption.textContent = (selectedOption.textContent || '').split(' (')[0] + ' (' + result.stageLabel + ')'
      }

      if (result.transitioned) {
        Notification.success('Content Flow', 'This task is now active for this page.')
      }
      if (result.comment) {
        this.offerCommentEdit(taskUid, result.commentUid, result.comment)
      }

      await this.reloadMarkers()
    } catch (error) {
      Notification.error('Content Flow', 'Could not reach the server.')
    } finally {
      this.select.disabled = false
    }
  }

  async createTask() {
    if (!TYPO3.settings?.ajaxUrls?.contentflow_task_create) {
      return
    }
    this.select.disabled = true
    try {
      const response = await new AjaxRequest(TYPO3.settings.ajaxUrls.contentflow_task_create).post({
        table: 'pages',
        uid: this.pageUid,
        // Left blank on purpose - createAction() falls back to the page's
        // own title, which is a better default than anything worth asking
        // the editor to type here.
        title: '',
      })
      const result = await response.resolve()
      if (result.success !== true) {
        Notification.error('Content Flow', result.message || 'Could not create a task for this page.')
        return
      }

      await this.reloadTasks()
      this.select.value = String(result.task)
      this.select.disabled = false
      await this.onChange()
    } catch (error) {
      Notification.error('Content Flow', 'Could not reach the server.')
    } finally {
      this.select.disabled = false
    }
  }

  offerCommentEdit(taskUid, commentUid, defaultComment) {
    this.doc.querySelector('.contentflow-ve-comment-popover')?.remove()
    if (this.dismissCommentPopover) {
      this.doc.removeEventListener('click', this.dismissCommentPopover, true)
      this.dismissCommentPopover = null
    }

    const popover = this.doc.createElement('div')
    popover.className = 'contentflow-ve-comment-popover'
    popover.style.cssText = 'position:absolute; z-index:1000; background:#fff; border:1px solid #ccc; ' +
      'border-radius:4px; padding:.5em; box-shadow:0 2px 8px rgba(0,0,0,.2); width:280px;'

    const label = this.doc.createElement('div')
    label.textContent = 'Reopened for editing - add a note?'
    label.style.cssText = 'font-size:.85em; margin-bottom:.25em;'

    const textarea = this.doc.createElement('textarea')
    textarea.className = 'form-control'
    textarea.rows = 2
    textarea.value = defaultComment

    const saveButton = this.doc.createElement('button')
    saveButton.type = 'button'
    saveButton.className = 'btn btn-sm btn-default'
    saveButton.textContent = 'Save note'
    saveButton.style.marginTop = '.35em'
    saveButton.addEventListener('click', async () => {
      try {
        // Generic core wizard_submit route (mode=contentflow_task_wizard),
        // not a content_flow-specific one - see Classes/Wizard/
        // TaskWizardProvider.php's regression_comment mode, the same one the
        // post-save wizard's comment step submits to.
        const response = await new AjaxRequest(TYPO3.settings.ajaxUrls.wizard_submit)
          .withQueryArguments({ mode: 'contentflow_task_wizard' })
          .post({
            mode: 'regression_comment',
            taskUid,
            commentUid,
            content: textarea.value,
          })
        const result = await response.resolve()
        if (result.success === true) {
          popover.remove()
        } else {
          Notification.error('Content Flow', (result.errors && result.errors[0]) || 'Could not save the note.')
        }
      } catch (error) {
        Notification.error('Content Flow', 'Could not reach the server.')
      }
    })

    popover.append(label, textarea, saveButton)
    this.doc.body.append(popover)

    const rect = this.select.getBoundingClientRect()
    const view = this.doc.defaultView
    popover.style.top = rect.bottom + view.scrollY + 4 + 'px'
    popover.style.left = rect.left + view.scrollX + 'px'

    const dismiss = (event) => {
      if (!popover.contains(event.target) && event.target !== this.select) {
        popover.remove()
        this.doc.removeEventListener('click', dismiss, true)
      }
    }
    this.dismissCommentPopover = dismiss
    view.setTimeout(() => this.doc.addEventListener('click', dismiss, true), 0)
  }

  async reloadMarkers() {
    if (!TYPO3.settings?.ajaxUrls?.contentflow_task_list_member_markers) {
      return
    }
    try {
      const response = await new AjaxRequest(TYPO3.settings.ajaxUrls.contentflow_task_list_member_markers)
        .withQueryArguments({ pageUid: this.pageUid })
        .get()
      const result = await response.resolve()
      if (result.success !== true) {
        return
      }

      const taskTitles = new Map((result.tasks || []).map((task) => [task.uid, task.title]))
      const claims = new Map((result.members || []).map((member) => [memberKey(member.table, member.uid), member.taskUid]))

      this.applyMarkers(claims, taskTitles)
    } catch (error) {
      // Silent - hover markers are a nicety, not load-bearing.
    }
  }

  applyMarkers(claims, taskTitles) {
    if (!this.tooltip) {
      this.tooltip = this.doc.createElement('div')
      this.tooltip.className = 'contentflow-ve-marker-tooltip'
      this.tooltip.style.cssText = 'position:fixed; z-index:1001; display:none; background:#1a1a1a; color:#fff; ' +
        'font-size:.75em; padding:.25em .5em; border-radius:3px; pointer-events:none; white-space:nowrap;'
      this.doc.body.append(this.tooltip)
    }

    this.doc.querySelectorAll(EDITABLE_SELECTOR).forEach((element) => {
      const taskUid = claims.get(memberKey(element.getAttribute('table'), element.getAttribute('uid')))
      const shouldMark = taskUid !== undefined && taskUid !== this.activeTaskUid

      element.classList.toggle('contentflow-ve-other-task', shouldMark)
      if (shouldMark) {
        element.style.outline = '2px solid hsl(' + hueForTaskUid(taskUid) + ', 70%, 45%)'
        element.style.outlineOffset = '1px'
        element.dataset.contentflowTaskTitle = taskTitles.get(taskUid) || ('Task #' + taskUid)
      } else {
        element.style.outline = ''
        element.style.outlineOffset = ''
        delete element.dataset.contentflowTaskTitle
      }

      // Listeners read the dataset live at hover time, so they only need
      // attaching once - a later reloadMarkers() just updates the dataset.
      if (element.dataset.contentflowListenerAttached === '1') {
        return
      }
      element.dataset.contentflowListenerAttached = '1'

      element.addEventListener('mouseenter', () => {
        if (!element.dataset.contentflowTaskTitle) {
          return
        }
        this.tooltip.textContent = 'Claimed by: ' + element.dataset.contentflowTaskTitle
        this.tooltip.style.display = 'block'
      })
      element.addEventListener('mousemove', (event) => {
        this.tooltip.style.left = event.clientX + 12 + 'px'
        this.tooltip.style.top = event.clientY + 12 + 'px'
      })
      element.addEventListener('mouseleave', () => {
        this.tooltip.style.display = 'none'
      })
    })
  }
}

/*
 * `#typo3-contentIframe` is a persistent element in the backend chrome that
 * TYPO3 reuses across module navigation, so one `load` listener attached
 * once catches every future navigation into (and out of) Visual Editor -
 * no need to re-discover the iframe per module switch.
 */
export function observeVisualEditorTaskSelect() {
  const iframe = document.querySelector('iframe#typo3-contentIframe')
  if (!iframe) {
    return
  }

  const tryMount = () => {
    const doc = iframe.contentDocument
    if (!isVisualEditorDocument(doc) || doc.querySelector('.contentflow-ve-task-select')) {
      return
    }
    const pageUid = pageUidFromIframe(iframe)
    if (!pageUid) {
      return
    }
    new VisualEditorTaskSelect(doc, pageUid).mount()
  }

  iframe.addEventListener('load', tryMount)
  tryMount()
}
