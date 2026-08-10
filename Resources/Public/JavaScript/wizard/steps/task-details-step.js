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

import '@gb-web/content-flow/components/assignee-picker.js'

const PRIORITY_CHOICES = [
  ['1', 'High'],
  ['2', 'Normal'],
  ['3', 'Low'],
]

export class TaskDetailsStep {
  constructor(context, configurationData = {}) {
    this.context = context
    this.key = 'taskDetails'
    this.title = 'Task details'
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
    const assignableUsers = Array.isArray(TYPO3.settings.ContentFlow?.assignableUsers)
      ? TYPO3.settings.ContentFlow.assignableUsers
      : []
    const assigneeLabel = this.assignee === 'me'
      ? 'Assign to me'
      : this.assignee === 'open'
        ? 'Leave open for someone to take'
        : (assignableUsers.find((user) => String(user.uid) === this.assignee)?.name ?? this.assignee)

    return [
      { label: 'Title', value: this.titleValue },
      { label: 'Assignment', value: assigneeLabel },
    ]
  }

  render() {
    const assignableUsers = Array.isArray(TYPO3.settings.ContentFlow?.assignableUsers)
      ? TYPO3.settings.ContentFlow.assignableUsers
      : []

    return html`
      <div class="form-group">
        <label class="form-label">Title</label>
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
        <label class="form-label">Description</label>
        <textarea
          class="form-control"
          rows="3"
          placeholder="Optional description"
          .value=${this.description}
          @input=${(event) => { this.description = event.target.value }}
        ></textarea>
      </div>
      <div class="form-group">
        <label class="form-label">Assignment</label>
        <contentflow-assignee-picker
          .users=${assignableUsers}
          .value=${this.assignee}
          @change=${(event) => { this.assignee = event.target.value }}
        ></contentflow-assignee-picker>
      </div>
      ${this.showExtraFields ? this.renderExtraFields() : ''}
    `
  }

  renderExtraFields() {
    const selectedPriority = String(this.priority)
    return html`
      <div class="form-group">
        <label class="form-label">Priority</label>
        <select
          class="form-select form-control"
          @change=${(event) => { this.priority = parseInt(event.target.value, 10) }}
        >
          ${PRIORITY_CHOICES.map(
            ([value, text]) => html`<option value=${value} ?selected=${value === selectedPriority}>${text}</option>`,
          )}
        </select>
      </div>
      <div class="form-row contentflow-date-fields">
        <div class="form-group">
          <label class="form-label">Start date</label>
          <input
            type="date"
            class="form-control"
            .value=${this.startDate}
            @change=${(event) => { this.startDate = event.target.value }}
          >
        </div>
        <div class="form-group">
          <label class="form-label">Due date</label>
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
