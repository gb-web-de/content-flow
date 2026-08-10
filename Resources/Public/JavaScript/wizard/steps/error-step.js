/*
 * Fallback for a wizard opened with no recognisable pending payload - should
 * never happen in practice (checkPendingWizard() only opens the wizard for a
 * payload it already validated), but the provider needs some Step to return
 * rather than an empty Configuration turning straight into a no-op confirm
 * screen.
 */
import { html } from 'lit'
import labels from '~labels/content_flow.messages'

export class ErrorStep {
  constructor(context, configurationData = {}) {
    this.context = context
    this.key = 'error'
    this.title = labels.get('step.error.title')
    this.autoAdvance = false
    this.message = configurationData.message || labels.get('wizard.error.stepFallback')
  }

  isComplete() {
    return false
  }

  render() {
    return html`<typo3-backend-alert severity="2" heading=${this.title} message=${this.message} show-icon></typo3-backend-alert>`
  }
}

export default ErrorStep
