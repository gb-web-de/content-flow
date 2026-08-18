/*
 * Stands in for the assignee picker's module. The real one is a LitElement, and
 * membership.js only ever imports it for its side effect (registering the custom
 * element) - so a test needs the import to resolve, not lit to be present.
 */
if (typeof customElements !== 'undefined' && !customElements.get('contentflow-assignee-picker')) {
  customElements.define('contentflow-assignee-picker', class extends HTMLElement {})
}
