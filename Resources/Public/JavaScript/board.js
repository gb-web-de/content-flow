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
    this.registerDragAndDrop();
    this.registerCreateButton();
    this.registerAssignButtons();
  }

  registerAssignButtons() {
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

  registerDragAndDrop() {
    let draggedCard = null;

    this.board.querySelectorAll('.contentflow-card').forEach((card) => {
      card.addEventListener('dragstart', (e) => {
        draggedCard = card;
        card.classList.add('is-dragged');
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', card.dataset.contentflowTask);
      });

      card.addEventListener('dragend', () => {
        if (draggedCard) {
          draggedCard.classList.remove('is-dragged');
          draggedCard = null;
        }
        this.board.querySelectorAll('.contentflow-column').forEach((col) => col.classList.remove('is-drop-target'));
      });
    });

    this.board.querySelectorAll('.contentflow-column').forEach((column) => {
      column.addEventListener('dragover', (e) => {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        column.classList.add('is-drop-target');
      });

      column.addEventListener('dragleave', (e) => {
        if (!column.contains(e.relatedTarget)) {
          column.classList.remove('is-drop-target');
        }
      });

      column.addEventListener('drop', async (e) => {
        e.preventDefault();
        column.classList.remove('is-drop-target');

        const taskUid = e.dataTransfer.getData('text/plain') || (draggedCard ? draggedCard.dataset.contentflowTask : null);
        if (!taskUid) return;

        const targetState = column.dataset.contentflowState;
        const targetStageUid = parseInt(column.dataset.contentflowStage || '0', 10);

        try {
          const response = await new AjaxRequest(TYPO3.settings.ajaxUrls.contentflow_task_move_stage)
            .post({
              task: parseInt(taskUid, 10),
              state: targetState,
              stageUid: targetStageUid,
            });
          const result = await response.resolve();
          if (result.success) {
            this.announce('Task moved to column ' + (column.querySelector('.contentflow-column-title')?.textContent || ''));
            Notification.success('Content Flow', 'Task updated.');
            window.location.reload();
          } else {
            Notification.error('Content Flow', result.message || 'Could not move task.');
          }
        } catch (error) {
          Notification.error('Content Flow', 'Could not reach server to move task.');
        }
      });
    });
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
    document.querySelectorAll('[data-contentflow-action="create-task"]').forEach((button) => {
      button.addEventListener('click', (event) => {
        event.preventDefault();
        const urlParams = new URLSearchParams(window.location.search);
        const pageId = parseInt(urlParams.get('id') || '0', 10);

        // If clicked from page module with an active page ID, create directly for this page
        if (pageId > 0 && button.closest('.contentflow-page-banner')) {
          this.createTask('pages', pageId);
        } else {
          this.openPagePicker();
        }
      });
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
      // Click opens Task Inspector & Workspace Modal
      card.addEventListener('click', (e) => {
        if (e.target.closest('.contentflow-action-assign')) return;
        const taskUid = card.dataset.contentflowTask;
        if (taskUid) {
          this.openTaskInspectorModal(parseInt(taskUid, 10));
        }
      });
      card.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' || event.key === ' ') {
          event.preventDefault();
          const taskUid = card.dataset.contentflowTask;
          if (taskUid) {
            this.openTaskInspectorModal(parseInt(taskUid, 10));
          }
        }
      });
    });
  }

  registerDragAndDrop() {
    let draggedCard = null;

    this.board.querySelectorAll('.contentflow-card').forEach((card) => {
      card.addEventListener('dragstart', (e) => {
        draggedCard = card;
        card.classList.add('is-dragged');
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', card.dataset.contentflowTask);
      });

      card.addEventListener('dragend', () => {
        if (draggedCard) {
          draggedCard.classList.remove('is-dragged');
          draggedCard = null;
        }
        this.board.querySelectorAll('.contentflow-column').forEach((col) => col.classList.remove('is-drop-target'));
      });
    });

    this.board.querySelectorAll('.contentflow-column').forEach((column) => {
      column.addEventListener('dragover', (e) => {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        column.classList.add('is-drop-target');
      });

      column.addEventListener('dragleave', (e) => {
        if (!column.contains(e.relatedTarget)) {
          column.classList.remove('is-drop-target');
        }
      });

      column.addEventListener('drop', async (e) => {
        e.preventDefault();
        column.classList.remove('is-drop-target');

        const taskUid = e.dataTransfer.getData('text/plain') || (draggedCard ? draggedCard.dataset.contentflowTask : null);
        if (!taskUid) return;

        const targetState = column.dataset.contentflowState;
        const targetStageUid = parseInt(column.dataset.contentflowStage || '0', 10);
        const colTitle = column.querySelector('.contentflow-column-title')?.textContent || targetState;

        // Open Workspace Stage Confirmation Modal (comment & recipients)
        this.openStageTransitionModal(parseInt(taskUid, 10), targetState, targetStageUid, colTitle);
      });
    });
  }

  /**
   * Workspace Stage Transition Confirmation Dialog (Drop proposes, core stage popup confirms).
   */
  async openStageTransitionModal(taskUid, targetState, targetStageUid, columnTitle) {
    try {
      const response = await new AjaxRequest(TYPO3.settings.ajaxUrls.contentflow_task_details)
        .post({ task: taskUid });
      const result = await response.resolve();
      const recipients = result.recipients || [];

      const modalContainer = document.createElement('div');
      modalContainer.innerHTML = `
        <div style="padding: 16px;">
          <p>Move task <strong>${result.details?.task?.title || ''}</strong> to <strong>${columnTitle}</strong>?</p>

          <div style="margin-bottom: 16px;">
            <label style="font-weight: 600; display: block; margin-bottom: 6px;">Stage Comment / Note:</label>
            <textarea id="cf-stage-comment" class="form-control" rows="3" placeholder="Enter reason or note for this stage transition..."></textarea>
          </div>

          <div style="margin-bottom: 16px;">
            <label style="font-weight: 600; display: block; margin-bottom: 6px;">Notify Recipients (Workspace Users):</label>
            <div style="max-height: 120px; overflow-y: auto; border: 1px solid #ddd; padding: 8px; border-radius: 4px;">
              ${recipients.map(r => `
                <label style="display: block; font-size: 12px; margin-bottom: 4px;">
                  <input type="checkbox" name="cf-recipient" value="${r.uid}"> ${r.name} (${r.username})
                </label>
              `).join('')}
            </div>
          </div>

          <div style="text-align: right; display: flex; gap: 8px; justify-content: flex-end;">
            <button type="button" class="btn btn-default" id="cf-cancel-stage">Cancel</button>
            <button type="button" class="btn btn-primary" id="cf-confirm-stage">Confirm & Move</button>
          </div>
        </div>
      `;

      const modal = Modal.advanced({
        title: 'Workspace Stage Transition: ' + columnTitle,
        content: modalContainer,
        size: Modal.sizes.medium,
        severity: SeverityEnum.notice,
      });

      modalContainer.querySelector('#cf-cancel-stage').addEventListener('click', () => Modal.dismiss());
      modalContainer.querySelector('#cf-confirm-stage').addEventListener('click', async () => {
        const comment = modalContainer.querySelector('#cf-stage-comment').value;
        const selectedRecipients = Array.from(modalContainer.querySelectorAll('input[name="cf-recipient"]:checked')).map(cb => parseInt(cb.value, 10));

        try {
          const execResp = await new AjaxRequest(TYPO3.settings.ajaxUrls.contentflow_task_execute_stage)
            .post({
              task: taskUid,
              state: targetState,
              stageUid: targetStageUid,
              comment: comment,
              recipients: selectedRecipients,
            });
          const execRes = await execResp.resolve();
          Modal.dismiss();
          if (execRes.success) {
            Notification.success('Content Flow', 'Task moved & stage decision saved.');
            window.location.reload();
          } else {
            Notification.error('Content Flow', execRes.message || 'Stage transition failed.');
          }
        } catch (err) {
          Notification.error('Content Flow', 'Error saving stage transition.');
        }
      });
    } catch (err) {
      Notification.error('Content Flow', 'Could not fetch stage details.');
    }
  }

  /**
   * Task Inspector & Workspace Details Modal with Diffs, Activity Log, and FormEngine edit launcher.
   */
  async openTaskInspectorModal(taskUid) {
    try {
      const response = await new AjaxRequest(TYPO3.settings.ajaxUrls.contentflow_task_details)
        .post({ task: taskUid });
      const result = await response.resolve();
      if (!result.success || !result.details) {
        Notification.error('Content Flow', 'Could not load task details.');
        return;
      }

      const details = result.details;
      const task = details.task;
      const diffs = details.diffs || [];
      const activities = details.activities || [];
      const comments = details.comments || [];
      const editUrl = result.editUrl;

      const container = document.createElement('div');
      container.innerHTML = `
        <div style="padding: 16px;">
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
            <div>
              <span class="badge badge-info">${task.state}</span>
              <span style="font-size: 12px; color: #666; margin-left: 8px;">Record: ${task.subject_table}:${task.subject_uid}</span>
            </div>
            <div>
              <button type="button" class="btn btn-sm btn-primary" id="cf-edit-record">
                Edit Record in FormEngine
              </button>
            </div>
          </div>

          <div class="contentflow-modal-nav">
            <button type="button" class="active" data-tab="diffs">Field Diffs (${diffs.length})</button>
            <button type="button" data-tab="activity">Activity & Comments (${activities.length + comments.length})</button>
            <button type="button" data-tab="members">Members (${details.members?.length || 0})</button>
          </div>

          <div id="cf-tab-diffs" class="cf-tab-content">
            ${diffs.length === 0 ? '<p style="font-size: 12px; opacity: 0.7;">No recent field changes found in history.</p>' : `
              <table class="contentflow-diff-table">
                <thead>
                  <tr>
                    <th>Field</th>
                    <th>Previous Value</th>
                    <th>New Value</th>
                    <th>User</th>
                    <th>Date</th>
                  </tr>
                </thead>
                <tbody>
                  ${diffs.map(d => `
                    <tr>
                      <td><strong>${d.label}</strong></td>
                      <td><span class="contentflow-diff-old">${d.old || '<em>empty</em>'}</span></td>
                      <td><span class="contentflow-diff-new">${d.new || '<em>empty</em>'}</span></td>
                      <td>${d.user}</td>
                      <td>${new Date(d.tstamp * 1000).toLocaleString()}</td>
                    </tr>
                  `).join('')}
                </tbody>
              </table>
            `}
          </div>

          <div id="cf-tab-activity" class="cf-tab-content" style="display: none;">
            <ul class="contentflow-timeline">
              ${activities.map(a => `
                <li class="contentflow-timeline-item">
                  <strong>${a.event}</strong> by User #${a.be_user}
                  <div style="font-size: 11px; opacity: 0.7;">${new Date(a.crdate * 1000).toLocaleString()}</div>
                </li>
              `).join('')}
              ${comments.map(c => `
                <li class="contentflow-timeline-item" style="border-left-color: var(--contentflow-accent);">
                  <strong>Comment:</strong> ${c.content}
                  <div style="font-size: 11px; opacity: 0.7;">${new Date(c.crdate * 1000).toLocaleString()}</div>
                </li>
              `).join('')}
            </ul>
          </div>

          <div id="cf-tab-members" class="cf-tab-content" style="display: none;">
            <ul style="list-style: none; padding: 0; font-size: 12px;">
              ${(details.members || []).map(m => `
                <li style="padding: 6px 0; border-bottom: 1px solid #eee; display: flex; justify-content: space-between;">
                  <span>${m.record_table}:${m.record_uid} (${m.origin})</span>
                  <button type="button" class="btn btn-xs btn-default cf-detach-btn" data-table="${m.record_table}" data-uid="${m.record_uid}">Detach</button>
                </li>
              `).join('')}
            </ul>
          </div>
        </div>
      `;

      // Tab switching
      container.querySelectorAll('.contentflow-modal-nav button').forEach(btn => {
        btn.addEventListener('click', () => {
          container.querySelectorAll('.contentflow-modal-nav button').forEach(b => b.classList.remove('active'));
          container.querySelectorAll('.cf-tab-content').forEach(tc => tc.style.display = 'none');
          btn.classList.add('active');
          container.querySelector('#cf-tab-' + btn.dataset.tab).style.display = 'block';
        });
      });

      // Edit in FormEngine
      container.querySelector('#cf-edit-record').addEventListener('click', () => {
        Modal.dismiss();
        Modal.advanced({
          type: Modal.types.iframe,
          title: 'Edit Record: ' + task.title,
          content: editUrl,
          size: Modal.sizes.large,
          severity: SeverityEnum.notice,
        });
      });

      // Detach handler
      container.querySelectorAll('.cf-detach-btn').forEach(btn => {
        btn.addEventListener('click', () => {
          Modal.dismiss();
          this.splitFromTask(btn.dataset.table, parseInt(btn.dataset.uid, 10), task.title);
        });
      });

      Modal.advanced({
        title: 'Task & Workspace Inspector: ' + task.title,
        content: container,
        size: Modal.sizes.large,
        severity: SeverityEnum.notice,
      });
    } catch (err) {
      Notification.error('Content Flow', 'Could not open task details modal.');
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
