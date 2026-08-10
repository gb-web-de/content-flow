/*
 * "Keep in progress" vs. "move directly to review", only ever shown after
 * RouteChoiceStep resolved to create_new_task. Same inline-radio pattern as
 * route-choice-step.js.
 */
import { html } from 'lit'

export class StageChoiceStep {
  constructor(context) {
    this.context = context
    this.key = 'stage'
    this.title = 'Stage'
    this.autoAdvance = false
    this.value = context.getStoreData(this.key) || 'in_progress'
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
      value: this.value === 'review' ? 'Move directly to review' : 'Keep in progress',
    }]
  }

  _select(value) {
    this.value = value
    this.context.wizard.requestUpdate()
  }

  render() {
    const choices = [
      ['in_progress', 'Keep in progress (more edits coming)'],
      ['review', 'Move directly to review'],
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
                  name="contentflow-stage-choice"
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

export default StageChoiceStep
