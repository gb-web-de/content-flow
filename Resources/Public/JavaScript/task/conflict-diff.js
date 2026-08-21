/*
 * "Compare versions": opens the workspace-vs-workspace diff for a record that
 * has a pending version in more than one workspace at once (see
 * WorkspaceConflictDetector on the PHP side).
 *
 * Delegated from both documents, same reasoning as membership.js: the button
 * appears in the page module's element badge (this script's own iframe
 * document) and inside the ticket modal's member list (rendered into the top
 * backend document by core's Modal.advanced()), which this module's own
 * `document` never sees.
 */
import Modal from '@typo3/backend/modal.js';
import Notification from '@typo3/backend/notification.js';
import { SeverityEnum } from '@typo3/backend/enum/severity.js';
import { topDocument } from '@gb-web/editorial-flow/dom-scope.js';

function openConflictDiff(table, uid, title) {
  const url = TYPO3.settings.ajaxUrls.editorialflow_task_conflict_diff;
  if (!url) {
    Notification.error('Editorial Flow', 'Workspace comparison is not available.');
    return;
  }

  Modal.advanced({
    type: Modal.types.ajax,
    title: title || 'Compare versions',
    content: url + '&table=' + encodeURIComponent(table) + '&uid=' + encodeURIComponent(uid),
    size: Modal.sizes.large,
    severity: SeverityEnum.warning,
  });
}

export function registerConflictDiffButtons() {
  const handler = (event) => {
    const target = event.target;
    if (typeof target?.closest !== 'function') {
      return;
    }

    const button = target.closest('[data-editorialflow-open-conflict-diff]');
    if (button === null) {
      return;
    }

    event.preventDefault();
    // Badge/card/banner buttons all sit inside a clickable card or content
    // element preview - opening the diff must not also select or open those.
    event.stopPropagation();

    const [table, uid] = button.dataset.editorialflowOpenConflictDiff.split(':');
    openConflictDiff(table, parseInt(uid, 10), button.dataset.editorialflowConflictTitle || '');
  };

  document.addEventListener('click', handler);
  const top = topDocument();
  if (top !== document) {
    top.addEventListener('click', handler);
  }
}
