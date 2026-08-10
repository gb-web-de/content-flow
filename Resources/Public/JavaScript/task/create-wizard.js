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

import '@gb-web/content-flow/wizard/task-wizard.js'

const ELEMENT_BROWSER_FIELD_REFERENCE_PREFIX = 'contentflow-create-target'

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
  Modal.advanced({
    type: Modal.types.default,
    title: 'New task',
    content: html`<contentflow-task-wizard
      .pending=${{ mode: 'create_from_picker', table, uid, recordTitle }}
    ></contentflow-task-wizard>`,
    severity: SeverityEnum.notice,
    staticBackdrop: true,
    buttons: [],
  })
}

/*
 * "Neue Seite erstellen": plans a page rather than creating it immediately -
 * the ticket holds no real subject until it is dragged into a review stage
 * (Classes/Wizard/TaskWizardProvider.php's create_pending_page mode,
 * TaskAjaxController::materializePendingPage()).
 */
function openPendingPageWizard() {
  const parentPid = parseInt(TYPO3.settings.ContentFlow?.currentPageId || '0', 10)
  Modal.advanced({
    type: Modal.types.default,
    title: 'New task',
    content: html`<contentflow-task-wizard
      .pending=${{ mode: 'create_pending_page', parentPid }}
    ></contentflow-task-wizard>`,
    severity: SeverityEnum.notice,
    staticBackdrop: true,
    buttons: [],
  })
}

function openRecordPicker(allowedTypesOverride) {
  const baseUrl = TYPO3.settings.ContentFlow?.elementBrowserUrl
  if (!baseUrl) {
    Notification.error('Content Flow', 'Element browser is not configured.')
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
    title: 'Select a page, content element or record',
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
      Notification.error('Content Flow', 'The selected record could not be identified.')
      return
    }

    modal.hideModal()
    openNewTaskWizard(record.table, record.uid, label || formatRecordLabel(record.table, record.uid))
  })
}

/*
 * Opens one of core's own record wizards (db_new) in an iframe, the same
 * Modal.types.iframe pattern openRecordPicker() already uses. Unlike the
 * element browser, this wizard emits no `typo3:elementBrowser` event this
 * extension can listen for once a record is actually created - it ends in
 * core's own FormEngine inside the same iframe instead. Rather than a fragile
 * bridge into core internals, closing the modal simply reloads the board:
 * TaskAutoCreationService::captureEdit() already runs on the record's first
 * save (a workspace `update` DataHandler operation, which a brand new
 * record's initial FormEngine save is), so an unplanned task is captured and
 * its own pending wizard (see wizard.js) surfaces right after the reload -
 * the same path an edit made anywhere else in the workspace already takes.
 */
function openCoreRecordWizard(url, title) {
  if (!url) {
    Notification.error('Content Flow', 'This wizard is not configured for the current page.')
    return
  }

  const modal = Modal.advanced({
    type: Modal.types.iframe,
    title,
    content: url,
    size: Modal.sizes.large,
    severity: SeverityEnum.notice,
  })

  modal.addEventListener('typo3-modal-hidden', () => {
    window.location.reload()
  })
}

function openEntryChoiceWizard() {
  const choices = [
    {
      label: 'Neue Seite erstellen',
      description: 'Plan a new page - it is only actually created once this ticket moves to a review stage.',
      action: () => openPendingPageWizard(),
    },
    {
      label: 'Bestehende Seite bearbeiten',
      description: 'Pick an existing page from the page tree.',
      action: () => openRecordPicker(['pages']),
    },
    {
      label: 'Select Record',
      description: 'Pick any record — page, content element or other.',
      action: () => openRecordPicker(),
    },
    {
      label: 'Neuen Record erstellen',
      description: "Create a new record with TYPO3's own record wizard.",
      action: () => openCoreRecordWizard(TYPO3.settings.ContentFlow?.newRecordUrl, 'Create a new record'),
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
    title: 'New task — how do you want to start?',
    content: list,
    severity: SeverityEnum.notice,
    buttons: [
      {
        text: 'Cancel',
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
