/*
 * Publishing - the action behind the "Done" column's confirmation.
 *
 * Deliberately not a drop target (ARCHITECTURE.md: going live is irreversible,
 * so it is an explicit action, never something an editor can trigger by
 * dropping a card slightly off target). This is that explicit action.
 */
import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import Notification from '@typo3/backend/notification.js';
import { SeverityEnum } from '@typo3/backend/enum/severity.js';
import { confirmModal } from '@gb-web/content-flow/modal-confirm.js';

export function registerPublishButtons(board) {
  const canPublish = TYPO3.settings.ContentFlow?.canPublish === true;

  document.querySelectorAll('.contentflow-action-publish').forEach((btn) => {
    if (!canPublish) {
      // Greyed out, not hidden: an editor should see that publishing exists
      // and why it is unavailable to them, not wonder if the feature is
      // missing - the same "always icon and label, never silence" rule
      // ARCHITECTURE.md sets for status coloring applies to disabled actions.
      btn.disabled = true;
      btn.title = 'You are not allowed to publish in this workspace.';
      return;
    }

    btn.addEventListener('click', async (event) => {
      event.stopPropagation();
      const taskUid = parseInt(btn.dataset.taskUid || '0', 10);
      const taskTitle = btn.dataset.taskTitle || 'this task';
      if (!taskUid) {
        return;
      }

      const confirmed = await confirmModal(
        'Publish "' + taskTitle + '"',
        'This makes every pending change in this task live immediately. This cannot be undone. Continue?',
        SeverityEnum.warning,
      );
      if (!confirmed) {
        return;
      }

      try {
        const response = await new AjaxRequest(TYPO3.settings.ajaxUrls.contentflow_task_publish)
          .post({ task: taskUid });
        const result = await response.resolve();
        if (result.success !== true) {
          Notification.error('Content Flow', result.message || 'Could not publish that task.');
          return;
        }
        board?.announce(taskTitle + ' published.');
        Notification.success('Content Flow', taskTitle + ' published.' + (result.closed ? ' Task closed.' : ''));
        window.location.reload();
      } catch (error) {
        Notification.error('Content Flow', 'Server error while publishing.');
      }
    });
  });
}
