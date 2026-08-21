/*
 * The "+" flow: five entry points grouped by what an editor means:
 * page (`pages`), content element (`tt_content`) and other records; each ending
 * up collecting task details through the same native wizard shell the
 * post-save routing wizard uses (see wizard/task-wizard.js,
 * Classes/Wizard/TaskWizardProvider.php) - a synthetic pending payload rather
 * than one read back from the session, since here the record was just picked
 * interactively and there is nothing to poll for.
 */
import Modal from '@typo3/backend/modal.js'
import Notification from '@typo3/backend/notification.js'
import { SeverityEnum } from '@typo3/backend/enum/severity.js'
import { html } from 'lit'
import AjaxRequest from '@typo3/core/ajax/ajax-request.js'
import { Categories } from '@typo3/backend/new-record-wizard.js'

import labels from '~labels/editorial_flow.messages'

import { WIZARD_MODAL_SIZE } from '@gb-web/editorial-flow/wizard/task-wizard.js'
import { taskContextTitle } from '@gb-web/editorial-flow/task/task-context-title.js'

const ELEMENT_BROWSER_FIELD_REFERENCE_PREFIX = 'editorialflow-create-target'
const PAGE_TABLE = 'pages'
const CONTENT_ELEMENT_TABLE = 'tt_content'

// The extension's name, not a sentence - it stays out of the label file.
const NOTIFICATION_TITLE = 'Editorial Flow'

function getAllowedCreateTables() {
  const configuredTables = TYPO3.settings.EditorialFlow?.createTargetTables
  if (!Array.isArray(configuredTables) || configuredTables.length === 0) {
    return [PAGE_TABLE]
  }

  const tables = configuredTables
    .map((table) => String(table).trim())
    .filter((table) => table !== '')

  return tables.length > 0 ? [...new Set(tables)] : [PAGE_TABLE]
}

function getAllowedRecordTables() {
  return getAllowedCreateTables()
    .filter((table) => table !== PAGE_TABLE && table !== CONTENT_ELEMENT_TABLE)
}

function parseSelectedRecord(value) {
  const match = String(value).match(/^(.*)_(\d+)$/)
  if (!match) {
    return null
  }

  const uid = parseInt(match[2], 10)
  if (!Number.isInteger(uid) || uid < 1) {
    return null
  }

  return {
    table: match[1],
    uid,
  }
}

function formatRecordLabel(table, uid) {
  return `${table}:${uid}`
}

function openNewTaskWizard(table, uid, recordTitle) {
  const pending = { mode: 'create_from_picker', table, uid, recordTitle }
  Modal.advanced({
    type: Modal.types.default,
    title: taskContextTitle(pending, labels),
    content: html`<editorialflow-task-wizard
      .pending=${pending}
    ></editorialflow-task-wizard>`,
    severity: SeverityEnum.notice,
    size: WIZARD_MODAL_SIZE,
    staticBackdrop: true,
    buttons: [],
  })
}

/*
 * "Create a new page": plans a page rather than creating it immediately -
 * the ticket holds no real subject until it is dragged into a review stage
 * (Classes/Wizard/TaskWizardProvider.php's create_pending_page mode,
 * TaskAjaxController::materializePendingPage()).
 */
function openPendingPageWizard() {
  const parentPid = parseInt(TYPO3.settings.EditorialFlow?.currentPageId || '0', 10)
  const pending = { mode: 'create_pending_page', parentPid }
  Modal.advanced({
    type: Modal.types.default,
    title: taskContextTitle(pending, labels),
    content: html`<editorialflow-task-wizard
      .pending=${pending}
    ></editorialflow-task-wizard>`,
    severity: SeverityEnum.notice,
    size: WIZARD_MODAL_SIZE,
    staticBackdrop: true,
    buttons: [],
  })
}

/*
 * The record type is already known by the time this opens - chosen in
 * openRecordTypePicker() below, through TYPO3 core's own "New page content"
 * wizard component. Nothing left to collect here but task details.
 */
