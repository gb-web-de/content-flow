/*
 * The DOM half of B4's task markers: what a content element looks like when a
 * task has claimed it, and the toolbar legend that says what the colours mean.
 *
 * Split out of visual-editor-task-select.js, which owns the toolbar select and
 * the server conversation. That file was one module doing three jobs; this one
 * only ever touches documents, and takes the data it renders as an argument.
 *
 * Two documents are involved here (see visual-editor-task-select.js's docblock
 * for the full three-document picture):
 *
 *   - the EXT:visual_editor module document, where the legend goes, and
 *   - one `iframe.visual-editor-iframe` per language inside it, holding the
 *     rendered FRONTEND page. Only there do `ve-content-element` elements
 *     exist, so that is where markers belong.
 *
 * The code itself runs in the top backend chrome, which is what lets a bubble
 * open a ticket through TYPO3.Modal directly: the modal renders into that same
 * chrome document, where Styles.css is already loaded
 * (LoadWizardModuleEventListener), so no iframe bridge and no second stylesheet
 * are needed for it.
 */
import Modal from '@typo3/backend/modal.js'
import { SeverityEnum } from '@typo3/backend/enum/severity.js'
import labels from '~labels/content_flow.messages'
import { claimFor, hueForTaskUid, legendEntries } from '@gb-web/content-flow/task/task-markers.js'
import { openMoveDialog, openSplitDialog, moveToTask } from '@gb-web/content-flow/task/membership.js'

const CONTENT_ELEMENT_SELECTOR = 've-content-element'
const CONTENT_FRAME_SELECTOR = 'iframe.visual-editor-iframe'

const BUBBLE_CLASS = 'contentflow-task-bubble'
const ACTIONS_CLASS = 'contentflow-task-actions'
const MENU_TOGGLE_CLASS = 'contentflow-task-menu-toggle'
const MENU_CLASS = 'contentflow-task-menu'
const CLAIMED_CLASS = 'contentflow-task-claimed'
const ACTIVE_CLASS = 'contentflow-task-claimed-active'
const HIGHLIGHT_CLASS = 'contentflow-task-highlight'
const LEGEND_CLASS = 'contentflow-ve-legend'

const MARKER_STYLE_ID = 'contentflow-ve-markers'
const HUE_PROPERTY = '--contentflow-task-hue'

/*
 * Injected into the frontend document rather than shipped in Styles.css: that
 * file is loaded into backend documents (LoadWizardModuleEventListener,
 * PageModuleEventListener) and never reaches this iframe. EXT:visual_editor
 * relaxes style-src to 'unsafe-inline' for edit mode
 * (PolicyMutatedEventListener), so a plain <style> element is allowed here.
 *
 * `ve-content-element` is `display: block; position: relative` in its own :host
 * styles, so an absolutely positioned light-DOM child anchors to the element
 * itself, and an outline drawn on it traces the element's real box.
 *
 * The outline is what makes ownership readable at a glance - a 14px dot is
 * something you have to go looking for. It is drawn inset and semi-transparent
 * on purpose: this sits on top of a rendered frontend design that has its own
 * borders and spacing, and a marker that repaints the page is worse than one
 * that is easy to miss. `outline` rather than `border` so nothing reflows.
 */
