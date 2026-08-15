/*
 * Flattens the wizard's dataStore into the shape TaskWizardProvider::handleSubmit()
 * expects, and posts it to core's generic wizard_submit route. Modelled on
 * @typo3/backend/page-wizard/finisher/page-wizard-submission-service.js.
 */
import AjaxRequest from '@typo3/core/ajax/ajax-request.js'

export class TaskWizardSubmissionService {
  constructor(context) {
    this.context = context
  }

  async execute() {
    const store = this.context.getDataStore()
    const body = {
      mode: store.pending?.mode,
      ...store.pending,
      ...(store.recordType ? { recordType: store.recordType } : {}),
      destination: store.destination,
      ...store.taskDetails,
      stageChoice: store.stage,
    }

    const response = await new AjaxRequest(TYPO3.settings.ajaxUrls.wizard_submit)
      .withQueryArguments({ mode: 'contentflow_task_wizard' })
      .post(body)

    return await response.resolve()
  }
}

export default TaskWizardSubmissionService
