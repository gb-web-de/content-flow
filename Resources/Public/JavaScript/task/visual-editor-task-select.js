/*
 * B4: the Visual Editor's persistent task select - the toolbar control and the
 * server conversation behind it. The markers it drives live in
 * task/visual-editor-markers.js; the identity rules they rest on live in
 * task/task-markers.js.
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
import labels from '~labels/editorial_flow.messages'
import { claimsByIdentifier } from '@gb-web/editorial-flow/task/task-markers.js'
import { TaskMarkers } from '@gb-web/editorial-flow/task/visual-editor-markers.js'

const TOOLBAR_STYLE_ID = 'editorialflow-ve-toolbar'
const CREATE_VALUE = '__create__'
const NONE_VALUE = '__none__'

/*
 * This document is not reached by Styles.css: it is added to the outer backend
 * chrome by LoadWizardModuleEventListener, and EXT:visual_editor's module
 * renders in its own iframe document that no AfterBackendPageRenderEvent fires
 * for. So the toolbar's stylesheet travels with this module. (The frontend
 * document's own stylesheet lives in visual-editor-markers.js, for the same
 * reason one level further down.)
 */
const TOOLBAR_STYLES = `
/*
 * Pushed to the right-hand end of EXT:visual_editor's toolbar, into the space
 * its own controls leave empty - see insertToolbar() for why it is not in the
 * button group any more.
 */
.editorialflow-ve-toolbar-slot {
  display: inline-flex;
  align-items: center;
  gap: .5em;
  margin-left: auto;
}
.editorialflow-ve-task-select {
  display: inline-flex;
  align-items: center;
}
.editorialflow-ve-task-select select {
  width: auto;
}
.editorialflow-ve-legend {
  display: inline-flex;
  align-items: center;
  gap: 3px;
}
.editorialflow-ve-legend:empty {
  display: none;
}
/*
 * Dot plus title, not a bare dot: a colour says nothing until it has been
 * hovered, and the toolbar has the room to just say which tasks these are.
 * The title is clipped rather than allowed to push the toolbar apart - the
 * full one, with stage and assignee, stays in the tooltip.
 */
.editorialflow-ve-legend-swatch {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  max-width: 12em;
  padding: 1px 6px;
  border: 1px solid transparent;
  border-radius: 10px;
  background: transparent;
  color: inherit;
  font-size: 11px;
  line-height: 1.7;
  cursor: pointer;
}
.editorialflow-ve-legend-swatch:hover,
.editorialflow-ve-legend-swatch:focus-visible {
  border-color: hsl(var(--editorialflow-task-hue, 0), 70%, 45%);
}
.editorialflow-ve-legend-dot {
  flex: none;
  width: 12px;
  height: 12px;
  border: 2px solid hsl(var(--editorialflow-task-hue, 0), 70%, 45%);
  border-radius: 50%;
  background: hsl(var(--editorialflow-task-hue, 0), 70%, 45%);
}
/* The editor's own task reads as a ring, the same way its bubbles do. */
.editorialflow-ve-legend-swatch.editorialflow-task-claimed-active .editorialflow-ve-legend-dot {
  background: transparent;
}
.editorialflow-ve-legend-title {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.editorialflow-ve-comment-popover {
  position: absolute;
  z-index: 1000;
  width: 280px;
  padding: .5em;
  border: 1px solid #ccc;
  border-radius: 4px;
  background: #fff;
  box-shadow: 0 2px 8px rgba(0, 0, 0, .2);
}
.editorialflow-ve-comment-popover-label {
  margin-bottom: .25em;
  font-size: .85em;
}
.editorialflow-ve-comment-popover button {
  margin-top: .35em;
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
    this.select = null
    // The markers' own membership actions refresh through here rather than
    // reloading: this runs in the backend chrome, and a reload would drop the
    // whole editing session for something one request can bring up to date.
    this.markers = new TaskMarkers(doc, () => this.reloadMarkers())
    this.dismissCommentPopover = null
    this.remounting = false
  }

  async mount() {
    if (!this.insertToolbar()) {
      return
    }
    this.watchToolbar()

    window.top.document.addEventListener('editorialflow:active-task-changed', async () => {
      await this.reloadTasks()
      await this.reloadMarkers()
    })

    await this.reloadTasks()
    await this.reloadMarkers()
  }

  /**
   * Build the select and the legend and put them in the toolbar.
   *
   * @returns {boolean} whether there was a toolbar to put them in
   */
  insertToolbar() {
    const anchor = this.doc.querySelector('ve-auto-save-toggle')
    if (!anchor?.parentElement) {
      return false
    }

    injectStyles(this.doc, TOOLBAR_STYLE_ID, TOOLBAR_STYLES)

    /*
     * A slot of our own at the end of the toolbar, pushed right by
     * `margin-left: auto`, rather than squeezed in front of
     * ve-auto-save-toggle.
     *
     * Two reasons. That anchor sits inside a Bootstrap `.btn-group`, whose
     * members are glued together with `margin-left: -1px` - a select and a
     * legend are not buttons and had no business in that seam. And they took
     * 433 of the group's 588 pixels, pushing EXT:visual_editor's own controls
     * along and leaving the toolbar's right half empty. This is where an
     * editor is told which task they are working on, so it belongs in that
     * empty half.
     */
    // A re-render leaves the previous slot behind detached from our select;
    // clearing first keeps a remount from stacking two of them.
    this.doc.querySelectorAll('.editorialflow-ve-toolbar-slot').forEach((stale) => stale.remove())

    const toolbar = anchor.closest('.btn-toolbar')
    const slot = this.doc.createElement('div')
    slot.className = 'editorialflow-ve-toolbar-slot'
    if (toolbar) {
      toolbar.append(slot)
    } else {
      // No toolbar to hang off - keep the old anchor rather than dropping the
      // select entirely.
      anchor.parentElement.insertBefore(slot, anchor)
    }

    const wrapper = this.doc.createElement('span')
    wrapper.className = 'editorialflow-ve-task-select'

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
    slot.append(wrapper)
    this.select = select

    const legend = this.doc.createElement('span')
    legend.className = 'editorialflow-ve-legend'
    slot.append(legend)
    this.markers.mountLegend(legend)

    select.addEventListener('change', () => this.onChange())

    return true
  }

  /*
   * EXT:visual_editor throws the whole doc header away and puts a freshly
   * fetched one in its place - `updateModuleState()` in Backend/page-changed.js
   * replaces every `.module-docheader, .js-replaceable` element with markup it
   * just re-requested from the server. That runs on the `pageChanged` message
   * the frontend iframe sends once it has loaded, which is *after* this module
   * mounted, so the select an editor saw appear vanished a second later. It
   * runs again on every language switch and every navigation inside the editor.
   *
   * Nothing announces it, so the disappearance is the signal: when the select
   * is no longer in the document, build it again. Re-inserting rather than
   * re-creating the whole controller keeps the marker observers - which live on
   * the frontend documents, untouched by all this - from being stacked up a
   * second time per replacement.
   */
  watchToolbar() {
    const view = this.doc.defaultView
    const observer = new view.MutationObserver(() => {
      if (this.select?.isConnected || this.remounting) {
        return
      }
      this.remounting = true
      view.requestAnimationFrame(async () => {
        this.remounting = false
        if (this.select?.isConnected || !this.insertToolbar()) {
          return
        }
        await this.reloadTasks()
        await this.reloadMarkers()
      })
    })
    observer.observe(this.doc.body, { childList: true, subtree: true })
  }

  async reloadTasks() {
    if (!TYPO3.settings?.ajaxUrls?.editorialflow_task_list_open_for_page) {
      return
    }
    try {
      const response = await new AjaxRequest(TYPO3.settings.ajaxUrls.editorialflow_task_list_open_for_page)
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
      const response = await new AjaxRequest(TYPO3.settings.ajaxUrls.editorialflow_task_set_active_for_page).post({
        pageUid: this.pageUid,
        taskUid,
      })
      const result = await response.resolve()
      if (result.success !== true) {
        Notification.error(labels.get('ve.notification.title'), result.message || labels.get('ve.error.selectFailed'))
        return
      }

      this.activeTaskUid = taskUid
      const eventDocument = window.top.document
      eventDocument.dispatchEvent(new eventDocument.defaultView.CustomEvent('editorialflow:active-task-changed', {
        detail: { activeTask: result.activeTask || null },
      }))
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
    if (!TYPO3.settings?.ajaxUrls?.editorialflow_task_create) {
      return
    }
    this.select.disabled = true
    try {
      const response = await new AjaxRequest(TYPO3.settings.ajaxUrls.editorialflow_task_create).post({
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
    } catch (error) {
      Notification.error(labels.get('ve.notification.title'), labels.get('ve.error.server'))
      return
    } finally {
      this.select.disabled = false
    }

    // Outside the try/finally: onChange() manages `disabled` itself, and
    // running it while the finally above still owed an unlock left the select
    // dead until the next render.
    await this.onChange()
  }

  offerCommentEdit(taskUid, commentUid, defaultComment) {
    this.closeCommentPopover()

    const popover = this.doc.createElement('div')
    popover.className = 'editorialflow-ve-comment-popover'

    const label = this.doc.createElement('div')
    label.className = 'editorialflow-ve-comment-popover-label'
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
        // Generic core wizard_submit route (mode=editorialflow_task_wizard),
        // not a editorial_flow-specific one - see Classes/Wizard/
        // TaskWizardProvider.php's regression_comment mode, the same one the
        // post-save wizard's comment step submits to.
        const response = await new AjaxRequest(TYPO3.settings.ajaxUrls.wizard_submit)
          .withQueryArguments({ mode: 'editorialflow_task_wizard' })
          .post({
            mode: 'regression_comment',
            taskUid,
            commentUid,
            content: textarea.value,
          })
        const result = await response.resolve()
        if (result.success === true) {
          this.closeCommentPopover()
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
        this.closeCommentPopover()
      }
    }
    this.dismissCommentPopover = dismiss
    view.setTimeout(() => this.doc.addEventListener('click', dismiss, true), 0)
  }

  /*
   * One way out for all three closers (a new popover, a saved note, a click
   * elsewhere). The listener used to be removed while `dismissCommentPopover`
   * kept pointing at the dead closure, so opening a second popover tried to
   * detach a handler that was no longer attached and left the real one behind.
   */
  closeCommentPopover() {
    this.doc.querySelector('.editorialflow-ve-comment-popover')?.remove()
    if (this.dismissCommentPopover) {
      this.doc.removeEventListener('click', this.dismissCommentPopover, true)
      this.dismissCommentPopover = null
    }
  }

  async reloadMarkers() {
    if (!TYPO3.settings?.ajaxUrls?.editorialflow_task_list_member_markers) {
      return
    }
    try {
      const response = await new AjaxRequest(TYPO3.settings.ajaxUrls.editorialflow_task_list_member_markers)
        .withQueryArguments({ pageUid: this.pageUid })
        .get()
      const result = await response.resolve()
      if (result.success !== true) {
        return
      }

      this.markers.update(claimsByIdentifier(result.members || []), result.tasks || [], this.activeTaskUid)
    } catch (error) {
      // Silent - the markers are a warning, not a load-bearing mechanism.
    }
  }
}

const IFRAME_SELECTOR = 'iframe#typo3-contentIframe'

function tryMount(iframe) {
  const doc = iframe.contentDocument
  if (!isVisualEditorDocument(doc) || doc.querySelector('.editorialflow-ve-task-select')) {
    return
  }
  const pageUid = pageUidFromIframe(iframe)
  if (!pageUid) {
    return
  }
  new VisualEditorTaskSelect(doc, pageUid).mount()
}

/*
 * `#typo3-contentIframe` is persistent once it exists - TYPO3 reuses it across
 * module navigation - so one `load` listener catches every future navigation
 * into (and out of) Visual Editor, and the iframe never has to be rediscovered
 * per module switch.
 *
 * It does not, however, exist when this runs. The backend chrome creates the
 * content iframe on demand, after its own DOMContentLoaded, which is exactly
 * when wizard.js calls this. Looking once and giving up meant the select was
 * never inserted at all on a direct load of the Visual Editor module - the case
 * an editor actually hits, since a bookmark or a page-tree click both land
 * there that way. The mount only ever appeared when something else caused a
 * second pass. Hence: wait for the element rather than assume it.
 */
function attach(iframe) {
  if (iframe.dataset.editorialflowVeObserved === '1') {
    return
  }
  iframe.dataset.editorialflowVeObserved = '1'
  iframe.addEventListener('load', () => tryMount(iframe))
  // The iframe may already have finished loading by the time we get here, in
  // which case no further `load` event is coming.
  tryMount(iframe)
}

export function observeVisualEditorTaskSelect() {
  const existing = document.querySelector(IFRAME_SELECTOR)
  if (existing) {
    attach(existing)
    return
  }

  const observer = new MutationObserver(() => {
    const iframe = document.querySelector(IFRAME_SELECTOR)
    if (iframe) {
      // One iframe, reused from here on, so the observer has done its job -
      // and a childList observer over the whole backend chrome is not something
      // to leave running for every modal and notification that opens.
      observer.disconnect()
      attach(iframe)
    }
  })
  observer.observe(document.body, { childList: true, subtree: true })
}