function openPendingRecordWizard(table, label) {
  const parentPid = parseInt(TYPO3.settings.EditorialFlow?.currentPageId || '0', 10)
  const pending = { mode: 'create_pending_record', parentPid, table, recordTypeLabel: label }
  Modal.advanced({
    type: Modal.types.default,
    title: taskContextTitle(pending, labels),
    content: html`<editorialflow-task-wizard .pending=${pending}></editorialflow-task-wizard>`,
    severity: SeverityEnum.notice,
    size: WIZARD_MODAL_SIZE,
    staticBackdrop: true,
    buttons: [],
  })
}

/*
 * "Create a new record": a grouped, iconed picker for which table to plan -
 * TYPO3 core's own <typo3-backend-new-record-wizard> component (the "New page
 * content" wizard's UI), fed editorial_flow's own creatable-tables data. That
 * component acts on a click immediately (dispatches its configured event, then
 * dismisses its own modal) rather than sitting inside a multi-step sequence, so
 * it gets its own standalone modal here - same shape as openRecordPicker()
 * below, which hands off to openNewTaskWizard() the same way once a record is
 * chosen in the iframe browser.
 */
async function openRecordTypePicker() {
  const url = TYPO3.settings?.ajaxUrls?.editorialflow_task_record_type_categories
  if (!url) {
    Notification.error(NOTIFICATION_TITLE, labels.get('wizard.error.recordTypeCategoriesMissing'))
    return
  }

  let categories
  try {
    const response = await new AjaxRequest(url).get()
    const result = await response.resolve()
    if (result.success !== true) {
      throw new Error('request rejected')
    }
    categories = Categories.fromData(result.categories || {})
  } catch {
    Notification.error(NOTIFICATION_TITLE, labels.get('wizard.error.recordTypeCategoriesMissing'))
    return
  }

  if (categories.items.length === 0) {
    Notification.warning(NOTIFICATION_TITLE, labels.get('entry.record.empty'))
    return
  }

  // Built imperatively rather than through a lit `html` content template: the
  // component dispatches its selection event on itself without `bubbles: true`
  // (unlike editorial_flow's own now-removed record-type-step.js, which set that
  // explicitly), so the listener has to sit on this exact element, not on the
  // modal wrapper a few DOM levels up.
  //
  // Created via `top.document`, not this frame's own `document`: Modal.advanced()
  // renders its content in the outer chrome document, not inside this module's
  // iframe. A plain element created here would still work once moved there (a
  // cross-document appendChild silently adopts it), but this custom element
  // carries a Shadow DOM with a Lit-constructed stylesheet tied to the realm
  // that defined its class - adopting that stylesheet into a shadow root that
  // ends up owned by a *different* document throws "Sharing constructed
  // stylesheets in multiple documents is not allowed". Creating the element
  // through the same document the modal actually lives in avoids the mismatch
  // - the same reason core's own new-record-wizard.js reaches through `top` for
  // TYPO3.ModuleMenu rather than assuming its own frame's globals apply.
  const picker = top.document.createElement('typo3-backend-new-record-wizard')
  picker.categories = categories
  picker.setAttribute('store-name', 'editorialflow-new-record-type')
  picker.addEventListener('editorialflow:record-type-chosen', (event) => {
    const item = event.detail?.item
    if (!item?.identifier) {
      return
    }
    openPendingRecordWizard(item.identifier, item.label)
  })

  // top.TYPO3.Modal, not this frame's own imported Modal, for the same reason
  // `picker` itself is created via `top.document` above: the component's own
  // click handling calls `Modal.dismiss()` against *its own* realm's Modal
  // singleton (top's, since the element's class is top's), so opening the
  // modal through a different singleton left it never actually closing -
  // functionally harmless here since this picker's own event listener above
  // is what really drives the flow, but still wrong and worth matching.
  top.TYPO3.Modal.advanced({
    type: top.TYPO3.Modal.types.default,
    title: labels.get('entry.newRecord.label'),
    content: picker,
    severity: SeverityEnum.notice,
    size: top.TYPO3.Modal.sizes.large,
    staticBackdrop: true,
  })
}

