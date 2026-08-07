/*
 * Taking an unassigned task. "Up for grabs" only works if claiming is one click.
 */
import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import Notification from '@typo3/backend/notification.js';

export function registerAssignButtons(board) {
    document.querySelectorAll('.contentflow-action-assign').forEach((btn) => {
      btn.addEventListener('click', async (e) => {
        e.stopPropagation();
        const taskUid = btn.dataset.taskUid;
        if (!taskUid) return;
        try {
          const response = await new AjaxRequest(TYPO3.settings.ajaxUrls.contentflow_task_assign_me)
            .post({ task: parseInt(taskUid, 10) });
          const result = await response.resolve();
          if (result.success) {
            Notification.success('Content Flow', 'Task assigned to you.');
            window.location.reload();
          } else {
            Notification.error('Content Flow', result.message || 'Could not assign task.');
          }
        } catch (error) {
          Notification.error('Content Flow', 'Server error while assigning task.');
        }
      });
    });
  }
