import { html } from 'lit'
import labels from '~labels/content_flow.messages'

export class RecordTypeStep {
  constructor(context, configurationData = {}) {
    this.context = context
    this.key = 'recordType'
    this.title = labels.get('step.recordType.title')
    this.autoAdvance = false
    this.recordTypes = Array.isArray(configurationData.recordTypes) ? configurationData.recordTypes : []
    this.value = context.getStoreData(this.key) || ''
  }

  isComplete() {
    return this.recordTypes.some((type) => type.table === this.value)
  }

  beforeAdvance() {
    this.context.setStoreData(this.key, this.value)
  }

  getSummaryData() {
    const selected = this.recordTypes.find((type) => type.table === this.value)
    return [{ label: this.title, value: selected?.label || this.value }]
  }

  render() {
    if (this.recordTypes.length === 0) {
      return html`<div class="callout callout-warning"><div class="callout-body">${labels.get('step.recordType.empty')}</div></div>`
    }

    return html`
      <fieldset class="form-group">
        <legend class="form-label">${labels.get('step.recordType.description')}</legend>
        <div class="contentflow-record-type-list">
          ${this.recordTypes.map((type) => html`
            <label class="contentflow-record-type-option">
              <input
                type="radio"
                name="contentflow-record-type"
                .value=${type.table}
                .checked=${this.value === type.table}
                @change=${() => {
                  this.value = type.table
                  this.context.wizard.requestUpdate()
                }}
              >
              <span>${type.label}</span>
              <small>${type.table}</small>
            </label>
          `)}
        </div>
      </fieldset>
    `
  }
}

export default RecordTypeStep
