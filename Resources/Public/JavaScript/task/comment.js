/*
 * Posting a comment from the ticket view.
 *
 * The form lives inside the ticket modal, which is loaded by ajax after the page
 * is ready - so the handler is delegated rather than bound to an element that does
 * not exist yet. Delegated from the TOP document specifically: TYPO3.Modal renders
 * the ticket into the top-level backend document, not this script's own (it runs
 * inside the board's content iframe) - see dom-scope.js.
 */
import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import Notification from '@typo3/backend/notification.js';
import { topDocument } from '@gb-web/editorial-flow/dom-scope.js';

export function registerCommentForm() {
  topDocument().addEventListener('submit', async (event) => {
    const form = event.target.closest('[data-editorialflow-comment-form]');
    if (form === null) {
      return;
    }
    event.preventDefault();

    const taskUid = parseInt(form.dataset.editorialflowCommentForm, 10);
    const textarea = form.querySelector('textarea');
    const content = textarea.value.trim();
    if (content === '') {
      Notification.warning('Editorial Flow', 'The comment is empty.');
      textarea.focus();
      return;
    }

    const button = form.querySelector('button[type="submit"]');
    button.disabled = true;

    try {
      const response = await new AjaxRequest(TYPO3.settings.ajaxUrls.editorialflow_task_comment)
        .post({ task: taskUid, content });
      const result = await response.resolve();

      if (result.success !== true) {
        Notification.error('Editorial Flow', result.message || 'Could not post the comment.');
        button.disabled = false;
        return;
      }

      Notification.success('Editorial Flow', 'Comment posted.');
      // Reload so the comment appears in the timeline in its correct
      // chronological place, rather than being appended client-side and
      // disagreeing with the server on ordering.
      window.location.reload();
    } catch (error) {
      Notification.error('Editorial Flow', 'Could not reach the server.');
      button.disabled = false;
    }
  });
}
