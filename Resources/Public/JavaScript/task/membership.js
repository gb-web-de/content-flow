/*
 * Select to task, and split from task.
 *
 * Both rest on one invariant: a record belongs to at most one open task. So
 * attaching an already-claimed record moves it rather than duplicating it.
 */
import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import Notification from '@typo3/backend/notification.js';
import Modal from '@typo3/backend/modal.js';
import { SeverityEnum } from '@typo3/backend/enum/severity.js';

export /**
   * "Select to task": hand the current selection to a task.
   */
  async attachSelectionTo(taskUid) {
    const records = Array.from(board.selection).map((id) => {
      const [table, uid] = id.split(':');
      return { table, uid: parseInt(uid, 10) };
    });
    if (records.length === 0) {
      return;
    }

    const response = await new AjaxRequest(TYPO3.settings.ajaxUrls.contentflow_task_attach)
      .post({ task: taskUid, records });
    const result = await response.resolve();

    if (result.refused?.length) {
      Notification.warning('Content Flow', result.refused.length + ' record(s) could not be moved.');
    }
    board.announce(result.moved.length + ' record(s) moved to the task.');
    window.location.reload();
  }

export /**
   * "Split from task": pull one record out into a task of its own. Irreversible
   * enough to be worth a confirmation, and never a side effect of a drag.
   */
  async splitFromTask(table, uid, title) {
    const confirmed = await Modal.confirm(
      'Split from task',
      'Give "' + title + '" a task of its own?',
      SeverityEnum.warning,
    );
    if (!confirmed) {
      return;
    }

    const response = await new AjaxRequest(TYPO3.settings.ajaxUrls.contentflow_task_detach)
      .post({ table, uid });
    const result = await response.resolve();

    if (result.success !== true) {
      Notification.error('Content Flow', result.message || 'Could not split the record off.');
      return;
    }
    board.announce(title + ' now has its own task.');
    window.location.reload();
  }
