/*
 * Stands in for the assignee picker's module. The real one is a LitElement, and
 * membership.js only ever imports it for its side effect (registering the custom
 * element) - so a test needs the import to resolve, not lit to be present.
 */
if (typeof customElements !== 'undefined' && !customElements.get('editorialflow-assignee-picker')) {
  customElements.define('editorialflow-assignee-picker', class extends HTMLElement {})
}
