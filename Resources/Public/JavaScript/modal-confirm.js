/*
 * Modal.confirm() itself never settles anything: its default OK/Cancel buttons
 * carry no `trigger`, so clicking either one only dispatches
 * 'confirm.button.ok'/'confirm.button.cancel' on the button and leaves the
 * dialog open - TYPO3 core expects the caller to listen for those events by
 * hand and call hideModal() itself (see modal.ts's own JSDoc on confirm()).
 * `await Modal.confirm(...)` therefore does not wait for a click at all: there
 * is no promise to await, so it resolves immediately to the (truthy) modal
 * element, and the dialog is left stuck on screen with both buttons inert.
 *
 * This wraps that in the boolean promise every call site here actually wants.
 */
import Modal from '@typo3/backend/modal.js';
import { SeverityEnum } from '@typo3/backend/enum/severity.js';

export function confirmModal(title, content, severity = SeverityEnum.warning) {
  const modal = Modal.confirm(title, content, severity);

  return new Promise((resolve) => {
    let settled = false;
    const settle = (result) => {
      if (settled) {
        return;
      }
      settled = true;
      resolve(result);
    };

    // settle() before hideModal(): hideModal() can itself dispatch
    // 'typo3-modal-hidden' (a close driven by the button, not around it), and
    // that listener must find settled already true, or it overwrites this
    // button's own answer with the close-without-answering default of false.
    modal.addEventListener('confirm.button.ok', () => {
      settle(true);
      modal.hideModal();
    });
    modal.addEventListener('confirm.button.cancel', () => {
      settle(false);
      modal.hideModal();
    });
    // Escape, the header close button, and a backdrop click all hide the
    // modal without going through either button - still a "no".
    modal.addEventListener('typo3-modal-hidden', () => settle(false));
  });
}
