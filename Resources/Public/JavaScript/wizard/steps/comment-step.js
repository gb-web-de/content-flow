/*
 * B5's non-blocking follow-up: the task itself was already regressed to
 * Editing with an auto-generated comment before this wizard ever appeared
 * (TaskAutoCreationService::maybeRegressPastEditing() - a DataHandler hook
 * runs after the save already completed, so it cannot block on a
 * human-authored comment the way a synchronous validation could). This step
 * only lets the editor refine that comment's wording, never the transition
 * itself - which has already happened either way.
 *
 * Stores its value under the shared 'taskDetails' store key (like
 * task-details-step.js does for title/description/assignee) purely so
 * task-wizard-submission-service.js's `...store.taskDetails` spread already
 * carries `content` into the submit body without needing a mode-specific
 * case there.
 */
import { html } from 'lit'
import labels from '~labels/editorial_flow.messages'

export class CommentStep {
  constructor(context, configurationData = {}) {
    this.context = context
    this.key = 'comment'
    this.title = labels.get('step.comment.title')
    this.autoAdvance = false
    this.value = configurationData.defaultComment || ''
  }

  isComplete() {
    return this.value.trim() !== ''
  }

  beforeAdvance() {
    this.context.setStoreData('taskDetails', { content: this.value.trim() })
  }

  getSummaryData() {
    return [{ label: this.title, value: this.value }]
  }

  render() {
    return html`
      <div class="form-group">
        <label class="form-label">${this.title}</label>
        <textarea
          class="form-control"
          rows="3"
          .value=${this.value}
          @input=${(event) => {
            this.value = event.target.value
            this.context.wizard.requestUpdate()
          }}
        ></textarea>
      </div>
    `
  }
}

export default CommentStep
