/*
 * Post-save task wizard.
 *
 * Not built on TYPO3's MultiStepWizard, despite both flows below looking like
 * natural candidates for it (a details step, then processing). Reproduced -
 * with this file already running in the top-level backend chrome document via
 * AfterBackendPageRenderEvent, no content iframe involved at all - that
 * MultiStepWizard's own carousel setup in this TYPO3 version throws
 * `Cannot read properties of null (reading 'addEventListener')` on its own,
 * a timing race in core between Modal's `requestAnimationFrame`-gated
 * `showModal()` and the wizard's async slide-advance setup. Not something
 * this extension can work around short of patching core, so both wizards
 * here are a single form inside a plain `Modal.advanced()` instead - see
 * task/create-wizard.js's docblock for the same finding on the "+" flow.
 */
import DocumentService from '@typo3/core/document-service.js'
import AjaxRequest from '@typo3/core/ajax/ajax-request.js'
import Notification from '@typo3/backend/notification.js'
import Modal from '@typo3/backend/modal.js'
import { SeverityEnum } from '@typo3/backend/enum/severity.js'

import { buildTaskDetailsForm } from '@gb-web/content-flow/task/task-details-form.js'

function buildIntro(text) {
  const intro = document.createElement('p')
  intro.textContent = text
  return intro
}

function buildStageChoice(settings) {
  const stageChoices = [
    ['in_progress', 'Keep in progress (more edits coming)'],
    ['review', 'Move directly to review'],
  ]

  const field = document.createElement('fieldset')
  field.className = 'form-group'

  const legend = document.createElement('legend')
  legend.className = 'form-label'
  legend.textContent = 'Stage'
  field.append(legend)

  settings.stageChoice = settings.stageChoice || 'in_progress'

  stageChoices.forEach(([value, labelText]) => {
    const wrapper = document.createElement('div')
    wrapper.className = 'radio'

    const label = document.createElement('label')
    const radio = document.createElement('input')
    radio.type = 'radio'
    radio.name = 'contentflow-stage-choice'
    radio.value = value
    radio.checked = settings.stageChoice === value
    radio.addEventListener('change', () => {
      settings.stageChoice = value
    })

    label.append(radio, ' ', labelText)
    wrapper.append(label)
    field.append(wrapper)
  })

  return field
}

class ContentFlowWizard {
  constructor() {
    DocumentService.ready().then(() => this.checkPendingWizard())
  }

  async checkPendingWizard() {
    if (!TYPO3.settings?.ajaxUrls?.contentflow_task_wizard_pending) {
      return
    }

    try {
      const response = await new AjaxRequest(TYPO3.settings.ajaxUrls.contentflow_task_wizard_pending).get()
      const result = await response.resolve()

      if (result.success !== true || !result.pending) {
        return
      }

      this.openWizard(result.pending)
    } catch (error) {
      // Silent catch if no session data or the backend is offline.
    }
  }

  openWizard(pending) {
    if (pending.mode === 'route_member') {
      this.openRouteMemberWizard(pending)
      return
    }

    this.openConfigureTaskWizard(pending)
  }

