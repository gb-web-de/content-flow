/*
 * Content Flow board - entry point.
 *
 * This file only wires things together. Each behaviour lives in its own small
 * module under board/ and task/, so a reader looking for "how does splitting a
 * record work" opens task/membership.js instead of scrolling a 600-line class.
 *
 * Everything TYPO3 core already solves is delegated to core:
 *
 *   Modal, Wizard      @typo3/backend/modal.js, multi-step-wizard.js
 *   Page picking       the element browser route (tree, live search, depth)
 *   Feedback           @typo3/backend/notification.js
 *   Workspace dialog   @typo3/workspaces/workspaces.js
 *
 * Custom code is limited to what core has no opinion about: mapping tasks onto
 * columns, drag-and-drop validation, and calls to this extension's routes.
 */
import DocumentService from '@typo3/core/document-service.js';
import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import Notification from '@typo3/backend/notification.js';
import Modal from '@typo3/backend/modal.js';
import { SeverityEnum } from '@typo3/backend/enum/severity.js';
import Workspaces from '@typo3/workspaces/workspaces.js';
import { topLevelModuleImport } from '@typo3/backend/utility/top-level-module-import.js';

import { registerFilters } from '@gb-web/content-flow/board/filters.js';
import { registerScopeControls } from '@gb-web/content-flow/board/scope.js';
import { registerDragAndDrop } from '@gb-web/content-flow/board/drag-drop.js';
import { registerAssignButtons } from '@gb-web/content-flow/task/assign.js';
import { registerTicketButtons } from '@gb-web/content-flow/task/ticket.js';
import { registerCreateButton } from '@gb-web/content-flow/task/create-wizard.js';
import { registerCommentForm } from '@gb-web/content-flow/task/comment.js';
import { registerPublishButtons } from '@gb-web/content-flow/task/publish.js';
import { registerMemberActions } from '@gb-web/content-flow/task/member-actions.js';
import { registerChecklistManagement, registerChecklistToggle } from '@gb-web/content-flow/board/checklist.js';

class ContentFlowBoard {
  constructor() {
    this.selection = new Set();
    this.workspaceUi = new Workspaces();
    DocumentService.ready().then(() => this.initialize());
  }

  initialize() {
    this.announcer = document.querySelector('.contentflow-announcer');

    // Registered before the board check on purpose: these actions also appear in
    // the page module banner, where there is no board element at all.
    registerCreateButton(this);
    registerTicketButtons(this);
    registerAssignButtons(this);
    // Delegated from the document: the ticket form arrives with the modal.
    registerCommentForm();
    registerMemberActions();
    registerChecklistToggle();

    this.board = document.querySelector('.contentflow-board');
    if (this.board === null) {
      return;
    }

    this.registerCardEvents();
    registerDragAndDrop(this);
    registerFilters(this);
    registerScopeControls();
    registerPublishButtons(this);
    registerChecklistManagement();
  }

  /**
   * Announce a change to screen readers. Every mutation goes through here - a
   * board that only communicates by moving pixels is unusable without sight.
   */
  announce(message) {
    if (this.announcer instanceof HTMLElement) {
      this.announcer.textContent = message;
    }
  }

