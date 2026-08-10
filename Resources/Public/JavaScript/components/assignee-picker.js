/*
 * Searchable assignee picker, replacing the plain <select> that used to list
 * "me" / "open" / every backend user. Light DOM (no shadow root): inherits
 * the backend's form-control styling from backend.css for free, the same way
 * the wizard step classes in wizard/steps/ render their own one-off fields
 * inline rather than reaching for shadow-DOM isolation.
 */
import { LitElement, html } from 'lit'

const BASE_CHOICES = [
  { value: 'me', label: 'Assign to me' },
  { value: 'open', label: 'Leave open for someone to take' },
]

export class ContentFlowAssigneePicker extends LitElement {
  static properties = {
    users: { attribute: false },
    value: { type: String },
    _query: { state: true },
    _open: { state: true },
    _highlighted: { state: true },
  }

  constructor() {
    super()
    this.users = []
    this.value = 'me'
    this._query = ''
    this._open = false
    this._highlighted = 0
  }

  createRenderRoot() {
    return this
  }

  get _allOptions() {
    return [...BASE_CHOICES, ...this.users.map((user) => ({ value: String(user.uid), label: user.name }))]
  }

  get _filteredOptions() {
    const query = this._query.trim().toLowerCase()
    if (query === '') {
      return this._allOptions
    }
    return this._allOptions.filter((option) => option.label.toLowerCase().includes(query))
  }

  get _selectedLabel() {
    const match = this._allOptions.find((option) => option.value === this.value)
    return match ? match.label : ''
  }

  _openList() {
    this._open = true
    this._highlighted = Math.max(0, this._filteredOptions.findIndex((option) => option.value === this.value))
  }

  _close() {
    this._open = false
    this._query = ''
  }

  _select(option) {
    this.value = option.value
    this._close()
    this.dispatchEvent(new Event('change', { bubbles: true, composed: true }))
  }

  _onInput(event) {
    this._query = event.target.value
    this._highlighted = 0
    this._open = true
  }

  _onKeydown(event) {
    const options = this._filteredOptions
    if (event.key === 'ArrowDown') {
      event.preventDefault()
      this._open = true
      this._highlighted = Math.min(this._highlighted + 1, options.length - 1)
    } else if (event.key === 'ArrowUp') {
      event.preventDefault()
      this._highlighted = Math.max(this._highlighted - 1, 0)
    } else if (event.key === 'Enter') {
      event.preventDefault()
      const option = options[this._highlighted]
      if (option) {
        this._select(option)
      }
    } else if (event.key === 'Escape') {
      this._close()
    }
  }

  render() {
    const options = this._filteredOptions
    return html`
      <div class="contentflow-assignee-picker">
        <input
          type="text"
          class="form-control"
          role="combobox"
          aria-expanded=${this._open}
          aria-autocomplete="list"
          autocomplete="off"
          .value=${this._open ? this._query : this._selectedLabel}
          @focus=${() => this._openList()}
          @input=${(event) => this._onInput(event)}
          @keydown=${(event) => this._onKeydown(event)}
          @blur=${() => this._close()}
        >
        <ul class="contentflow-assignee-options" role="listbox" ?hidden=${!this._open}>
          ${options.map(
            (option, index) => html`
              <li
                role="option"
                aria-selected=${option.value === this.value}
                class=${index === this._highlighted ? 'is-highlighted' : ''}
                @mousedown=${(event) => event.preventDefault()}
                @click=${() => this._select(option)}
              >${option.label}</li>
            `,
          )}
          ${options.length === 0 ? html`<li class="contentflow-assignee-options-empty">No matches</li>` : ''}
        </ul>
      </div>
    `
  }
}

customElements.define('contentflow-assignee-picker', ContentFlowAssigneePicker)
