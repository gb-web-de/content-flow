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
import labels from '~labels/content_flow.messages';

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

/*
 * Core's own "Editing" stage (StagesService::STAGE_EDIT_ID), the one a record
 * sits in as soon as a workspace version exists.
 */
const EDITING_STAGE_UID = 0;

/*
 * A ticket planned with "Neue Seite erstellen" carries no subject yet, which
 * the card spells as `pages:0` (Index.html's data-contentflow-record).
 */
const PENDING_PAGE_RECORD = 'pages:0';

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
        // Editing is where any planned task starts. Pending subjects open core's
        // creation wizard; existing subjects become the active edit context.
        return targetStage === EDITING_STAGE_UID;
      }
      return currentStage !== targetStage;
    }

    if (currentWorkspaceUid > 0) {
      return false;
    }

    return currentState !== targetState;
  }

  isPendingPageCard(card) {
    return (card?.dataset.contentflowRecord || '') === PENDING_PAGE_RECORD;
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
      return 'This planned task has to enter Editing before it can move to a review stage.';
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
      // A ticket that has no page yet cannot change a stage - there is nothing
      // versioned to move. Its drop into Editing means "create the page now",
      // and the server answers that with the page wizard rather than a
      // transition (TaskAjaxController::requestPageWizard()).
      const currentWorkspaceUid = parseInt(card?.dataset.contentflowWorkspace || '0', 10);
      if (this.isPendingPageCard(card) || (currentWorkspaceUid < 1 && targetStageUid === EDITING_STAGE_UID)) {
        await this.moveTaskToColumn(taskUid, targetState, targetStageUid, columnTitle, cardTitle);
        return;
      }

      await this.openStageTransitionModal(
        taskUid,
        targetStageUid,
        columnTitle,
        cardTitle,
        card?.dataset.contentflowActive === 'true' && targetStageUid !== EDITING_STAGE_UID,
      );
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

      // The move is not finished server-side: this ticket has no page yet, and
      // TYPO3's own wizard is where one gets created. The ticket reaches
      // Editing when the page does (PendingPageClaimService).
      if (result.requiresPageWizard === true) {
        await this.openPageWizard(result, cardTitle);
        return;
      }

      if (result.requiresRecordTarget === true) {
        await this.openRecordTargetModal(result, cardTitle);
        return;
      }

      if (result.startedEditing === true && typeof result.redirectUrl === 'string' && result.redirectUrl !== '') {
        window.location.href = result.redirectUrl;
        return;
      }

      this.announce(`Moved ${cardTitle} to ${columnTitle}.`);
      Notification.success('Content Flow', `${cardTitle} moved to ${columnTitle}.`);
      window.location.reload();
    } catch (error) {
      Notification.error('Content Flow', await this.extractErrorMessage(error, 'Could not move the task.'));
    }
  }

  /*
   * TYPO3's own page-creation dialog, the same one the page tree opens - not a
   * Content Flow rebuild of it. Core owns the position step, the page-type step
   * and whatever fields that type requires, and its provider creates the page;
   * this extension only says where to start and then waits for the DataHandler
   * hook to link the result to the ticket.
   *
   * The import goes to the top frame for the same reason the stage dialog's
   * does: the modal is built in the parent's realm, so <typo3-backend-page-
   * wizard> has to be defined there, not in this iframe.
   */
  async openPageWizard(result, cardTitle) {
    try {
      await topLevelModuleImport('@typo3/backend/page-wizard/page-wizard.js');
      const { openPageWizardModal } = await import('@typo3/backend/page-wizard/helper/wizard-helper.js');

      await openPageWizardModal({ positionData: result.positionData });
      this.announce(`Creating the page for ${cardTitle}.`);
      this.dropPageWizardClaimWhenClosed();
    } catch (error) {
      Notification.error('Content Flow', 'Could not open TYPO3\'s page wizard.');
      await this.cancelPageWizard();
    }
  }

  async openRecordTargetModal(result, cardTitle) {
    try {
      const targetUrl = TYPO3.settings.ajaxUrls.contentflow_task_record_creation_targets;
      const startUrl = TYPO3.settings.ajaxUrls.contentflow_task_start_record_creation;
      if (!targetUrl || !startUrl) {
        Notification.error('Content Flow', labels.get('recordTarget.error.unavailable'));
        return;
      }

      const targets = await this.postJson(targetUrl, { task: result.taskUid });
      if (targets.success !== true) {
        Notification.error('Content Flow', targets.message || labels.get('recordTarget.error.load'));
        return;
      }
      if (!Array.isArray(targets.pages) || targets.pages.length === 0) {
        Notification.warning('Content Flow', labels.get('recordTarget.empty'));
        return;
      }

      const content = document.createElement('div');
      const description = document.createElement('p');
      description.textContent = labels.get('recordTarget.description', [targets.recordTypeLabel || targets.recordTable]);
      const label = document.createElement('label');
      label.className = 'form-label';
      label.htmlFor = 'contentflow-record-target-page';
      label.textContent = labels.get('recordTarget.page');
      const select = document.createElement('select');
      select.id = 'contentflow-record-target-page';
      select.className = 'form-select';
      targets.pages.forEach((page) => {
        const option = document.createElement('option');
        option.value = String(page.uid);
        option.textContent = page.path || page.title;
        select.append(option);
      });
      content.append(description, label, select);

      Modal.advanced({
        type: Modal.types.default,
        title: labels.get('recordTarget.title', [cardTitle]),
        content,
        severity: SeverityEnum.notice,
        buttons: [
          {
            text: labels.get('entry.cancel'),
            btnClass: 'btn-default',
            name: 'cancel',
            trigger: (event, modal) => modal.hideModal(),
          },
          {
            text: labels.get('recordTarget.start'),
            btnClass: 'btn-primary',
            name: 'start',
            trigger: async (event, modal) => {
              event.target.disabled = true;
              try {
                const started = await this.postJson(startUrl, {
                  task: result.taskUid,
                  page: parseInt(select.value, 10),
                });
                if (started.success !== true || !started.redirectUrl) {
                  Notification.error('Content Flow', started.message || labels.get('recordTarget.error.start'));
                  return;
                }
                modal.hideModal();
                window.location.href = started.redirectUrl;
              } catch (error) {
                Notification.error('Content Flow', await this.extractErrorMessage(error, labels.get('recordTarget.error.start')));
              } finally {
                event.target.disabled = false;
              }
            },
          },
        ],
      });
    } catch (error) {
      Notification.error('Content Flow', await this.extractErrorMessage(error, labels.get('recordTarget.error.load')));
    }
  }

  /*
   * Core's wizard redirects to the new page on success, so a modal that merely
   * closes almost always means "cancelled". Telling the server so keeps the
   * ticket from adopting whatever page the editor creates next; if a page WAS
   * created, the claim is already spent and this call changes nothing.
   */
  dropPageWizardClaimWhenClosed() {
    const modal = top.document.querySelector('typo3-backend-modal');
    if (!modal) {
      return;
    }
    modal.addEventListener('typo3-modal-hidden', () => this.cancelPageWizard(), { once: true });
  }

  async cancelPageWizard() {
    const url = TYPO3.settings.ajaxUrls.contentflow_task_cancel_page_wizard;
    if (!url) {
      return;
    }
    try {
      await this.postJson(url, {});
    } catch (error) {
      // The claim expires on its own - see PendingPageHandoff.
    }
  }

  async openStageTransitionModal(taskUid, targetStageUid, columnTitle, cardTitle, askToDeactivate = false) {
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
      const appendChoice = () => {
        const form = modal.querySelector('form');
        if (askToDeactivate && form !== null && form.querySelector('[name="deactivateActiveTask"]') === null) {
          this.appendActiveTaskChoice(form);
        }
      };
      appendChoice();
      modal.addEventListener('typo3-modal-shown', appendChoice, { once: true });
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
            deactivateActiveTask: dialogValues.deactivateActiveTask,
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
      deactivateActiveTask: formData.get('deactivateActiveTask') === '1',
    };
  }

  appendActiveTaskChoice(form) {
    const section = form.ownerDocument.createElement('fieldset');
    section.className = 'contentflow-stage-active-choice';

    const wrapper = form.ownerDocument.createElement('div');
    wrapper.className = 'form-check';
    const checkbox = form.ownerDocument.createElement('input');
    checkbox.type = 'checkbox';
    checkbox.className = 'form-check-input';
    checkbox.id = 'contentflow-deactivate-active-task';
    checkbox.name = 'deactivateActiveTask';
    checkbox.value = '1';
    checkbox.checked = true;
    const label = form.ownerDocument.createElement('label');
    label.className = 'form-check-label';
    label.htmlFor = checkbox.id;
    label.textContent = labels.get('stage.deactivate.label');
    wrapper.append(checkbox, label);

    const hint = form.ownerDocument.createElement('div');
    hint.className = 'form-text';
    hint.textContent = labels.get('stage.deactivate.hint');
    section.append(wrapper, hint);
    form.append(section);
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
