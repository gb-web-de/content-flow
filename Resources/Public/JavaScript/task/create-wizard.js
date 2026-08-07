/*
 * The "+" flow: pick a page with core's element browser, then collect the task
 * details on top of TYPO3's supported MultiStepWizard.
 */
import AjaxRequest from '@typo3/core/ajax/ajax-request.js'
import Notification from '@typo3/backend/notification.js'
import Modal from '@typo3/backend/modal.js'
import MultiStepWizard from '@typo3/backend/multi-step-wizard.js'
import { SeverityEnum } from '@typo3/backend/enum/severity.js'

import { buildTaskDetailsForm } from '@gb-web/content-flow/task/task-details-form.js'

function updateTitleRequirement(wizard, titleInput) {
  if (titleInput.value.trim() === '') {
    wizard.lockNextStep()
    return
  }

  wizard.unlockNextStep()
}

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

function openTaskWizard(board, pageUid, pageTitle) {
  MultiStepWizard.set('pageUid', pageUid)
  MultiStepWizard.set('title', pageTitle)
  MultiStepWizard.set('description', '')
  MultiStepWizard.set('assignee', 'me')
  MultiStepWizard.set('priority', 2)

  MultiStepWizard.addSlide(
    'contentflow-details',
    'New task',
    '',
    SeverityEnum.notice,
    'Details',
    (slide, settings) => {
      const wrapper = document.createElement('div')
      const fields = buildTaskDetailsForm(settings, {
        title: pageTitle,
        description: '',
        assignee: 'me',
      })

      wrapper.append(fields.element)
      addPriorityField(wrapper, settings)
      slide.html(wrapper)

      updateTitleRequirement(MultiStepWizard, fields.titleInput)
      fields.titleInput.addEventListener('input', () => {
        updateTitleRequirement(MultiStepWizard, fields.titleInput)
      })
    },
  )

  MultiStepWizard.addFinalProcessingSlide(async () => {
    const settings = MultiStepWizard.setup.settings
    const title = String(settings.title || '').trim()
    if (title === '') {
      Notification.error('Content Flow', 'A title is required.')
      MultiStepWizard.previous()
      return
    }

    try {
      const result = await createTask('pages', settings.pageUid, {
        title,
        description: String(settings.description || '').trim(),
        priority: settings.priority,
        assignee: settings.assignee,
      })

      if (result.success !== true) {
        Notification.error('Content Flow', result.message || 'Could not create the task.')
        MultiStepWizard.previous()
        return
      }

      MultiStepWizard.dismiss()
      board.announce('Created task ' + title)
      Notification.success('Content Flow', 'Task created.')
      window.location.reload()
    } catch (error) {
      Notification.error('Content Flow', 'Could not reach the server.')
      MultiStepWizard.previous()
    }
  })

  MultiStepWizard.show()
}

function openPagePicker(board) {
  const baseUrl = TYPO3.settings.ContentFlow?.elementBrowserUrl
  if (!baseUrl) {
    Notification.error('Content Flow', 'Element browser is not configured.')
    return
  }

  // The same parameters core's FormEngine.openPopupWindow() builds.
  const params = new URLSearchParams({ mode: 'db', allowedTypes: 'pages', useEvents: '1' })
  const modal = Modal.advanced({
    type: Modal.types.iframe,
    title: 'Select a page',
    content: baseUrl + (baseUrl.includes('?') ? '&' : '?') + params.toString(),
    size: Modal.sizes.large,
    severity: SeverityEnum.notice,
  })

  modal.addEventListener('typo3:element-browser:message', (event) => {
    const { actionName, value, label } = event.detail
    if (actionName !== 'typo3:elementBrowser:elementAdded') {
      return
    }

    const uid = parseInt(String(value).split('_').pop(), 10)
    modal.hideModal()
    if (Number.isInteger(uid) && uid > 0) {
      openTaskWizard(board, uid, label || ('Page ' + uid))
    }
  })
}

export async function createTask(table, uid, details = {}) {
  const response = await new AjaxRequest(TYPO3.settings.ajaxUrls.contentflow_task_create)
    .post({ table, uid, ...details })
  return await response.resolve()
}

export function registerCreateButton(board) {
  document.querySelectorAll('[data-contentflow-action="create-task"]').forEach((button) => {
    button.addEventListener('click', (event) => {
      event.preventDefault()

      const bannerPageId = parseInt(button.dataset.contentflowPage || '0', 10)
      if (bannerPageId > 0) {
        openTaskWizard(board, bannerPageId, button.dataset.contentflowPageTitle || ('Page ' + bannerPageId))
        return
      }

      openPagePicker(board)
    })
  })
}
