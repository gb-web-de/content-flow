/*
 * Content Flow's board runs inside the backend's classic "list frame" iframe, but
 * TYPO3's own Modal.advanced()/Modal.types.ajax always render into the TOP-level
 * backend document (so a dialog is never clipped by the iframe's viewport). Any
 * listener delegated on this module's own `document` therefore never sees clicks
 * happening inside a ticket modal, a checklist-manage modal, or any other Modal
 * content - those nodes live in a different Document than the one the listener is
 * bound to. Bind to the top document instead whenever one exists.
 */
export function topDocument() {
  try {
    return window.top && window.top !== window ? window.top.document : document;
  } catch {
    // Cross-origin top (should never happen inside the TYPO3 backend) - fail safe.
    return document;
  }
}
