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
    this.filter = ''
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

    const filter = this.filter.trim().toLowerCase()
    const visibleTypes = filter === ''
      ? this.recordTypes
      : this.recordTypes.filter((type) => {
        const haystack = `${type.label || ''} ${type.table || ''}`.toLowerCase()
        return haystack.includes(filter)
      })

    return html`
      <fieldset class="form-group">
        <legend class="form-label">${labels.get('step.recordType.description')}</legend>
        <div class="form-group">
          <label class="form-label" for="contentflow-record-type-filter">${labels.get('step.recordType.search')}</label>
          <input
            id="contentflow-record-type-filter"
            type="search"
            class="form-control"
            .value=${this.filter}
            placeholder=${labels.get('step.recordType.searchPlaceholder')}
            @input=${(event) => {
              this.filter = event.target.value || ''
              this.context.wizard.requestUpdate()
            }}
          >
        </div>
        <div class="contentflow-record-type-list">
          ${visibleTypes.length === 0 ? html`
            <div class="callout callout-info"><div class="callout-body">${labels.get('step.recordType.noResults')}</div></div>
          ` : visibleTypes.map((type) => html`
            <label class="contentflow-record-type-option">
              <input
                type="radio"
                name="contentflow-record-type"
                .value=${type.table}
                .checked=${this.value === type.table}
                @change=${() => {
                  this.value = type.table
                  this.context.wizard.dispatchEvent(new CustomEvent('contentflow:record-type-selected', {
                    bubbles: true,
                    detail: { table: type.table, label: type.label },
                  }))
                  this.context.wizard.requestUpdate()
                }}
              >
              <span title=${type.label}>${type.label}</span>
              <small title=${type.table}>${type.table}</small>
            </label>
          `)}
        </div>
      </fieldset>
    `
  }
}

export default RecordTypeStep