/*
 * "Create a new content element": TYPO3 core's own "+Content" wizard
 * (NewContentElementController), the exact same one the page module's own
 * "+Content" button opens - reused as-is rather than rebuilt, the same way
 * openRecordTypePicker() above reuses <typo3-backend-new-record-wizard>.
 * Unlike a planned page or record, a content element always has a real page
 * to sit on already (the one selected on the board), so there is no "pending"
 * step here: core creates the record immediately through its own FormEngine
 * flow, and TaskAutoCreationDataHandlerHook - which already auto-creates or
 * advances a task for any record edited in a workspace (see ARCHITECTURE.md)
 * - picks it up from there, no explicit task-creation call needed on return.
 *
 * `colPos` is deliberately left unset: core's own wizard then shows its
 * position-map step first (matching whatever backend layout the page
 * actually has) before the content-type picker, exactly like clicking
 * "+Content" without a fixed column would in the page module itself.
 */
function openNewContentElementWizard() {
  const baseUrl = TYPO3.settings.EditorialFlow?.newContentElementWizardUrl
  const pageId = parseInt(TYPO3.settings.EditorialFlow?.currentPageId || '0', 10)
  if (!baseUrl || pageId < 1) {
    Notification.error(NOTIFICATION_TITLE, labels.get('wizard.error.newContentElementWizardMissing'))
    return
  }

  const params = new URLSearchParams({
    id: String(pageId),
    uid_pid: String(pageId),
    returnUrl: window.location.href,
  })

  // top.TYPO3.Modal, not this frame's own imported Modal: the ajax-fetched
  // content includes core's own <typo3-backend-new-record-wizard>, already
  // registered against the outer chrome document (see openRecordTypePicker()
  // above for the same cross-document reasoning). Its own click handling
  // reaches for `Modal.currentModal` to swap in the position-map step and
  // finally to dismiss itself - calling .advanced() through this frame's
  // Modal singleton would open the modal fine, but leave *that* singleton's
  // currentModal set rather than the outer chrome's, so the component's own
  // follow-up step silently fails to find anything to update.
  top.TYPO3.Modal.advanced({
    type: top.TYPO3.Modal.types.ajax,
    title: labels.get('entry.newContentElement.label'),
    content: baseUrl + (baseUrl.includes('?') ? '&' : '?') + params.toString(),
    severity: SeverityEnum.notice,
    size: top.TYPO3.Modal.sizes.large,
  })
}

function openRecordPicker(allowedTypesOverride, titleLabelKey = 'entry.recordPicker.title') {
  const baseUrl = TYPO3.settings.EditorialFlow?.elementBrowserUrl
  if (!baseUrl) {
    Notification.error(NOTIFICATION_TITLE, labels.get('wizard.error.elementBrowserMissing'))
    return
  }

  const currentPageId = parseInt(TYPO3.settings.EditorialFlow?.currentPageId || '0', 10)
  const allowedTypes = allowedTypesOverride || getAllowedCreateTables()
  if (allowedTypes.length === 0) {
    Notification.warning(NOTIFICATION_TITLE, labels.get('entry.record.empty'))
    return
  }

  const params = new URLSearchParams({
    mode: 'db',
    allowedTypes: allowedTypes.join(','),
    fieldReference: `${ELEMENT_BROWSER_FIELD_REFERENCE_PREFIX}-${Date.now()}`,
    useEvents: '1',
  })
  if (currentPageId > 0) {
    params.set('expandPage', String(currentPageId))
  }

  const modal = Modal.advanced({
    type: Modal.types.iframe,
    title: labels.get(titleLabelKey),
    content: baseUrl + (baseUrl.includes('?') ? '&' : '?') + params.toString(),
    size: Modal.sizes.large,
    severity: SeverityEnum.notice,
  })

  modal.addEventListener('typo3:element-browser:message', (event) => {
    const { actionName, value, label } = event.detail
    if (actionName !== 'typo3:elementBrowser:elementAdded') {
      return
    }

    const record = parseSelectedRecord(value)
    if (record === null) {
      Notification.error(NOTIFICATION_TITLE, labels.get('wizard.error.recordUnidentified'))
      return
    }

    modal.hideModal()
    openNewTaskWizard(record.table, record.uid, label || formatRecordLabel(record.table, record.uid))
  })
}

