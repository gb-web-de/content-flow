/*
 * Preview / discard for one task member, from inside the ticket modal.
 *
 * Delegated from the TOP document, not this module's own: the ticket modal is
 * rendered by TYPO3.Modal into the top-level backend document (see dom-scope.js),
 * while this script runs inside the board's content iframe - a listener bound to
 * the iframe's own document would never see clicks on the modal's buttons. The
 * member list itself arrives with that modal, loaded by ajax after the page is
 * ready, hence delegation in the first place (same reasoning as comment.js).
 */
import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import Notification from '@typo3/backend/notification.js';
import { SeverityEnum } from '@typo3/backend/enum/severity.js';
import { topDocument } from '@gb-web/editorial-flow/dom-scope.js';
import { confirmModal } from '@gb-web/editorial-flow/modal-confirm.js';

export function registerMemberActions() {
  topDocument().addEventListener('click', async (event) => {
    const previewButton = event.target.closest('.editorialflow-member-preview');
    if (previewButton !== null) {
      await previewMember(previewButton.dataset.table, parseInt(previewButton.dataset.uid, 10));
      return;
    }

    const diffButton = event.target.closest('.editorialflow-member-diff');
    if (diffButton !== null) {
      jumpToDiff(diffButton.dataset.table, diffButton.dataset.uid, diffButton.closest('.editorialflow-ticket'));
      return;
    }

    const discardButton = event.target.closest('.editorialflow-member-discard');
    if (discardButton !== null) {
      await discardMember(
        discardButton.dataset.table,
        parseInt(discardButton.dataset.uid, 10),
        discardButton.dataset.title || 'this record',
      );
    }
  });
}

/*
 * Scrolls the ticket's Changes section to this member's own diff entries and
 * briefly highlights them - read-only, so no server round trip needed, the data
 * is already in the DOM (see Ticket.html's `data-table`/`data-uid` on each
 * .editorialflow-diff item, stamped from WorkspaceIntegrationService::
 * getAggregatedMemberDiffs()).
 */
function jumpToDiff(table, uid, ticketRoot) {
  const scope = ticketRoot || topDocument();
  const target = scope.querySelector(`.editorialflow-diff[data-table="${table}"][data-uid="${uid}"]`);
  if (target === null) {
    return;
  }
  target.scrollIntoView({ behavior: 'smooth', block: 'start' });
  target.classList.add('is-highlighted');
  window.setTimeout(() => target.classList.remove('is-highlighted'), 2000);
}

async function previewMember(table, uid) {
  try {
    const response = await new AjaxRequest(TYPO3.settings.ajaxUrls.editorialflow_task_preview_member)
      .post({ table, uid });
    const result = await response.resolve();

    if (result.success !== true || !result.url) {
      Notification.error('Editorial Flow', result.message || 'Could not build a preview link.');
      return;
    }
    window.open(result.url, 'editorialflow_preview');
  } catch (error) {
    Notification.error('Editorial Flow', 'Could not reach the server.');
  }
}

async function discardMember(table, uid, title) {
  const confirmed = await confirmModal(
    'Discard version',
    'Throw away the pending changes to "' + title + '"? This cannot be undone.',
    SeverityEnum.warning,
  );
  if (!confirmed) {
    return;
  }

  try {
    const response = await new AjaxRequest(TYPO3.settings.ajaxUrls.editorialflow_task_discard_member)
      .post({ table, uid });
    const result = await response.resolve();

    if (result.success !== true) {
      Notification.error('Editorial Flow', result.message || 'Could not discard that version.');
      return;
    }
    Notification.success('Editorial Flow', '"' + title + '" discarded.');
    window.location.reload();
  } catch (error) {
    Notification.error('Editorial Flow', 'Could not reach the server.');
  }
}
