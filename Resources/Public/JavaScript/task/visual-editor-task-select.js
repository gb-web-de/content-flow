/*
 * B4: the Visual Editor's persistent task select, plus the markers that flag
 * content already claimed by a *different* task.
 *
 * Three documents are involved, and getting them mixed up is why the markers
 * did nothing at all until this was corrected:
 *
 *   1. the top backend chrome, where this module is loaded (from wizard.js),
 *   2. `#typo3-contentIframe`, holding EXT:visual_editor's own module - its
 *      toolbar of Lit components (ve-auto-save-toggle, ve-backend-save-button,
 *      ...), which is where the select is inserted,
 *   3. one `iframe.visual-editor-iframe` per language inside that, holding the
 *      rendered FRONTEND page - and only there do ve-content-element and
 *      ve-editable-text exist. The markers belong in this document.
 *
 * Reaching into (3) directly is safe rather than lucky: PageEdit.html renders
 * that iframe only under `<f:if condition="{language.sameOrigin}">` and shows a
 * plain notice instead when the site lives on another domain. If the iframe is
 * there, it is same-origin by construction, so no postMessage bridge is needed.
 *
 * EXT:visual_editor offers no server-side extension point for another package
 * (see Backend/index.js in the vendored package), and wizard.js is never loaded
 * inside its module either - LoadWizardModuleEventListener's
 * AfterBackendPageRenderEvent does not fire for that render.
 *
 * Picking a task here is deliberately a *before-the-fact* declaration ("this
 * page's edits go to this task"), not a reaction to a save - VE can autosave on
 * every few keystrokes (ve-auto-save-toggle.js's debounced doSave()), so a
 * modal popping up after each one would make editing unusable. See
 * TaskAjaxController::setActiveTaskForPageAction() and ActiveTaskSession for
 * the other half.
 */
import AjaxRequest from '@typo3/core/ajax/ajax-request.js'
import Notification from '@typo3/backend/notification.js'
import labels from '~labels/content_flow.messages'
import { claimsByIdentifier, foreignTaskUidFor, hueForTaskUid } from '@gb-web/content-flow/task/task-markers.js'

const CONTENT_ELEMENT_SELECTOR = 've-content-element'
const CONTENT_FRAME_SELECTOR = 'iframe.visual-editor-iframe'
const BUBBLE_CLASS = 'contentflow-task-bubble'
const MARKER_STYLE_ID = 'contentflow-ve-markers'
const TOOLBAR_STYLE_ID = 'contentflow-ve-toolbar'
const CREATE_VALUE = '__create__'
const NONE_VALUE = '__none__'

/*
 * Neither document here is reached by Styles.css: it is added to the outer
 * backend chrome by LoadWizardModuleEventListener, and EXT:visual_editor's
 * module renders in its own iframe document that no AfterBackendPageRenderEvent
 * fires for. So both stylesheets travel with this module.
 */
const TOOLBAR_STYLES = `
.contentflow-ve-task-select {
  display: inline-flex;
  align-items: center;
  margin-right: .5em;
}
.contentflow-ve-task-select select {
  width: auto;
}
.contentflow-ve-comment-popover {
  position: absolute;
  z-index: 1000;
  width: 280px;
  padding: .5em;
  border: 1px solid #ccc;
  border-radius: 4px;
  background: #fff;
  box-shadow: 0 2px 8px rgba(0, 0, 0, .2);
}
.contentflow-ve-comment-popover-label {
  margin-bottom: .25em;
  font-size: .85em;
}
.contentflow-ve-comment-popover button {
  margin-top: .35em;
}
`

/*
 * Injected into the frontend document rather than shipped in Styles.css:
 * that file is loaded into backend documents (LoadWizardModuleEventListener,
 * PageModuleEventListener) and never reaches this iframe. EXT:visual_editor
 * relaxes style-src to 'unsafe-inline' for edit mode (PolicyMutatedEventListener),
 * so a plain <style> element is allowed here.
 *
 * ve-content-element is `display: block; position: relative` in its own :host
 * styles, so an absolutely positioned light-DOM child anchors to the element
 * itself. The bubble is a real button so the task title is reachable by
 * keyboard, not only by hovering.
 */