  openConfigureTaskWizard(pending) {
    const settings = {
      actionType: 'configure_auto_task',
      taskUid: pending.taskUid,
      table: pending.table,
      uid: pending.uid,
    }

    const form = document.createElement('form')
    form.addEventListener('submit', (event) => event.preventDefault())
    form.append(
      buildIntro(
        'A task was opened automatically for this workspace edit. Please confirm the title and add optional details.',
      ),
    )

    const fields = buildTaskDetailsForm(settings, {
      title: pending.defaultTitle || pending.subjectTitle || pending.editedTitle || '',
      description: '',
      assignee: 'me',
    })
    form.append(fields.element)

    const modal = Modal.advanced({
      type: Modal.types.default,
      title: 'Finish task details',
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
          text: 'Save',
          btnClass: 'btn-notice',
          name: 'save',
          trigger: async (event, currentModal) => {
            const title = String(settings.title || '').trim()
            if (title === '') {
              Notification.warning('Content Flow', 'A title is required.')
              fields.titleInput.focus()
              return
            }

            const saveButton = currentModal.querySelector('button[name="save"]')
            if (saveButton) {
              saveButton.disabled = true
            }

            try {
              const result = await this.submit({
                actionType: settings.actionType,
                taskUid: settings.taskUid,
                table: settings.table,
                uid: settings.uid,
                title,
                description: String(settings.description || '').trim(),
                assignee: settings.assignee,
              })

              if (result.success !== true) {
                Notification.error('Content Flow', result.message || 'Could not save the task details.')
                if (saveButton) {
                  saveButton.disabled = false
                }
                return
              }

              currentModal.hideModal()
              Notification.success('Content Flow', 'Task details saved.')
              window.location.reload()
            } catch (error) {
              Notification.error('Content Flow', 'Could not reach the server.')
              if (saveButton) {
                saveButton.disabled = false
              }
            }
          },
        },
      ],
    })

    const updateSaveButton = () => {
      const saveButton = modal.querySelector('button[name="save"]')
      if (saveButton) {
        saveButton.disabled = fields.titleInput.value.trim() === ''
      }
    }
    modal.addEventListener('typo3-modal-shown', updateSaveButton, { once: true })
    fields.titleInput.addEventListener('input', updateSaveButton)
  }

  openRouteMemberWizard(pending) {
    const settings = {
      actionType: 'attach_to_page_task',
      table: pending.table,
      uid: pending.uid,
      pageTaskUid: pending.pageTaskUid,
      stageChoice: 'in_progress',
    }

    const form = document.createElement('form')
    form.addEventListener('submit', (event) => event.preventDefault())
    form.append(
      buildIntro(
        'An open task already exists for this page. Decide whether this edit belongs there or should become a separate task.',
      ),
    )

    const choiceField = document.createElement('fieldset')
    choiceField.className = 'form-group'
    const choiceLegend = document.createElement('legend')
    choiceLegend.className = 'form-label'
    choiceLegend.textContent = 'Task destination'
    choiceField.append(choiceLegend)

    const existingChoice = document.createElement('div')
    existingChoice.className = 'radio'
    const existingLabel = document.createElement('label')
    const existingRadio = document.createElement('input')
    existingRadio.type = 'radio'
    existingRadio.name = 'contentflow-action-type'
    existingRadio.value = 'attach_to_page_task'
    existingRadio.checked = true
    existingRadio.addEventListener('change', () => {
      settings.actionType = existingRadio.value
      toggleSeparateFields()
    })
    existingLabel.append(
      existingRadio,
      ' ',
      'Add it to the existing page task ("' + (pending.pageTaskTitle || 'Untitled task') + '")',
    )
    existingChoice.append(existingLabel)

    const separateChoice = document.createElement('div')
    separateChoice.className = 'radio'
    const separateLabel = document.createElement('label')
    const separateRadio = document.createElement('input')
    separateRadio.type = 'radio'
    separateRadio.name = 'contentflow-action-type'
    separateRadio.value = 'create_new_task'
    separateRadio.addEventListener('change', () => {
      settings.actionType = separateRadio.value
      toggleSeparateFields()
    })
    separateLabel.append(separateRadio, ' ', 'Create a separate task for this record')
    separateChoice.append(separateLabel)

    choiceField.append(existingChoice, separateChoice)
    form.append(choiceField)

    const separateFields = document.createElement('div')
    const fields = buildTaskDetailsForm(settings, {
      title: pending.defaultTitle || pending.recordTitle || '',
      description: '',
      assignee: 'me',
    })
    separateFields.append(fields.element, buildStageChoice(settings))
    form.append(separateFields)

    let updateSaveButton = () => {}
    const toggleSeparateFields = () => {
      const separate = settings.actionType === 'create_new_task'
      separateFields.hidden = !separate
      updateSaveButton()
    }
    toggleSeparateFields()
    fields.titleInput.addEventListener('input', updateSaveButton)

    const modal = Modal.advanced({
      type: Modal.types.default,
      title: 'Route this edit',
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
          text: 'Continue',
          btnClass: 'btn-notice',
          name: 'continue',
          trigger: async (event, currentModal) => {
            const title = String(settings.title || '').trim()
            if (settings.actionType === 'create_new_task' && title === '') {
              Notification.warning('Content Flow', 'A title is required.')
              fields.titleInput.focus()
              return
            }

            const continueButton = currentModal.querySelector('button[name="continue"]')
            if (continueButton) {
              continueButton.disabled = true
            }

            try {
              const result = await this.submit({
                actionType: settings.actionType,
                table: settings.table,
                uid: settings.uid,
                pageTaskUid: settings.pageTaskUid,
                title,
                description: String(settings.description || '').trim(),
                assignee: settings.assignee,
                stageChoice: settings.stageChoice,
              })

              if (result.success !== true) {
                Notification.error('Content Flow', result.message || 'Could not route the task.')
                if (continueButton) {
                  continueButton.disabled = false
                }
                return
              }

              currentModal.hideModal()
              Notification.success(
                'Content Flow',
                result.action === 'attached'
                  ? 'Edit added to the existing task.'
                  : 'A separate task was created.',
              )
              window.location.reload()
            } catch (error) {
              Notification.error('Content Flow', 'Could not reach the server.')
              if (continueButton) {
                continueButton.disabled = false
              }
            }
          },
        },
      ],
    })

    updateSaveButton = () => {
      const continueButton = modal.querySelector('button[name="continue"]')
      if (continueButton) {
        continueButton.disabled = settings.actionType === 'create_new_task' && fields.titleInput.value.trim() === ''
      }
    }
    modal.addEventListener('typo3-modal-shown', updateSaveButton, { once: true })
    toggleSeparateFields()
  }

  async submit(payload) {
    const response = await new AjaxRequest(TYPO3.settings.ajaxUrls.contentflow_task_wizard_submit).post(payload)
    return await response.resolve()
  }
}

export default new ContentFlowWizard()
