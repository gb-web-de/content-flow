/*
 * Thin wrapper around TYPO3 core's generic <typo3-backend-wizard> shell,
 * modelled directly on @typo3/backend/page-wizard/page-wizard.js: builds the
 * step context, loads the initial step set for `this.pending.mode` via
 * loadDynamicSteps(), and - for the route_member mode's destination choice -
 * loads a follow-up step set once the user answers it, exactly like
 * page-wizard.js does after its own doktype step.
 *
 * No decorators (no build step in this extension): reactive properties are
 * declared via the plain `static properties` form instead of @property().
 */
import { LitElement, html } from 'lit'
import '@typo3/backend/wizard/wizard.js'
import { loadDynamicSteps } from '@typo3/backend/wizard/helper/dynamic-steps-loader.js'
import { AutoAdvanceEvent } from '@typo3/backend/wizard/events/auto-advance-event.js'

import { TaskWizardSubmissionService } from '@gb-web/content-flow/wizard/task-wizard-submission-service.js'

export class TaskWizard extends LitElement {
  static properties = {
    pending: { type: Object },
    initialSteps: { state: true },
    followUpSteps: { state: true },
    submissionService: { state: true },
  }

  constructor() {
    super()
    this.pending = null
    this.initialSteps = []
    this.followUpSteps = []
    this.submissionService = null
  }

  createRenderRoot() {
    return this
  }

  firstUpdated() {
    const wizardElement = this.querySelector('typo3-backend-wizard')
    this.context = {
      wizard: wizardElement,
      getStoreData: wizardElement.getStoreData.bind(wizardElement),
      setStoreData: wizardElement.setStoreData.bind(wizardElement),
      clearStoreData: wizardElement.clearStoreData.bind(wizardElement),
      getDataStore: wizardElement.getDataStore.bind(wizardElement),
      dispatchAutoAdvance: () => wizardElement.dispatchEvent(new AutoAdvanceEvent()),
    }
    this.context.setStoreData('pending', this.pending)
    this.submissionService = new TaskWizardSubmissionService(this.context)

    loadDynamicSteps('contentflow_task_wizard', this.context).then((steps) => {
      this.initialSteps = steps
    })
  }

  render() {
    return html`
      <typo3-backend-wizard
        .steps=${[...this.initialSteps, ...this.followUpSteps]}
        .submissionService=${this.submissionService}
        confirm-button-label="Save"
        @wizard-before-next-step=${(event) => this._onBeforeNextStep(event)}
      ></typo3-backend-wizard>
    `
  }

  _onBeforeNextStep(event) {
    if (event.detail.currentStepKey !== 'destination') {
      return
    }
    event.detail.result = loadDynamicSteps('contentflow_task_wizard', this.context).then((steps) => {
      this.followUpSteps = steps
    })
  }
}

customElements.define('contentflow-task-wizard', TaskWizard)
