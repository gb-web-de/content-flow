/*
 * Post-save task wizard. Built on TYPO3's supported MultiStepWizard JS API
 * rather than the newer PHP wizard provider classes, which core marks @internal.
 */
import DocumentService from '@typo3/core/document-service.js'
import AjaxRequest from '@typo3/core/ajax/ajax-request.js'
import Notification from '@typo3/backend/notification.js'
import MultiStepWizard from '@typo3/backend/multi-step-wizard.js'
import { SeverityEnum } from '@typo3/backend/enum/severity.js'

import { buildTaskDetailsForm } from '@gb-web/content-flow/task/task-details-form.js'

function updateTitleRequirement(wizard, titleInput, required) {
  if (!required || titleInput.value.trim() !== '') {
    wizard.unlockNextStep()
    return
  }

  wizard.lockNextStep()
}

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
    MultiStepWizard.set('actionType', 'configure_auto_task')
    MultiStepWizard.set('taskUid', pending.taskUid)
    MultiStepWizard.set('table', pending.table)
    MultiStepWizard.set('uid', pending.uid)
    MultiStepWizard.set('title', pending.defaultTitle || pending.subjectTitle || pending.editedTitle || '')
    MultiStepWizard.set('description', '')
    MultiStepWizard.set('assignee', 'me')

    MultiStepWizard.addSlide(
      'contentflow-configure-auto-task',
      'Finish task details',
      '',
      SeverityEnum.notice,
      'Details',
      (slide, settings) => {
        const wrapper = document.createElement('div')
        wrapper.append(
          buildIntro(
            'A task was opened automatically for this workspace edit. Please confirm the title and add optional details.',
          ),
        )

        const fields = buildTaskDetailsForm(settings, {
          title: pending.defaultTitle || pending.subjectTitle || pending.editedTitle || '',
          description: '',
          assignee: 'me',
        })
        wrapper.append(fields.element)
        slide.html(wrapper)

        updateTitleRequirement(MultiStepWizard, fields.titleInput, true)
        fields.titleInput.addEventListener('input', () => {
          updateTitleRequirement(MultiStepWizard, fields.titleInput, true)
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
          MultiStepWizard.previous()
          return
        }

        MultiStepWizard.dismiss()
        Notification.success('Content Flow', 'Task details saved.')
        window.location.reload()
      } catch (error) {
        Notification.error('Content Flow', 'Could not reach the server.')
        MultiStepWizard.previous()
      }
    })

    MultiStepWizard.show()
  }

  openRouteMemberWizard(pending) {
    MultiStepWizard.set('actionType', 'attach_to_page_task')
    MultiStepWizard.set('table', pending.table)
    MultiStepWizard.set('uid', pending.uid)
    MultiStepWizard.set('pageTaskUid', pending.pageTaskUid)
    MultiStepWizard.set('title', pending.defaultTitle || pending.recordTitle || '')
    MultiStepWizard.set('description', '')
    MultiStepWizard.set('assignee', 'me')
    MultiStepWizard.set('stageChoice', 'in_progress')

    MultiStepWizard.addSlide(
      'contentflow-route-member',
      'Route this edit',
      '',
      SeverityEnum.notice,
      'Decision',
      (slide, settings) => {
        const wrapper = document.createElement('div')
        wrapper.append(
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
        existingRadio.checked = settings.actionType !== 'create_new_task'
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
        separateRadio.checked = settings.actionType === 'create_new_task'
        separateRadio.addEventListener('change', () => {
          settings.actionType = separateRadio.value
          toggleSeparateFields()
        })
        separateLabel.append(separateRadio, ' ', 'Create a separate task for this record')
        separateChoice.append(separateLabel)

        choiceField.append(existingChoice, separateChoice)
        wrapper.append(choiceField)

        const separateFields = document.createElement('div')
        const fields = buildTaskDetailsForm(settings, {
          title: pending.defaultTitle || pending.recordTitle || '',
          description: '',
          assignee: 'me',
        })
        separateFields.append(fields.element, buildStageChoice(settings))
        wrapper.append(separateFields)
        slide.html(wrapper)

        const toggleSeparateFields = () => {
          const separate = settings.actionType === 'create_new_task'
          separateFields.hidden = !separate
          updateTitleRequirement(MultiStepWizard, fields.titleInput, separate)
        }

        toggleSeparateFields()
        fields.titleInput.addEventListener('input', () => {
          updateTitleRequirement(MultiStepWizard, fields.titleInput, settings.actionType === 'create_new_task')
        })
      },
    )

    MultiStepWizard.addFinalProcessingSlide(async () => {
      const settings = MultiStepWizard.setup.settings
      const title = String(settings.title || '').trim()
      if (settings.actionType === 'create_new_task' && title === '') {
        Notification.error('Content Flow', 'A title is required.')
        MultiStepWizard.previous()
        return
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
          MultiStepWizard.previous()
          return
        }

        MultiStepWizard.dismiss()
        Notification.success(
          'Content Flow',
          result.action === 'attached'
            ? 'Edit added to the existing task.'
            : 'A separate task was created.',
        )
        window.location.reload()
      } catch (error) {
        Notification.error('Content Flow', 'Could not reach the server.')
        MultiStepWizard.previous()
      }
    })

    MultiStepWizard.show()
  }

  async submit(payload) {
    const response = await new AjaxRequest(TYPO3.settings.ajaxUrls.contentflow_task_wizard_submit).post(payload)
    return await response.resolve()
  }
}

export default new ContentFlowWizard()
