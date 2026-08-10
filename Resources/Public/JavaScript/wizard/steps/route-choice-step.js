/*
 * "Attach to the existing page task" vs. "create a separate task" - a single
 * radio group rendered inline, the same way core's own doktype-step.js builds
 * its one-off radio-card list directly in render() rather than as a separate
 * custom element.
 */
import { html } from 'lit'
import labels from '~labels/content_flow.messages'

export class RouteChoiceStep {
  constructor(context, configurationData = {}) {
    this.context = context
    this.key = 'destination'
    this.title = labels.get('step.destination.title')
    this.autoAdvance = false
    this.pageTaskTitle = configurationData.pageTaskTitle || labels.get('task.untitled')
    this.value = context.getStoreData(this.key) || 'attach_to_page_task'
  }

  isComplete() {
    return this.value !== null
  }

  beforeAdvance() {
    this.context.setStoreData(this.key, this.value)
  }

  getSummaryData() {
    return [{
      label: this.title,
      value: this.value === 'create_new_task'
        ? labels.get('step.destination.create')
        : labels.get('step.destination.attach.summary', [this.pageTaskTitle]),
    }]
  }

  _select(value) {
    this.value = value
    this.context.wizard.requestUpdate()
  }

  render() {
    const choices = [
      ['attach_to_page_task', labels.get('step.destination.attach', [this.pageTaskTitle])],
      ['create_new_task', labels.get('step.destination.create')],
    ]

    return html`
      <fieldset class="form-group">
        <legend class="form-label">${this.title}</legend>
        ${choices.map(
          ([value, label]) => html`
            <div class="radio">
              <label>
                <input
                  type="radio"
                  name="contentflow-destination-choice"
                  .value=${value}
                  .checked=${this.value === value}
                  @change=${() => this._select(value)}
                >
                ${label}
              </label>
            </div>
          `,
        )}
      </fieldset>
    `
  }
}

export default RouteChoiceStep
