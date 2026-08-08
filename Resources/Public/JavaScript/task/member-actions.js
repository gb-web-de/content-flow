/*
 * Preview / discard for one task member, from inside the ticket modal.
 *
 * Delegated from the document, same reasoning as comment.js: the ticket's
 * member list arrives with the modal, loaded by ajax after the page is ready.
 */
import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import Notification from '@typo3/backend/notification.js';
import Modal from '@typo3/backend/modal.js';
import { SeverityEnum } from '@typo3/backend/enum/severity.js';

export function registerMemberActions() {
  document.addEventListener('click', async (event) => {
    const previewButton = event.target.closest('.contentflow-member-preview');
    if (previewButton !== null) {
      await previewMember(previewButton.dataset.table, parseInt(previewButton.dataset.uid, 10));
      return;
    }

    const discardButton = event.target.closest('.contentflow-member-discard');
    if (discardButton !== null) {
      await discardMember(
        discardButton.dataset.table,
        parseInt(discardButton.dataset.uid, 10),
        discardButton.dataset.title || 'this record',
      );
    }
  });
}

async function previewMember(table, uid) {
  try {
    const response = await new AjaxRequest(TYPO3.settings.ajaxUrls.contentflow_task_preview_member)
      .post({ table, uid });
    const result = await response.resolve();

    if (result.success !== true || !result.url) {
      Notification.error('Content Flow', result.message || 'Could not build a preview link.');
      return;
    }
    window.open(result.url, 'contentflow_preview');
  } catch (error) {
    Notification.error('Content Flow', 'Could not reach the server.');
  }
}

async function discardMember(table, uid, title) {
  const confirmed = await Modal.confirm(
    'Discard version',
    'Throw away the pending changes to "' + title + '"? This cannot be undone.',
    SeverityEnum.warning,
  );
  if (!confirmed) {
    return;
  }

  try {
    const response = await new AjaxRequest(TYPO3.settings.ajaxUrls.contentflow_task_discard_member)
      .post({ table, uid });
    const result = await response.resolve();

    if (result.success !== true) {
      Notification.error('Content Flow', result.message || 'Could not discard that version.');
      return;
    }
    Notification.success('Content Flow', '"' + title + '" discarded.');
    window.location.reload();
  } catch (error) {
    Notification.error('Content Flow', 'Could not reach the server.');
  }
}