const MARKER_STYLES = `
.${CLAIMED_CLASS} {
  outline: 2px solid hsl(var(${HUE_PROPERTY}, 0), 70%, 45%, .55);
  outline-offset: -2px;
}
.${CLAIMED_CLASS}.${ACTIVE_CLASS} {
  outline-style: dashed;
  outline-color: hsl(var(${HUE_PROPERTY}, 0), 70%, 45%, .35);
}
.${CLAIMED_CLASS}.${HIGHLIGHT_CLASS} {
  outline-width: 3px;
  outline-color: hsl(var(${HUE_PROPERTY}, 0), 70%, 45%);
  background-color: hsl(var(${HUE_PROPERTY}, 0), 70%, 45%, .08);
}
/*
 * Right-hand corner, not the left one: a rendered page carries its own content
 * at the start of a line - headings, first words, images - and a dot placed
 * over that reads as part of the design. The right edge is where the eye finds
 * a status mark and where nothing of the page usually is.
 */
.${ACTIONS_CLASS} {
  position: absolute;
  top: 4px;
  right: 4px;
  z-index: 11;
  display: flex;
  align-items: center;
  gap: 3px;
}
.${BUBBLE_CLASS} {
  position: relative;
  width: 14px;
  height: 14px;
  padding: 0;
  border: 2px solid #fff;
  border-radius: 50%;
  background: hsl(var(${HUE_PROPERTY}, 0), 70%, 45%);
  box-shadow: 0 1px 3px rgba(0, 0, 0, .4);
  cursor: pointer;
}
/*
 * The second affordance, deliberately separate from the dot: clicking the dot
 * has meant "open this task" since the markers shipped, and quietly turning
 * that into a menu would break a gesture editors already know. Not drag-only,
 * not hover-only - a real button, so the menu is reachable by keyboard.
 */
.${MENU_TOGGLE_CLASS} {
  position: relative;
  width: 14px;
  height: 14px;
  padding: 0;
  border: 2px solid #fff;
  border-radius: 3px;
  background: #1a1a1a;
  color: #fff;
  box-shadow: 0 1px 3px rgba(0, 0, 0, .4);
  font-family: sans-serif;
  font-size: 9px;
  line-height: 1;
  cursor: pointer;
}
.${MENU_CLASS} {
  position: absolute;
  top: calc(100% + 6px);
  right: 0;
  z-index: 12;
  display: flex;
  flex-direction: column;
  min-width: 190px;
  padding: 4px;
  border-radius: 3px;
  background: #1a1a1a;
  box-shadow: 0 2px 6px rgba(0, 0, 0, .45);
}
.${MENU_CLASS}[hidden] {
  display: none;
}
.${MENU_CLASS} button {
  padding: .35em .5em;
  border: 0;
  border-radius: 2px;
  background: transparent;
  color: #fff;
  font-family: sans-serif;
  font-size: 12px;
  line-height: 1.4;
  text-align: left;
  cursor: pointer;
}
.${MENU_CLASS} button:hover,
.${MENU_CLASS} button:focus-visible {
  background: hsl(var(${HUE_PROPERTY}, 0), 70%, 45%);
}
/*
 * The editor's own task reads as a ring rather than a filled dot: same colour,
 * same place, visibly not the warning.
 */
.${BUBBLE_CLASS}.${ACTIVE_CLASS} {
  background: #fff;
  border-color: hsl(var(${HUE_PROPERTY}, 0), 70%, 45%);
}
.${BUBBLE_CLASS}::after {
  content: attr(data-contentflow-label);
  position: absolute;
  top: calc(100% + 6px);
  /* Anchored right so the label opens inwards - anchored left it would run off
     the page edge, the bubble now sitting in the right-hand corner. */
  right: 0;
  display: none;
  z-index: 12;
  padding: .25em .5em;
  border-radius: 3px;
  background: #1a1a1a;
  color: #fff;
  font-family: sans-serif;
  font-size: 12px;
  font-weight: normal;
  line-height: 1.4;
  text-align: left;
  /* A real newline in the attribute value separates title from detail. */
  white-space: pre-line;
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

/**
 * "Title\nStage · Assignee" - the second line is dropped entirely when the
 * server had nothing to say for it, rather than rendering a lonely separator.
 */
function tooltipFor(task) {
  const title = task?.title || ''
  const detail = [task?.stageLabel, task?.assigneeName || labels.get('ve.marker.unassigned')]
    .filter((part) => part)
    .join(' · ')

  return detail ? title + '\n' + detail : title
}

export class TaskMarkers {
  /**
   * @param moduleDoc the EXT:visual_editor module document (holds the frames
   *                  and the toolbar)
   */
  constructor(moduleDoc, onMembershipChange = null) {
    this.doc = moduleDoc
    // Called after a split or a move. Not window.location.reload(): this module
    // runs in the backend chrome, so reloading would throw away the whole
    // editing session for a marker the driver can simply re-fetch.
    this.onMembershipChange = onMembershipChange ?? (() => {})
    this.claims = new Map()
    this.tasks = new Map()
    this.activeTaskUid = 0
    this.legend = null
    this.highlightedTaskUid = 0
    // One pending pass per document, so a burst of mutations while typing
    // costs one re-scan instead of one per inserted node.
    this.pendingPasses = new WeakMap()
  }

  /**
   * New data from the server: which task claims what, how each task is named,
   * and which one the editor declared active.
   *
   * @param claims {Map<string, number>} identifier -> task uid
   * @param tasks {Array<{uid: number, title: string, stageLabel?: string, assigneeName?: string}>}
   * @param activeTaskUid {number}
   */
  update(claims, tasks, activeTaskUid) {
    this.claims = claims
    this.tasks = new Map((tasks ?? []).map((task) => [Number(task.uid), task]))
    this.activeTaskUid = Number(activeTaskUid) || 0

    this.renderLegend(tasks ?? [])
    // Marks every frame on the way past, so there is no second pass here.
    this.observeContentFrames()
  }

  contentFrames() {
    return [...this.doc.querySelectorAll(CONTENT_FRAME_SELECTOR)]
  }

  /*
   * The frontend document loads later than the module document around it, and
   * EXT:visual_editor reloads it after every save (reload-all-child-frames.js),
   * which throws away everything marked into it. One `load` listener per frame
   * covers both.
   *
   * Re-run on every update() rather than once at mount: EXT:visual_editor
   * replaces the `.js-replaceable` language columns wholesale when the set of
   * languages changes, and a frame that appeared that way has no listener yet.
   * The dataset flag keeps the re-run idempotent.
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

  markFrame(frame) {
    const doc = frame.contentDocument
    if (!doc?.body) {
      return
    }

    injectStyles(doc, MARKER_STYLE_ID, MARKER_STYLES)
    this.markElements(doc)
    this.observeMutations(doc)
    this.dismissMenusOnOutsideClick(doc)
  }

  /**
   * A click anywhere else in the page closes an open menu - the behaviour every
   * other menu in the backend has, and without it an editor has to find the
   * chevron again to get rid of it. Registered once per document.
   */
  dismissMenusOnOutsideClick(doc) {
    if (doc.body.dataset.contentflowMenuDismiss === '1') {
      return
    }
    doc.body.dataset.contentflowMenuDismiss = '1'
    doc.addEventListener('click', (event) => {
      if (event.target?.closest?.('.' + ACTIONS_CLASS) === null) {
        this.closeAllMenus()
      }
    })
  }

  markElements(doc) {
    doc.querySelectorAll(CONTENT_ELEMENT_SELECTOR).forEach((element) => {
      const claim = claimFor(element, this.claims, this.activeTaskUid)
      const existing = element.querySelector(':scope > .' + ACTIONS_CLASS)

      if (claim === null) {
        existing?.remove()
        element.classList.remove(CLAIMED_CLASS, ACTIVE_CLASS, HIGHLIGHT_CLASS)
        element.style.removeProperty(HUE_PROPERTY)
        return
      }

      const task = this.tasks.get(claim.taskUid)
      const title = task?.title || '#' + claim.taskUid
      const actions = existing ?? this.createActions(doc)
      const bubble = actions.querySelector('.' + BUBBLE_CLASS)

      element.style.setProperty(HUE_PROPERTY, String(hueForTaskUid(claim.taskUid)))
      element.classList.add(CLAIMED_CLASS)
      element.classList.toggle(ACTIVE_CLASS, claim.isActive)
      element.classList.toggle(HIGHLIGHT_CLASS, this.highlightedTaskUid === claim.taskUid)

      bubble.classList.toggle(ACTIVE_CLASS, claim.isActive)
      bubble.dataset.contentflowLabel = tooltipFor({ ...task, title })
      bubble.dataset.contentflowTask = String(claim.taskUid)
      bubble.setAttribute(
        'aria-label',
        (claim.isActive ? labels.get('ve.marker.yourTask') : labels.get('ve.marker.claimedBy'))
        + ' ' + title + ' - ' + labels.get('ve.marker.openTicket'),
      )

      // Stamped on the group rather than closed over, so a re-render that keeps
      // the existing nodes still acts on current data.
      actions.dataset.contentflowTask = String(claim.taskUid)
      actions.dataset.contentflowTable = claim.table
      actions.dataset.contentflowUid = String(claim.uid)
      actions.dataset.contentflowRecordTitle = claim.title || claim.table + ':' + claim.uid
      actions.dataset.contentflowActive = claim.isActive ? '1' : '0'

      if (!existing) {
        element.append(actions)
      }
    })
  }

  /**
   * The dot and its menu button, in one wrapper so both sit in the element's
   * corner and the menu can be positioned against them.
   */
  createActions(doc) {
    const actions = doc.createElement('span')
    actions.className = ACTIONS_CLASS
    // Everything we add sits inside editable content: it must not be typed
    // over, and the mutation observer has to be able to tell our own nodes
    // apart from a re-render, or marking would trigger marking.
    actions.contentEditable = 'false'
    actions.draggable = false
    actions.dataset.contentflowMarker = '1'

    actions.append(this.createBubble(doc), this.createMenuToggle(doc))

    return actions
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
      this.openTicket(Number(bubble.dataset.contentflowTask))
    })

    return bubble
  }

  /**
   * Opening the menu builds it from the group's current dataset, so a menu is
   * never a stale copy of what the element was claimed by a re-render ago.
   */
  createMenuToggle(doc) {
    const toggle = doc.createElement('button')
    toggle.type = 'button'
    toggle.className = MENU_TOGGLE_CLASS
    toggle.contentEditable = 'false'
    toggle.draggable = false
    toggle.textContent = '\u25BE'
    toggle.setAttribute('aria-haspopup', 'true')
    toggle.setAttribute('aria-expanded', 'false')
    toggle.setAttribute('aria-label', labels.get('membership.menu.label'))
    toggle.addEventListener('click', (event) => {
      event.preventDefault()
      event.stopPropagation()
      this.toggleMenu(doc, toggle)
    })

    return toggle
  }

  toggleMenu(doc, toggle) {
    const actions = toggle.parentElement
    const open = actions.querySelector('.' + MENU_CLASS)
    if (open !== null) {
      this.closeMenu(actions)
      return
    }

    this.closeAllMenus()
    actions.append(this.createMenu(doc, actions))
    toggle.setAttribute('aria-expanded', 'true')
    actions.querySelector('.' + MENU_CLASS + ' button')?.focus()
  }

  closeMenu(actions) {
    actions.querySelector('.' + MENU_CLASS)?.remove()
    const toggle = actions.querySelector('.' + MENU_TOGGLE_CLASS)
    toggle?.setAttribute('aria-expanded', 'false')
    // Focus is never lost: it goes back to the control that opened the menu.
    toggle?.focus()
  }

  closeAllMenus() {
    this.contentFrames().forEach((frame) => {
      frame.contentDocument?.querySelectorAll('.' + ACTIONS_CLASS).forEach((actions) => {
        if (actions.querySelector('.' + MENU_CLASS) !== null) {
          this.closeMenu(actions)
        }
      })
    })
  }

  /**
   * Three membership actions, all of them lossless: what an editor has typed
   * lives on the record's workspace version, and none of these touches it.
   */
  createMenu(doc, actions) {
    const menu = doc.createElement('div')
    menu.className = MENU_CLASS
    menu.setAttribute('role', 'menu')
    menu.contentEditable = 'false'
    menu.dataset.contentflowMarker = '1'

    const table = actions.dataset.contentflowTable
    const uid = Number(actions.dataset.contentflowUid)
    const title = actions.dataset.contentflowRecordTitle || ''
    const claimedBy = Number(actions.dataset.contentflowTask)
    const onDone = () => this.onMembershipChange()

    const entries = []
    // Only when there is somewhere to move it TO: the editor has declared a
    // task, and it is not the one already holding this element.
    if (this.activeTaskUid > 0 && this.activeTaskUid !== claimedBy) {
      entries.push([labels.get('membership.move.toActive'), () => moveToTask(table, uid, this.activeTaskUid, {
        recordTitle: title,
        taskTitle: this.tasks.get(this.activeTaskUid)?.title || '#' + this.activeTaskUid,
        onDone,
      })])
    }
    entries.push([labels.get('membership.split.button'), () => openSplitDialog(table, uid, title, { onDone })])
    entries.push([labels.get('membership.move.button'), () => openMoveDialog(table, uid, title, { onDone })])

    entries.forEach(([text, action]) => {
      const item = doc.createElement('button')
      item.type = 'button'
      item.setAttribute('role', 'menuitem')
      item.contentEditable = 'false'
      item.textContent = text
      item.addEventListener('click', (event) => {
        event.preventDefault()
        event.stopPropagation()
        this.closeMenu(actions)
        action()
      })
      menu.append(item)
    })

    menu.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') {
        event.preventDefault()
        event.stopPropagation()
        this.closeMenu(actions)
      }
    })

    return menu
  }

  /*
   * EXT:visual_editor re-renders content elements in place (a save, a move, a
   * language sync), which drops the markers again without reloading the frame.
   *
   * The pass is deferred to the next frame: typing inside an editable element
   * inserts nodes continuously, and re-scanning every `ve-content-element` on
   * the page per insertion is work nobody sees.
   */
  observeMutations(doc) {
    if (doc.body.dataset.contentflowObserved === '1') {
      return
    }
    doc.body.dataset.contentflowObserved = '1'

    const view = doc.defaultView
    const observer = new view.MutationObserver((mutations) => {
      // Ignore the bubbles' own insertion and removal, or marking would trigger
      // marking.
      const relevant = mutations.some((mutation) => [...mutation.addedNodes, ...mutation.removedNodes].some(
        (node) => node.nodeType === 1 && node.dataset?.contentflowMarker !== '1',
      ))
      if (!relevant || this.pendingPasses.get(doc)) {
        return
      }

      this.pendingPasses.set(doc, true)
      view.requestAnimationFrame(() => {
        this.pendingPasses.delete(doc)
        this.markElements(doc)
      })
    })
    observer.observe(doc.body, { childList: true, subtree: true })
  }

  /**
   * The colour key, in the toolbar next to the task select: without it the
   * hues are just decoration - you cannot tell what a colour means until you
   * have hovered something that happens to carry it.
   */
  mountLegend(container) {
    this.legend = container
  }

  renderLegend(tasks) {
    if (!this.legend) {
      return
    }

    this.legend.replaceChildren()
    const entries = legendEntries(tasks, this.activeTaskUid)
    if (entries.length === 0) {
      return
    }

    this.legend.title = labels.get('ve.legend.label')
    entries.forEach((entry) => {
      const swatch = this.doc.createElement('button')
      swatch.type = 'button'
      swatch.className = 'contentflow-ve-legend-swatch'
      swatch.classList.toggle(ACTIVE_CLASS, entry.isActive)
      swatch.style.setProperty(HUE_PROPERTY, String(entry.hue))
      swatch.title = tooltipFor(entry).replace('\n', ' - ')
      swatch.setAttribute('aria-label', entry.title + ' - ' + labels.get('ve.marker.openTicket'))

      // The colour alone said nothing until it was hovered. The title next to
      // it turns the row of dots into a readable list of what is going on on
      // this page - and which of it is yours, the ring being the same "this one
      // is mine" mark the bubbles use. Long titles are cut by CSS, with the
      // full text plus stage and assignee still in the tooltip above.
      const dot = this.doc.createElement('span')
      dot.className = 'contentflow-ve-legend-dot'

      const title = this.doc.createElement('span')
      title.className = 'contentflow-ve-legend-title'
      title.textContent = entry.title

      swatch.append(dot, title)
      swatch.addEventListener('click', () => this.openTicket(entry.taskUid))
      // Hovering a swatch answers "which parts of this page are that task?" -
      // the question the legend exists to raise in the first place.
      swatch.addEventListener('mouseenter', () => this.highlight(entry.taskUid))
      swatch.addEventListener('mouseleave', () => this.highlight(0))
      swatch.addEventListener('focus', () => this.highlight(entry.taskUid))
      swatch.addEventListener('blur', () => this.highlight(0))
      this.legend.append(swatch)
    })
  }

  highlight(taskUid) {
    if (this.highlightedTaskUid === taskUid) {
      return
    }
    this.highlightedTaskUid = taskUid
    this.contentFrames().forEach((frame) => {
      const doc = frame.contentDocument
      if (doc?.body) {
        this.markElements(doc)
      }
    })
  }

  /*
   * Runs in the top backend chrome, so this is the same call board.js's
   * openTicket() makes and the modal lands in the same document - the one
   * Styles.css is loaded into.
   */
  openTicket(taskUid) {
    const url = TYPO3.settings?.ajaxUrls?.contentflow_task_ticket
    if (!url || !taskUid) {
      return
    }

    Modal.advanced({
      type: Modal.types.ajax,
      title: this.tasks.get(taskUid)?.title || '#' + taskUid,
      content: url + '&task=' + encodeURIComponent(taskUid),
      size: Modal.sizes.large,
      severity: SeverityEnum.notice,
    })
  }
}
