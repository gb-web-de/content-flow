/*
 * Posting a comment from the ticket view.
 *
 * The form lives inside the ticket modal, which is loaded by ajax after the page
 * is ready - so the handler is delegated from the document rather than bound to
 * an element that does not exist yet.
 */
import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import Notification from '@typo3/backend/notification.js';

export function registerCommentForm() {
  document.addEventListener('submit', async (event) => {
    const form = event.target.closest('[data-contentflow-comment-form]');
    if (form === null) {
      return;
    }
    event.preventDefault();

    const taskUid = parseInt(form.dataset.contentflowCommentForm, 10);
    const textarea = form.querySelector('textarea');
    const content = textarea.value.trim();
    if (content === '') {
      Notification.warning('Content Flow', 'The comment is empty.');
      textarea.focus();
      return;
    }

    const button = form.querySelector('button[type="submit"]');
    button.disabled = true;

    try {
      const response = await new AjaxRequest(TYPO3.settings.ajaxUrls.contentflow_task_comment)
        .post({ task: taskUid, content });
      const result = await response.resolve();

      if (result.success !== true) {
        Notification.error('Content Flow', result.message || 'Could not post the comment.');
        button.disabled = false;
        return;
      }

      Notification.success('Content Flow', 'Comment posted.');
      // Reload so the comment appears in the timeline in its correct
      // chronological place, rather than being appended client-side and
      // disagreeing with the server on ordering.
      window.location.reload();
    } catch (error) {
      Notification.error('Content Flow', 'Could not reach the server.');
      button.disabled = false;
    }
  });
}