  registerCardEvents() {
    this.board.querySelectorAll('.contentflow-card').forEach((card) => {
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
    const wasSelected = this.selection.has(id);
    if (wasSelected) {
      this.selection.delete(id);
    } else {
      this.selection.add(id);
    }
    card.classList.toggle('is-selected', !wasSelected);
    card.setAttribute('aria-selected', wasSelected ? 'false' : 'true');
    this.announce((wasSelected ? 'Deselected ' : 'Selected ') + (card.dataset.contentflowTitle || 'task'));
  }

  canDropCardIntoColumn(card, column) {
    if (column.dataset.contentflowAcceptsDrop === 'false') {
      return false;
    }
    // Gates the DRAGGED card, not the target column: core's own stage
    // permission is about who may act on whatever currently sits in a stage,
    // never about who may move things into one (see WORKSPACE-STAGES.md).
    // ContentFlowController::buildBoard() stamps this from the card's own
    // current stage_uid.
    if (card.dataset.contentflowCanAct === 'false') {
      return false;
    }

    const currentState = card.dataset.contentflowState || '';
    const currentStage = this.parseStageUid(card.dataset.contentflowStage);
    const currentWorkspaceUid = parseInt(card.dataset.contentflowWorkspace || '0', 10);
    const targetState = column.dataset.contentflowState || '';
    const targetStage = this.parseStageUid(column.dataset.contentflowStage);

    if (targetStage !== null) {
      if (currentWorkspaceUid < 1) {
        return false;
      }
      return currentStage !== targetStage;
    }

    if (currentWorkspaceUid > 0) {
      return false;
    }

    return currentState !== targetState;
  }

  getDropRejectionMessage(card, column) {
    if (column.dataset.contentflowAcceptsDrop === 'false') {
      return 'This column does not accept manual card drops. Going live is an explicit action.';
    }
    if (card.dataset.contentflowCanAct === 'false') {
      return 'You are not responsible for the stage this task currently sits in, so you cannot move it. '
        + 'An administrator can add you (or your group) as a responsible person for that stage in the workspace settings.';
    }

    const currentState = card.dataset.contentflowState || '';
    const currentWorkspaceUid = parseInt(card.dataset.contentflowWorkspace || '0', 10);
    const targetState = column.dataset.contentflowState || '';
    const targetStage = this.parseStageUid(column.dataset.contentflowStage);

    if (targetStage !== null && currentWorkspaceUid < 1) {
      return 'This task has no workspace version yet. Edit it first so TYPO3 can create one.';
    }
    if (targetStage === null && currentWorkspaceUid > 0) {
      return 'This task already has a workspace version, so it cannot be moved back to a planning column.';
    }
    if (targetStage === null && currentState === targetState) {
      return 'This task is already in that column.';
    }
    if (targetStage !== null && this.parseStageUid(card.dataset.contentflowStage) === targetStage) {
      return 'This task is already in that review column.';
    }

    return 'This task cannot be dropped there.';
  }

  async handleCardDrop(taskUid, column, card = null) {
    const targetState = column.dataset.contentflowState || 'backlog';
    const targetStageUid = this.parseStageUid(column.dataset.contentflowStage);
    const columnTitle = column.querySelector('.contentflow-column-title')?.textContent?.trim() || targetState;
    const cardTitle = card?.dataset.contentflowTitle || 'Task';

    if (targetStageUid !== null) {
      await this.openStageTransitionModal(taskUid, targetStageUid, columnTitle, cardTitle);
      return;
    }

    await this.moveTaskToColumn(taskUid, targetState, 0, columnTitle, cardTitle);
  }

  async moveTaskToColumn(taskUid, targetState, targetStageUid, columnTitle, cardTitle) {
    const url = TYPO3.settings.ajaxUrls.contentflow_task_move_stage;
    if (!url) {
      Notification.error('Content Flow', 'Board move is not configured.');
      return;
    }

    try {
      const result = await this.postJson(url, {
        task: taskUid,
        state: targetState,
        stageUid: targetStageUid,
      });
      if (result.success !== true) {
        Notification.error('Content Flow', result.message || 'Could not move the task.');
        return;
      }

      this.announce(`Moved ${cardTitle} to ${columnTitle}.`);
      Notification.success('Content Flow', `${cardTitle} moved to ${columnTitle}.`);
      window.location.reload();
    } catch (error) {
      Notification.error('Content Flow', await this.extractErrorMessage(error, 'Could not move the task.'));
    }
  }

  async openStageTransitionModal(taskUid, targetStageUid, columnTitle, cardTitle) {
    try {
      // TYPO3.Modal is reused from the parent frame when this board runs inside
      // the backend's content iframe (see modal.js), so the dialog is built in
      // the parent's realm. A plain `import` here would only register
      // <typo3-workspaces-send-to-stage-form> in this iframe's realm and the
      // element would stay undefined where it actually renders. Core's own
      // iframe-hosted caller of this API (workspaces/backend.js) resolves this
      // the same way: dispatch the import to the parent frame and let it
      // register there.
      await topLevelModuleImport('@typo3/workspaces/renderable/send-to-stage-form.js');

      const response = await this.workspaceUi.sendRemoteRequest(
        this.workspaceUi.generateRemotePayloadBody('sendToSpecificStageWindow', [targetStageUid]),
        '.contentflow-board',
      );
      const payload = await response.resolve();

      if (payload?.[0]?.result?.success === false) {
        Notification.error('Content Flow', 'TYPO3 refused to open the workspace stage dialog.');
        return;
      }

      const modal = this.workspaceUi.renderSendToStageWindow(payload);
      modal.addEventListener('button.clicked', async (event) => {
        if (event.target.name !== 'ok') {
          return;
        }

        // Not `instanceof HTMLFormElement`: TYPO3.Modal is reused from the parent
        // frame when the board runs inside the backend content iframe (see
        // modal.js), so the dialog's nodes belong to the parent's realm and
        // would never match this frame's HTMLFormElement constructor.
        const form = modal.querySelector('form');
        if (form === null || form.tagName !== 'FORM') {
          Notification.error('Content Flow', 'The workspace stage dialog could not be rendered.');
          return;
        }

        if (modal.dataset.contentflowSubmitting === '1') {
          return;
        }

        modal.dataset.contentflowSubmitting = '1';
        const submitButton = modal.querySelector('button[name="ok"]');
        if (submitButton !== null) {
          submitButton.disabled = true;
        }

        try {
          const dialogValues = this.readStageTransitionForm(form);
          const result = await this.postJson(TYPO3.settings.ajaxUrls.contentflow_task_execute_stage, {
            task: taskUid,
            stageUid: targetStageUid,
            comment: dialogValues.comment,
            recipients: dialogValues.recipients,
            additional: dialogValues.additional,
          });
          if (result.success !== true) {
            Notification.error('Content Flow', result.message || 'Could not move the task to that stage.');
            return;
          }

          modal.hideModal();
          this.announce(`Moved ${cardTitle} to ${columnTitle}.`);
          Notification.success('Content Flow', `${cardTitle} moved to ${columnTitle}.`);
          // Soft warning, never a block: core already decided the move itself
          // is allowed, this only flags that the stage being left had unchecked
          // review items.
          if (result.incompleteChecklistItems > 0) {
            Notification.warning(
              'Content Flow',
              `${result.incompleteChecklistItems} checklist item(s) were left unchecked in the previous stage.`,
            );
          }
          window.location.reload();
        } catch (error) {
          Notification.error(
            'Content Flow',
            await this.extractErrorMessage(error, 'Could not move the task to that stage.'),
          );
        } finally {
          delete modal.dataset.contentflowSubmitting;
          if (submitButton !== null) {
            submitButton.disabled = false;
          }
        }
      });
    } catch (error) {
      Notification.error(
        'Content Flow',
        await this.extractErrorMessage(error, 'Could not open the TYPO3 workspace dialog.'),
      );
    }
  }

  openTicket(taskUid, title) {
    const url = TYPO3.settings.ajaxUrls.contentflow_task_ticket;
    if (!url) {
      Notification.error('Content Flow', 'Ticket view is not available.');
      return;
    }

    this.announce('Opened task ' + title);
    Modal.advanced({
      type: Modal.types.ajax,
      title,
      content: url + '&task=' + encodeURIComponent(taskUid),
      size: Modal.sizes.large,
      severity: SeverityEnum.notice,
    });
  }

  async postJson(url, payload) {
    const response = await new AjaxRequest(url).post(payload, {
      headers: {
        'Content-Type': 'application/json; charset=utf-8',
      },
    });

    return response.resolve();
  }

  async extractErrorMessage(error, fallback) {
    if (error && typeof error.resolve === 'function') {
      try {
        const payload = await error.resolve();
        if (payload?.message) {
          return payload.message;
        }
      } catch {
        // Ignore follow-up parse errors and keep the fallback below.
      }
    }

    return fallback;
  }

  readStageTransitionForm(form) {
    const formData = new FormData(form);
    const recipients = formData
      .getAll('recipients')
      .map((value) => parseInt(String(value), 10))
      .filter((value) => Number.isInteger(value) && value > 0);

    return {
      comment: String(formData.get('comments') || '').trim(),
      additional: String(formData.get('additional') || '').trim(),
      recipients,
    };
  }

  parseStageUid(rawValue) {
    if (rawValue === undefined || rawValue === null || rawValue === '') {
      return null;
    }

    const value = parseInt(rawValue, 10);
    return Number.isNaN(value) ? null : value;
  }
}

export default new ContentFlowBoard();