const MARKER_STYLES = `
.${BUBBLE_CLASS} {
  position: absolute;
  top: 4px;
  left: 4px;
  z-index: 11;
  width: 14px;
  height: 14px;
  padding: 0;
  border: 2px solid #fff;
  border-radius: 50%;
  background: hsl(var(--contentflow-task-hue, 0), 70%, 45%);
  box-shadow: 0 1px 3px rgba(0, 0, 0, .4);
  cursor: help;
}
.${BUBBLE_CLASS}::after {
  content: attr(data-contentflow-label);
  position: absolute;
  top: calc(100% + 6px);
  left: 0;
  display: none;
  z-index: 12;
  padding: .25em .5em;
  border-radius: 3px;
  background: #1a1a1a;
  color: #fff;
  font-family: sans-serif;
  font-size: 12px;
  line-height: 1.4;
  white-space: nowrap;
  pointer-events: none;
}
.${BUBBLE_CLASS}:hover::after,
.${BUBBLE_CLASS}:focus-visible::after {
  display: block;
}
`

function injectStyles(doc, id, css) {
  if (doc.getElementById(id)) {
    return
  }
  const style = doc.createElement('style')
  style.id = id
  style.textContent = css
  doc.head.append(style)
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
    this.activeTaskUid = 0
    this.claims = new Map()
    this.taskTitles = new Map()
    this.select = null
    this.dismissCommentPopover = null
  }

  async mount() {
    const anchor = this.doc.querySelector('ve-auto-save-toggle')
    if (!anchor?.parentElement) {
      return
    }

    injectStyles(this.doc, TOOLBAR_STYLE_ID, TOOLBAR_STYLES)

    const wrapper = this.doc.createElement('span')
    wrapper.className = 'contentflow-ve-task-select'

    const select = this.doc.createElement('select')
    select.className = 'form-select form-select-sm'
    select.title = labels.get('ve.select.label')

    const placeholder = this.doc.createElement('option')
    placeholder.value = ''
    placeholder.textContent = labels.get('ve.select.placeholder')
    placeholder.disabled = true
    placeholder.selected = true
    select.append(placeholder)

    wrapper.append(select)
    anchor.parentElement.insertBefore(wrapper, anchor)
    this.select = select

    select.addEventListener('change', () => this.onChange())

    await this.reloadTasks()
    await this.reloadMarkers()
    this.observeContentFrames()
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

      // The way back out of a declaration. Without it the choice keeps routing
      // every save on this page and cannot be taken back.
      const noneOption = this.doc.createElement('option')
      noneOption.value = NONE_VALUE
      noneOption.dataset.task = '1'
      noneOption.textContent = labels.get('ve.select.none')
      this.select.append(noneOption)

      tasks.forEach((task) => {
        const option = this.doc.createElement('option')
        option.value = String(task.uid)
        option.dataset.task = '1'
        option.textContent = task.title + ' (' + task.stageLabel + ')'
        this.select.append(option)
      })

      const createOption = this.doc.createElement('option')
      createOption.value = CREATE_VALUE
      createOption.dataset.task = '1'
      createOption.textContent = labels.get('ve.select.create')
      this.select.append(createOption)

      // A choice made before a reload is still routing saves server-side, so
      // it has to be visible here - and the markers below need it to tell the
      // active task's own content apart from everyone else's.
      this.activeTaskUid = Number(result.activeTaskUid ?? 0)
      if (this.activeTaskUid > 0 && this.hasOption(String(this.activeTaskUid))) {
        this.select.value = String(this.activeTaskUid)
      } else if (current && this.hasOption(current)) {
        this.select.value = current
      }
    } catch (error) {
      // Silent - the select just stays on its placeholder.
    }
  }

  hasOption(value) {
    return [...this.select.options].some((option) => option.value === value)
  }

  async onChange() {
    const value = this.select.value
    if (value === CREATE_VALUE) {
      await this.createTask()
      return
    }

    // "No task" and a real task are the same request, differing only in the uid
    // - the server treats 0 as "drop the declaration" and moves nothing.
    const taskUid = value === NONE_VALUE ? 0 : parseInt(value, 10)
    if (Number.isNaN(taskUid)) {
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
        Notification.error(labels.get('ve.notification.title'), result.message || labels.get('ve.error.selectFailed'))
        return
      }

      this.activeTaskUid = taskUid
      const selectedOption = this.select.querySelector('option[value="' + taskUid + '"]')
      if (selectedOption) {
        selectedOption.textContent = (selectedOption.textContent || '').split(' (')[0] + ' (' + result.stageLabel + ')'
      }

      if (result.transitioned) {
        Notification.success(labels.get('ve.notification.title'), labels.get('ve.notification.taskActive'))
      }
      if (result.comment) {
        this.offerCommentEdit(taskUid, result.commentUid, result.comment)
      }

      await this.reloadMarkers()
    } catch (error) {
      Notification.error(labels.get('ve.notification.title'), labels.get('ve.error.server'))
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
        Notification.error(labels.get('ve.notification.title'), result.message || labels.get('ve.error.createFailed'))
        return
      }

      await this.reloadTasks()
      this.select.value = String(result.task)
      this.select.disabled = false
      await this.onChange()
    } catch (error) {
      Notification.error(labels.get('ve.notification.title'), labels.get('ve.error.server'))
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

    const label = this.doc.createElement('div')
    label.className = 'contentflow-ve-comment-popover-label'
    label.textContent = labels.get('ve.comment.prompt')

    const textarea = this.doc.createElement('textarea')
    textarea.className = 'form-control'
    textarea.rows = 2
    textarea.value = defaultComment

    const saveButton = this.doc.createElement('button')
    saveButton.type = 'button'
    saveButton.className = 'btn btn-sm btn-default'
    saveButton.textContent = labels.get('ve.comment.save')
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
          Notification.error(
            labels.get('ve.notification.title'),
            (result.errors && result.errors[0]) || labels.get('ve.error.commentFailed'),
          )
        }
      } catch (error) {
        Notification.error(labels.get('ve.notification.title'), labels.get('ve.error.server'))
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

      this.taskTitles = new Map((result.tasks || []).map((task) => [Number(task.uid), task.title]))
      this.claims = claimsByIdentifier(result.members || [])
    } catch (error) {
      // Silent - the markers are a warning, not a load-bearing mechanism.
      return
    }

    this.markAllFrames()
  }

  contentFrames() {
    return [...this.doc.querySelectorAll(CONTENT_FRAME_SELECTOR)]
  }

  /*
   * The frontend document loads later than the module document around it, and
   * EXT:visual_editor reloads it after every save (reload-all-child-frames.js),
   * which throws away everything marked into it. One `load` listener per frame
   * covers both.
   */
  observeContentFrames() {
    this.contentFrames().forEach((frame) => {
      if (frame.dataset.contentflowObserved !== '1') {
        frame.dataset.contentflowObserved = '1'
        frame.addEventListener('load', () => this.markFrame(frame))
      }
      this.markFrame(frame)
    })
  }

  markAllFrames() {
    this.contentFrames().forEach((frame) => this.markFrame(frame))
  }

  markFrame(frame) {
    const doc = frame.contentDocument
    if (!doc?.body) {
      return
    }

    injectStyles(doc, MARKER_STYLE_ID, MARKER_STYLES)
    this.markElements(doc)
    this.observeMutations(doc)
  }

  markElements(doc) {
    doc.querySelectorAll(CONTENT_ELEMENT_SELECTOR).forEach((element) => {
      const taskUid = foreignTaskUidFor(element, this.claims, this.activeTaskUid)
      const existing = element.querySelector(':scope > .' + BUBBLE_CLASS)

      if (taskUid === null) {
        existing?.remove()
        return
      }

      const title = this.taskTitles.get(taskUid) || '#' + taskUid
      const bubble = existing ?? this.createBubble(doc)
      bubble.style.setProperty('--contentflow-task-hue', String(hueForTaskUid(taskUid)))
      bubble.dataset.contentflowLabel = title
      bubble.setAttribute('aria-label', labels.get('ve.marker.claimedBy') + ' ' + title)
      if (!existing) {
        element.append(bubble)
      }
    })
  }

  createBubble(doc) {
    const bubble = doc.createElement('button')
    bubble.type = 'button'
    bubble.className = BUBBLE_CLASS
    // The bubble sits inside editable content: it must not be typed over, and a
    // click on it must not reach EXT:visual_editor's own element handlers.
    bubble.contentEditable = 'false'
    bubble.draggable = false
    bubble.addEventListener('click', (event) => {
      event.preventDefault()
      event.stopPropagation()
    })

    return bubble
  }

  /*
   * EXT:visual_editor re-renders content elements in place (a save, a move, a
   * language sync), which drops the bubbles again without reloading the frame.
   */
  observeMutations(doc) {
    if (doc.body.dataset.contentflowObserved === '1') {
      return
    }
    doc.body.dataset.contentflowObserved = '1'

    const observer = new doc.defaultView.MutationObserver((mutations) => {
      // Ignore the bubbles' own insertion and removal, or marking would trigger
      // marking.
      const relevant = mutations.some((mutation) => [...mutation.addedNodes, ...mutation.removedNodes].some(
        (node) => node.nodeType === 1 && !node.classList?.contains(BUBBLE_CLASS),
      ))
      if (relevant) {
        this.markElements(doc)
      }
    })
    observer.observe(doc.body, { childList: true, subtree: true })
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