function openEntryChoiceWizard() {
  const sections = [
    {
      title: labels.get('entry.section.page'),
      choices: [
        {
          label: labels.get('entry.newPage.label'),
          description: labels.get('entry.newPage.description'),
          action: () => openPendingPageWizard(),
        },
        {
          label: labels.get('entry.existingPage.label'),
          description: labels.get('entry.existingPage.description'),
          action: () => openRecordPicker([PAGE_TABLE], 'entry.pagePicker.title'),
        },
      ],
    },
    {
      title: labels.get('entry.section.contentElement'),
      choices: [
        {
          label: labels.get('entry.newContentElement.label'),
          description: labels.get('entry.newContentElement.description'),
          action: () => openNewContentElementWizard(),
        },
        {
          label: labels.get('entry.contentElement.label'),
          description: labels.get('entry.contentElement.description'),
          action: () => openRecordPicker([CONTENT_ELEMENT_TABLE], 'entry.contentElementPicker.title'),
        },
      ],
    },
    {
      title: labels.get('entry.section.record'),
      choices: [
        {
          label: labels.get('entry.record.label'),
          description: labels.get('entry.record.description'),
          action: () => openRecordPicker(getAllowedRecordTables(), 'entry.recordPicker.title'),
        },
        {
          label: labels.get('entry.newRecord.label'),
          description: labels.get('entry.newRecord.description'),
          action: () => openRecordTypePicker(),
        },
      ],
    },
  ]

  let modal

  const content = document.createElement('div')
  content.className = 'editorialflow-entry-chooser'

  const buildChoiceSection = ({ title, choices }) => {
    const section = document.createElement('section')
    section.className = 'editorialflow-entry-section'
    section.setAttribute('aria-label', title)

    const heading = document.createElement('h3')
    heading.className = 'editorialflow-entry-section-title'
    heading.textContent = title

    const list = document.createElement('div')
    list.className = 'editorialflow-entry-choices'
    choices.forEach(({ label, description, action }) => {
      const button = document.createElement('button')
      button.type = 'button'
      button.className = 'btn btn-default editorialflow-entry-choice'

      const title = document.createElement('span')
      title.className = 'editorialflow-entry-choice-title'
      title.textContent = label

      const hint = document.createElement('span')
      hint.className = 'editorialflow-entry-choice-hint'
      hint.textContent = description

      button.append(title, hint)
      button.addEventListener('click', () => {
        modal.hideModal()
        action()
      })
      list.append(button)
    })

    section.append(heading, list)
    return section
  }

  sections.forEach((section) => content.append(buildChoiceSection(section)))

  modal = Modal.advanced({
    type: Modal.types.default,
    title: labels.get('entry.title'),
    content,
    severity: SeverityEnum.notice,
    buttons: [
      {
        text: labels.get('entry.cancel'),
        btnClass: 'btn-default',
        name: 'cancel',
        trigger: (event, currentModal) => currentModal.hideModal(),
      },
    ],
  })
}

export function registerCreateButton(board) {
  document.querySelectorAll('[data-editorialflow-action="create-task"]').forEach((button) => {
    button.addEventListener('click', (event) => {
      event.preventDefault()

      const bannerPageId = parseInt(button.dataset.editorialflowPage || '0', 10)
      if (bannerPageId > 0) {
        openNewTaskWizard(
          'pages',
          bannerPageId,
          button.dataset.editorialflowPageTitle || ('Page ' + bannerPageId),
        )
        return
      }

      openEntryChoiceWizard()
    })
  })
}
