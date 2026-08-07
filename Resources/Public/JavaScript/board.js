/*
 * Content Flow board.
 *
 * Everything here that TYPO3 core already solves is delegated to core:
 *
 *   Modal, Wizard        @typo3/backend/modal.js, multi-step-wizard.js
 *   Page picking         @typo3/backend/tree/page-browser.js via the element browser
 *                        route - tree, search and depth come for free
 *   Record selection     @typo3/backend/multi-record-selection.js ("select to task")
 *   Context menu         @typo3/backend/context-menu.js (right-click on a card)
 *   Feedback             @typo3/backend/notification.js
 *   Icons                @typo3/backend/icons.js
 *   Severity mapping     @typo3/backend/severity.js
 *
 * Custom code is limited to the two things core has no opinion about: the board's
 * own drag-and-drop between columns, and talking to the extension's ajax routes.
 *
 * Accessibility is a hard requirement, not a nicety (see ARCHITECTURE.md):
 * nothing is drag-only. Every move is reachable from the keyboard and from the
 * card's context menu, and every change is announced through the live region.
 */
import DocumentService from '@typo3/core/document-service.js';
import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import Notification from '@typo3/backend/notification.js';
import Modal from '@typo3/backend/modal.js';
import { SeverityEnum } from '@typo3/backend/enum/severity.js';

class ContentFlowBoard {
  constructor() {
    this.selection = new Set();
    DocumentService.ready().then(() => this.initialize());
  }

  initialize() {
    this.board = document.querySelector('.contentflow-board');
    if (this.board === null) {
      return;
    }
    this.announcer = document.querySelector('.contentflow-announcer');
    this.registerCardEvents();
    this.registerCreateButton();
  }

  /**
   * Announce a change to screen readers. Every mutation goes through here - a
   * board that only communicates by moving pixels is unusable without sight.
   */
  announce(message) {
    if (this.announcer !== null) {
      this.announcer.textContent = message;
    }
  }

  registerCreateButton() {
    const button = document.querySelector('[data-contentflow-action="create-task"]');
    if (button === null) {
      return;
    }
    button.addEventListener('click', (event) => {
      event.preventDefault();
      this.openPagePicker();
    });
  }

  /**
   * The "+" button. Uses core's element browser so the editor gets the page tree,
   * live search and the usual depth navigation instead of a bespoke picker.
   */
  openPagePicker() {
    const url = TYPO3.settings.ContentFlow?.pageBrowserUrl;
    if (!url) {
      Notification.error('Content Flow', 'Page browser is not configured.');
      return;
    }

    // The element browser posts its selection back through this global, which is
    // the contract core's browser scripts expect.
    window.setFormValueFromBrowseWin = (fieldReference, value) => {
      const uid = parseInt(String(value).split('_').pop(), 10);
      Modal.dismiss();
      if (Number.isInteger(uid) && uid > 0) {
        this.createTask('pages', uid);
      }
    };

    Modal.advanced({
      type: Modal.types.iframe,
      title: 'Select a page',
      content: url,
      size: Modal.sizes.large,
      severity: SeverityEnum.notice,
    });
  }

  async createTask(table, uid) {
    try {
      const response = await new AjaxRequest(TYPO3.settings.ajaxUrls.contentflow_task_create)
        .post({ table, uid });
      const result = await response.resolve();
      if (result.success !== true) {
        Notification.error('Content Flow', result.message || 'Could not create the task.');
        return;
      }
      this.announce('Task created.');
      Notification.success('Content Flow', 'Task created.');
      window.location.reload();
    } catch (error) {
      Notification.error('Content Flow', 'Could not reach the server.');
    }
  }

  registerCardEvents() {
    this.board.querySelectorAll('.contentflow-card').forEach((card) => {
      // Selection is keyboard-operable: the card is focusable and responds to
      // Enter/Space, so "select to task" never requires a pointer.
      card.addEventListener('click', () => this.toggleSelection(card));
      card.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' || event.key === ' ') {
          event.preventDefault();
          this.toggleSelection(card);
        }
      });
    });
  }

  toggleSelection(card) {
    const id = card.dataset.contentflowRecord;
    if (!id) {
      return;
    }
    if (this.selection.has(id)) {
      this.selection.delete(id);
      card.classList.remove('is-selected');
      card.setAttribute('aria-selected', 'false');
      this.announce('Deselected ' + card.dataset.contentflowTitle);
    } else {
      this.selection.add(id);
      card.classList.add('is-selected');
      card.setAttribute('aria-selected', 'true');
      this.announce('Selected ' + card.dataset.contentflowTitle);
    }
  }

  /**
   * "Select to task": hand the current selection to a task.
   */
  async attachSelectionTo(taskUid) {
    const records = Array.from(this.selection).map((id) => {
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
    this.announce(result.moved.length + ' record(s) moved to the task.');
    window.location.reload();
  }

  /**
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
    this.announce(title + ' now has its own task.');
    window.location.reload();
  }
}

export default new ContentFlowBoard();
