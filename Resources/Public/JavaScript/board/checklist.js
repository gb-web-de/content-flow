/*
 * Manage a stage's review checklist - owner-gated, so the gear button only
 * exists in the DOM for columns the server already decided the current editor
 * may configure (BoardColumnRegistry::canManageChecklist).
 */
import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import Notification from '@typo3/backend/notification.js';
import Modal from '@typo3/backend/modal.js';
import { SeverityEnum } from '@typo3/backend/enum/severity.js';
import { topDocument } from '@gb-web/content-flow/dom-scope.js';

export function registerChecklistManagement() {
  // The gear button lives on the board itself (this script's own document), not
  // inside a modal - no top-document delegation needed here.
  document.addEventListener('click', async (event) => {
    const button = event.target.closest('.contentflow-column-checklist-manage');
    if (button === null) {
      return;
    }
    const column = button.closest('.contentflow-column');
    if (column === null) {
      return;
    }
    openManageModal(column);
  });
}

/*
 * Checking an item off in the ticket - delegated from the TOP document, same
 * reasoning as task/comment.js: the ticket's checklist arrives with a modal that
 * TYPO3.Modal renders into the top-level backend document, not this script's own
 * (see dom-scope.js).
 */
export function registerChecklistToggle() {
  topDocument().addEventListener('change', async (event) => {
    const checkbox = event.target.closest('[data-contentflow-checklist-toggle]');
    if (checkbox === null) {
      return;
    }

    const taskUid = parseInt(checkbox.dataset.contentflowChecklistToggle, 10);
    const itemUid = parseInt(checkbox.dataset.itemUid, 10);
    const completed = checkbox.checked;
    const previous = !completed;
    checkbox.disabled = true;

    try {
      const response = await new AjaxRequest(TYPO3.settings.ajaxUrls.contentflow_checklist_toggle)
        .post({ task: taskUid, itemUid, completed });
      const result = await response.resolve();
      if (result.success !== true) {
        Notification.error('Content Flow', result.message || 'Could not update the checklist.');
        checkbox.checked = previous;
        return;
      }
      checkbox.closest('.contentflow-checklist-item')?.classList.toggle('is-completed', completed);
    } catch (error) {
      Notification.error('Content Flow', 'Could not reach the server.');
      checkbox.checked = previous;
    } finally {
      checkbox.disabled = false;
    }
  });
}

function openManageModal(column) {
  const workspaceUid = parseInt(column.dataset.contentflowWorkspace || '0', 10);
  const stageUid = parseInt(column.dataset.contentflowStage || '0', 10);
  let items = [];
  try {
    items = JSON.parse(column.dataset.contentflowChecklistItems || '[]');
  } catch {
    items = [];
  }

  const content = document.createElement('div');
  content.className = 'contentflow-checklist-manage';

  const list = document.createElement('ul');
  list.className = 'contentflow-checklist-manage-list';
  content.appendChild(list);

  const renderItems = () => {
    list.innerHTML = '';
    if (items.length === 0) {
      const empty = document.createElement('li');
      empty.className = 'contentflow-empty';
      empty.textContent = 'No checklist items yet.';
      list.appendChild(empty);
      return;
    }
    items.forEach((item) => {
      const row = document.createElement('li');
      const title = document.createElement('span');
      title.textContent = item.title;
      row.appendChild(title);

      const removeButton = document.createElement('button');
      removeButton.type = 'button';
      removeButton.className = 'btn btn-xs btn-default';
      removeButton.textContent = 'Remove';
      removeButton.addEventListener('click', async () => {
        const response = await new AjaxRequest(TYPO3.settings.ajaxUrls.contentflow_checklist_remove)
          .post({ workspaceUid, itemUid: item.uid });
        const result = await response.resolve();
        if (result.success !== true) {
          Notification.error('Content Flow', result.message || 'Could not remove that checklist item.');
          return;
        }
        items = items.filter((candidate) => candidate.uid !== item.uid);
        renderItems();
      });
      row.appendChild(removeButton);
      list.appendChild(row);
    });
  };
  renderItems();

  const form = document.createElement('form');
  form.className = 'contentflow-checklist-manage-add';
  const input = document.createElement('input');
  input.type = 'text';
  input.className = 'form-control';
  input.placeholder = 'New checklist item';
  const submit = document.createElement('button');
  submit.type = 'submit';
  submit.className = 'btn btn-primary btn-sm';
  submit.textContent = 'Add';
  form.appendChild(input);
  form.appendChild(submit);
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const title = input.value.trim();
    if (title === '') {
      return;
    }
    const response = await new AjaxRequest(TYPO3.settings.ajaxUrls.contentflow_checklist_add)
      .post({ workspaceUid, stageUid, title });
    const result = await response.resolve();
    if (result.success !== true) {
      Notification.error('Content Flow', result.message || 'Could not add that checklist item.');
      return;
    }
    items.push(result.item);
    input.value = '';
    renderItems();
  });
  content.appendChild(form);

  Modal.advanced({
    title: 'Manage review checklist',
    content,
    severity: SeverityEnum.notice,
    buttons: [
      {
        text: 'Done',
        btnClass: 'btn-default',
        name: 'close',
        trigger: (event, modal) => modal.hideModal(),
      },
    ],
    callback: () => {
      // Reload once the modal closes so the board's own copy of each column's
      // checklist (used by the incomplete-items warning) is fresh.
      window.location.reload();
    },
  });
}
