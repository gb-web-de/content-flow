/*
 * Title/description/assignee - and, when configurationData.showExtraFields is
 * set (the "+" flow's create_from_picker mode), priority and start/due date -
 * collected on a single step. Plain class following the step contract shown
 * by @typo3/backend/page-wizard/steps/doktype-step.js: no getValue()/setValue()
 * (this step never needs to be reset when the wizard goes back past it, so it
 * intentionally doesn't implement the third method - see wizard.js's
 * hasValue() duck-typing check in TYPO3 core), fields stay plain instance
 * properties, and every change calls context.wizard.requestUpdate() because
 * mutating a plain object gives Lit nothing to react to on its own.
 */
import { html } from 'lit'
import labels from '~labels/editorial_flow.messages'

import '@gb-web/editorial-flow/components/assignee-picker.js'

const PRIORITY_CHOICES = [
  ['1', labels.get('step.taskDetails.priority.high')],
  ['2', labels.get('step.taskDetails.priority.normal')],
  ['3', labels.get('step.taskDetails.priority.low')],
]

export class TaskDetailsStep {
  constructor(context, configurationData = {}) {
    this.context = context
    this.key = 'taskDetails'
    this.title = labels.get('step.taskDetails.title')
    this.autoAdvance = false
    this.showExtraFields = Boolean(configurationData.showExtraFields)

    const stored = context.getStoreData(this.key) || {}
    this.titleValue = stored.title ?? configurationData.defaultTitle ?? ''
    this.description = stored.description ?? ''
    this.assignee = stored.assignee ?? 'me'
    this.priority = stored.priority ?? 2
    this.startDate = stored.startDate ?? ''
    this.dueDate = stored.dueDate ?? ''
  }

  isComplete() {
    return this.titleValue.trim() !== ''
  }

  beforeAdvance() {
    this.context.setStoreData(this.key, {
      title: this.titleValue.trim(),
      description: this.description.trim(),
      assignee: this.assignee,
      ...(this.showExtraFields ? { priority: this.priority, startDate: this.startDate, dueDate: this.dueDate } : {}),
    })
  }

  getSummaryData() {
    const assignableUsers = Array.isArray(TYPO3.settings.EditorialFlow?.assignableUsers)
      ? TYPO3.settings.EditorialFlow.assignableUsers
      : []
    const assigneeLabel = this.assignee === 'me'
      ? labels.get('assignee.me')
      : this.assignee === 'open'
        ? labels.get('assignee.open')
        : (assignableUsers.find((user) => String(user.uid) === this.assignee)?.name ?? this.assignee)

    return [
      { label: labels.get('step.taskDetails.field.title'), value: this.titleValue },
      { label: labels.get('step.taskDetails.field.assignment'), value: assigneeLabel },
    ]
  }

  render() {
    const assignableUsers = Array.isArray(TYPO3.settings.EditorialFlow?.assignableUsers)
      ? TYPO3.settings.EditorialFlow.assignableUsers
      : []

    return html`
      <div class="form-group">
        <label class="form-label">${labels.get('step.taskDetails.field.title')}</label>
        <input
          type="text"
          class="form-control"
          .value=${this.titleValue}
          @input=${(event) => {
            this.titleValue = event.target.value
            this.context.wizard.requestUpdate()
          }}
        >
      </div>
      <div class="form-group">
        <label class="form-label">${labels.get('step.taskDetails.field.description')}</label>
        <textarea
          class="form-control"
          rows="3"
          placeholder=${labels.get('step.taskDetails.field.description.placeholder')}
          .value=${this.description}
          @input=${(event) => { this.description = event.target.value }}
        ></textarea>
      </div>
      <div class="form-group">
        <label class="form-label">${labels.get('step.taskDetails.field.assignment')}</label>
        <editorialflow-assignee-picker
          .users=${assignableUsers}
          .value=${this.assignee}
          @change=${(event) => { this.assignee = event.target.value }}
        ></editorialflow-assignee-picker>
      </div>
      ${this.showExtraFields ? this.renderExtraFields() : ''}
    `
  }

  renderExtraFields() {
    const selectedPriority = String(this.priority)
    return html`
      <div class="form-group">
        <label class="form-label">${labels.get('step.taskDetails.field.priority')}</label>
        <select
          class="form-select form-control"
          @change=${(event) => { this.priority = parseInt(event.target.value, 10) }}
        >
          ${PRIORITY_CHOICES.map(
            ([value, text]) => html`<option value=${value} ?selected=${value === selectedPriority}>${text}</option>`,
          )}
        </select>
      </div>
      <div class="form-row editorialflow-date-fields">
        <div class="form-group">
          <label class="form-label">${labels.get('step.taskDetails.field.startDate')}</label>
          <input
            type="date"
            class="form-control"
            .value=${this.startDate}
            @change=${(event) => { this.startDate = event.target.value }}
          >
        </div>
        <div class="form-group">
          <label class="form-label">${labels.get('step.taskDetails.field.dueDate')}</label>
          <input
            type="date"
            class="form-control"
            .value=${this.dueDate}
            @change=${(event) => { this.dueDate = event.target.value }}
          >
        </div>
      </div>
    `
  }
}

export default TaskDetailsStep
