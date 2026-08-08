/*
 * The "+" flow: pick a record with core's element browser, then collect the
 * task details on one form.
 *
 * Deliberately not TYPO3's MultiStepWizard: reproduced (with zero involvement
 * from this extension or the board's content iframe - a bare top-level
 * `MultiStepWizard.show()` in the browser console crashes identically) that
 * MultiStepWizard's own carousel setup in this TYPO3 version throws
 * `Cannot read properties of null (reading 'addEventListener')` the moment a
 * second slide is involved - a race between Modal's `requestAnimationFrame`-
 * gated `showModal()` and the wizard's own async slide-advance setup, not
 * something this extension can work around short of patching core. The form
 * here is short enough that a wizard added little anyway: one screen, a
 * Cancel and a Create button, built the same way board/checklist.js's manage
 * modal already builds a plain form inside `Modal.advanced()`.
 */
import AjaxRequest from '@typo3/core/ajax/ajax-request.js'
import Notification from '@typo3/backend/notification.js'
import Modal from '@typo3/backend/modal.js'
import { SeverityEnum } from '@typo3/backend/enum/severity.js'

import { buildTaskDetailsForm } from '@gb-web/content-flow/task/task-details-form.js'

const ELEMENT_BROWSER_FIELD_REFERENCE_PREFIX = 'contentflow-create-target'

function addPriorityField(container, settings) {
  const priorityChoices = [
    ['1', 'High'],
    ['2', 'Normal'],
    ['3', 'Low'],
  ]
  const selectedPriority = Number.isInteger(settings.priority) ? String(settings.priority) : '2'

  const field = document.createElement('div')
  field.className = 'form-group'

  const label = document.createElement('label')
  label.className = 'form-label'
  label.textContent = 'Priority'

  const select = document.createElement('select')
  select.className = 'form-select form-control'
  priorityChoices.forEach(([value, text]) => {
    select.add(new Option(text, value, value === selectedPriority, value === selectedPriority))
  })
  settings.priority = parseInt(select.value, 10)
  select.addEventListener('change', () => {
    settings.priority = parseInt(select.value, 10)
  })

  field.append(label, select)
  container.append(field)
}

/*
 * Start date and due date - a start date moves the task straight into
 * Planned instead of leaving it in Backlog for someone to notice and drag
 * (see TaskAjaxController::createAction()). Both are optional: most tasks
 * still open themselves from editing, unplanned, and that stays true here.
 */
function addDateFields(container, settings) {
  const row = document.createElement('div')
  row.className = 'form-row contentflow-date-fields'

  const startField = document.createElement('div')
  startField.className = 'form-group'
  const startLabel = document.createElement('label')
  startLabel.className = 'form-label'
  startLabel.textContent = 'Start date'
  const startInput = document.createElement('input')
  startInput.type = 'date'
  startInput.className = 'form-control'
  startInput.addEventListener('change', () => {
    settings.startDate = startInput.value
  })
  startField.append(startLabel, startInput)

  const dueField = document.createElement('div')
  dueField.className = 'form-group'
  const dueLabel = document.createElement('label')
  dueLabel.className = 'form-label'
  dueLabel.textContent = 'Due date'
  const dueInput = document.createElement('input')
  dueInput.type = 'date'
  dueInput.className = 'form-control'
  dueInput.addEventListener('change', () => {
    settings.dueDate = dueInput.value
  })
  dueField.append(dueLabel, dueInput)

  row.append(startField, dueField)
  container.append(row)
}

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

function openNewTaskWizard(board, table, uid, recordTitle) {
  const settings = { table, uid, priority: 2 }

  const form = document.createElement('form')
  form.className = 'contentflow-create-form'
  form.addEventListener('submit', (event) => event.preventDefault())

  const fields = buildTaskDetailsForm(settings, {
    title: recordTitle,
    description: '',
    assignee: 'me',
  })
  form.append(fields.element)
  addPriorityField(form, settings)
  addDateFields(form, settings)

  const modal = Modal.advanced({
    type: Modal.types.default,
    title: 'New task',
    content: form,
    severity: SeverityEnum.notice,
    buttons: [
      {
        text: 'Cancel',
        btnClass: 'btn-default',
        name: 'cancel',
        trigger: (event, currentModal) => currentModal.hideModal(),
      },
      {
        text: 'Create',
        btnClass: 'btn-notice',
        name: 'create',
        trigger: async (event, currentModal) => {
          const title = String(settings.title || '').trim()
          if (title === '') {
            Notification.warning('Content Flow', 'A title is required.')
            fields.titleInput.focus()
            return
          }

          const createButton = currentModal.querySelector('button[name="create"]')
          if (createButton) {
            createButton.disabled = true
          }

          try {
            const result = await createTask(settings.table, settings.uid, {
              title,
              description: String(settings.description || '').trim(),
              priority: settings.priority,
              assignee: settings.assignee,
              startDate: settings.startDate || '',
              dueDate: settings.dueDate || '',
            })

            if (result.success !== true) {
              Notification.error('Content Flow', result.message || 'Could not create the task.')
              if (createButton) {
                createButton.disabled = false
              }
              return
            }

            currentModal.hideModal()
            board.announce('Created task ' + title)
            Notification.success('Content Flow', 'Task created.')
            window.location.reload()
          } catch (error) {
            Notification.error('Content Flow', 'Could not reach the server.')
            if (createButton) {
              createButton.disabled = false
            }
          }
        },
      },
    ],
  })

  // Title is required to create the task. Looked up fresh each time rather
  // than cached once: Modal.advanced() returns before Lit has necessarily
  // committed its first render, so the button may not exist in the DOM yet
  // at this point.
  const updateCreateButton = () => {
    const createButton = modal.querySelector('button[name="create"]')
    if (createButton) {
      createButton.disabled = fields.titleInput.value.trim() === ''
    }
  }
  modal.addEventListener('typo3-modal-shown', updateCreateButton, { once: true })
  fields.titleInput.addEventListener('input', updateCreateButton)
}

function openRecordPicker(board) {
  const baseUrl = TYPO3.settings.ContentFlow?.elementBrowserUrl
  if (!baseUrl) {
    Notification.error('Content Flow', 'Element browser is not configured.')
    return
  }

  const currentPageId = parseInt(TYPO3.settings.ContentFlow?.currentPageId || '0', 10)
  const allowedTypes = getAllowedCreateTables()
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
    openNewTaskWizard(board, record.table, record.uid, label || formatRecordLabel(record.table, record.uid))
  })
}

export async function createTask(table, uid, details = {}) {
  const url = TYPO3.settings.ajaxUrls.contentflow_task_create
  if (!url) {
    return {
      success: false,
      message: 'Task creation is not configured.',
    }
  }

  const response = await new AjaxRequest(url).post({ table, uid, ...details })
  return await response.resolve()
}

export function registerCreateButton(board) {
  document.querySelectorAll('[data-contentflow-action="create-task"]').forEach((button) => {
    button.addEventListener('click', (event) => {
      event.preventDefault()

      const bannerPageId = parseInt(button.dataset.contentflowPage || '0', 10)
      if (bannerPageId > 0) {
        openNewTaskWizard(
          board,
          'pages',
          bannerPageId,
          button.dataset.contentflowPageTitle || ('Page ' + bannerPageId),
        )
        return
      }

      openRecordPicker(board)
    })
  })
}
