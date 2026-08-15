/*
 * The "+" flow: four entry points (plan a new page, pick an existing page,
 * pick any record, create a new record via core's own wizard), each ending
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

import labels from '~labels/content_flow.messages'

import { WIZARD_MODAL_SIZE } from '@gb-web/content-flow/wizard/task-wizard.js'
import { taskContextTitle } from '@gb-web/content-flow/task/task-context-title.js'

const ELEMENT_BROWSER_FIELD_REFERENCE_PREFIX = 'contentflow-create-target'

// The extension's name, not a sentence - it stays out of the label file.
const NOTIFICATION_TITLE = 'Content Flow'

function getAllowedCreateTables() {
  const configuredTables = TYPO3.settings.ContentFlow?.createTargetTables
  if (!Array.isArray(configuredTables) || configuredTables.length === 0) {
    return ['pages']
  }

  const tables = configuredTables
    .map((table) => String(table).trim())
    .filter((table) => table !== '')

  return tables.length > 0 ? [...new Set(tables)] : ['pages']
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
    content: html`<contentflow-task-wizard
      .pending=${pending}
    ></contentflow-task-wizard>`,
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
  const parentPid = parseInt(TYPO3.settings.ContentFlow?.currentPageId || '0', 10)
  const pending = { mode: 'create_pending_page', parentPid }
  Modal.advanced({
    type: Modal.types.default,
    title: taskContextTitle(pending, labels),
    content: html`<contentflow-task-wizard
      .pending=${pending}
    ></contentflow-task-wizard>`,
    severity: SeverityEnum.notice,
    size: WIZARD_MODAL_SIZE,
    staticBackdrop: true,
    buttons: [],
  })
}

function openPendingRecordWizard() {
  const parentPid = parseInt(TYPO3.settings.ContentFlow?.currentPageId || '0', 10)
  const pending = { mode: 'create_pending_record', parentPid }
  const modal = Modal.advanced({
    type: Modal.types.default,
    title: taskContextTitle(pending, labels),
    content: html`<contentflow-task-wizard .pending=${pending}></contentflow-task-wizard>`,
    severity: SeverityEnum.notice,
    size: WIZARD_MODAL_SIZE,
    staticBackdrop: true,
    buttons: [],
  })
  modal.addEventListener('contentflow:record-type-selected', (event) => {
    modal.modalTitle = taskContextTitle({
      ...pending,
      recordTypeLabel: event.detail?.label || '',
    }, labels)
  })
}

function openRecordPicker(allowedTypesOverride) {
  const baseUrl = TYPO3.settings.ContentFlow?.elementBrowserUrl
  if (!baseUrl) {
    Notification.error(NOTIFICATION_TITLE, labels.get('wizard.error.elementBrowserMissing'))
    return
  }

  const currentPageId = parseInt(TYPO3.settings.ContentFlow?.currentPageId || '0', 10)
  const allowedTypes = allowedTypesOverride || getAllowedCreateTables()
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
    title: labels.get('entry.recordPicker.title'),
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
  const choices = [
    {
      label: labels.get('entry.newPage.label'),
      description: labels.get('entry.newPage.description'),
      action: () => openPendingPageWizard(),
    },
    {
      label: labels.get('entry.existingPage.label'),
      description: labels.get('entry.existingPage.description'),
      action: () => openRecordPicker(['pages']),
    },
    {
      label: labels.get('entry.record.label'),
      description: labels.get('entry.record.description'),
      action: () => openRecordPicker(),
    },
    {
      label: labels.get('entry.newRecord.label'),
      description: labels.get('entry.newRecord.description'),
      action: () => openPendingRecordWizard(),
    },
  ]

  const list = document.createElement('div')
  list.className = 'contentflow-entry-choices'

  let modal
  choices.forEach(({ label, description, action }) => {
    const button = document.createElement('button')
    button.type = 'button'
    button.className = 'btn btn-default contentflow-entry-choice'

    const title = document.createElement('span')
    title.className = 'contentflow-entry-choice-title'
    title.textContent = label

    const hint = document.createElement('span')
    hint.className = 'contentflow-entry-choice-hint'
    hint.textContent = description

    button.append(title, hint)
    button.addEventListener('click', () => {
      modal.hideModal()
      action()
    })
    list.append(button)
  })

  modal = Modal.advanced({
    type: Modal.types.default,
    title: labels.get('entry.title'),
    content: list,
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
  document.querySelectorAll('[data-contentflow-action="create-task"]').forEach((button) => {
    button.addEventListener('click', (event) => {
      event.preventDefault()

      const bannerPageId = parseInt(button.dataset.contentflowPage || '0', 10)
      if (bannerPageId > 0) {
        openNewTaskWizard(
          'pages',
          bannerPageId,
          button.dataset.contentflowPageTitle || ('Page ' + bannerPageId),
        )
        return
      }

      openEntryChoiceWizard()
    })
  })
}
