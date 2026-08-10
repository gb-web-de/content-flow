/*
 * "Attach to the existing page task" vs. "create a separate task" - a single
 * radio group rendered inline, the same way core's own doktype-step.js builds
 * its one-off radio-card list directly in render() rather than as a separate
 * custom element.
 */
import { html } from 'lit'

export class RouteChoiceStep {
  constructor(context, configurationData = {}) {
    this.context = context
    this.key = 'destination'
    this.title = 'Task destination'
    this.autoAdvance = false
    this.pageTaskTitle = configurationData.pageTaskTitle || 'Untitled task'
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
      value: this.value === 'create_new_task' ? 'Create a separate task for this record' : `Add it to "${this.pageTaskTitle}"`,
    }]
  }

  _select(value) {
    this.value = value
    this.context.wizard.requestUpdate()
  }

  render() {
    const choices = [
      ['attach_to_page_task', `Add it to the existing page task ("${this.pageTaskTitle}")`],
      ['create_new_task', 'Create a separate task for this record'],
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
