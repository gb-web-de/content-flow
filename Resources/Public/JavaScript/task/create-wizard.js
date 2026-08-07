/*
 * The "+ New task" flow.
 *
 * Two core APIs, each doing what it is good at, deliberately NOT nested because
 * core modals do not stack: the element browser picks the record (tree, live
 * search, page content listing), then MultiStepWizard collects title, priority
 * and assignment.
 */
import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import Notification from '@typo3/backend/notification.js';
import Modal from '@typo3/backend/modal.js';
import { SeverityEnum } from '@typo3/backend/enum/severity.js';

const ELEMENT_BROWSER_FIELD_REFERENCE_PREFIX = 'contentflow-create-target';

export function registerCreateButton(board) {
  document.querySelectorAll('[data-contentflow-action="create-task"]').forEach((button) => {
    button.addEventListener('click', (event) => {
      event.preventDefault();

      // On a page banner the page is already known; on the board the user can
      // choose any trackable record.
      const bannerPageId = parseInt(button.dataset.contentflowPage || '0', 10);
      if (bannerPageId > 0) {
        openNewTaskWizard(
          board,
          'pages',
          bannerPageId,
          button.dataset.contentflowPageTitle || ('Page ' + bannerPageId),
        );
        return;
      }

      openRecordPicker(board);
    });
  });
}

/**
 * The "+" button: pick a record, then fill in the details.
 *
 * Two core APIs, each doing what it is good at, and deliberately NOT nested:
 *   1. the element browser picks the record
 *   2. MultiStepWizard collects title, priority and assignment
 */
function openRecordPicker(board) {
  const baseUrl = TYPO3.settings.ContentFlow?.elementBrowserUrl;
  if (!baseUrl) {
    Notification.error('Content Flow', 'Element browser is not configured.');
    return;
  }

  const currentPageId = parseInt(TYPO3.settings.ContentFlow?.currentPageId || '0', 10);
  const allowedTypes = getAllowedCreateTables();
  const params = new URLSearchParams({
    mode: 'db',
    allowedTypes: allowedTypes.join(','),
    fieldReference: `${ELEMENT_BROWSER_FIELD_REFERENCE_PREFIX}-${Date.now()}`,
    useEvents: '1',
  });
  if (currentPageId > 0) {
    params.set('expandPage', String(currentPageId));
  }

  const modal = Modal.advanced({
    type: Modal.types.iframe,
    title: 'Select a page, content element or record',
    content: baseUrl + (baseUrl.includes('?') ? '&' : '?') + params.toString(),
    size: Modal.sizes.large,
    severity: SeverityEnum.notice,
  });

  modal.addEventListener('typo3:element-browser:message', (event) => {
    const { actionName, value, label } = event.detail;
    if (actionName !== 'typo3:elementBrowser:elementAdded') {
      return;
    }

    const record = parseSelectedRecord(value);
    if (record === null) {
      Notification.error('Content Flow', 'The selected record could not be identified.');
      return;
    }

    modal.hideModal();
    openNewTaskWizard(board, record.table, record.uid, label || formatRecordLabel(record.table, record.uid));
  });
}

/**
 * Details step, on core's MultiStepWizard.
 *
 * Leaving a task unassigned is offered as a first-class choice rather than as
 * an empty field: "somebody will pick this up" is a real plan, and the board
 * lists those separately.
 */
function openNewTaskWizard(board, table, uid, recordTitle) {
  const wizard = TYPO3.MultiStepWizard;
  if (!wizard) {
    void createTask(board, table, uid, { title: recordTitle });
    return;
  }

  wizard.set('table', table);
  wizard.set('uid', uid);
  wizard.set('title', recordTitle);
  wizard.set('priority', 2);
  wizard.set('assignee', 'me');

  wizard.addSlide('contentflow-details', 'New task', '', SeverityEnum.notice, 'Details', (slide, settings) => {
    const form = document.createElement('div');

    const titleField = document.createElement('div');
    titleField.className = 'form-group';
    const titleLabel = document.createElement('label');
    titleLabel.className = 'form-label';
    titleLabel.textContent = 'Title';
    const titleInput = document.createElement('input');
    titleInput.type = 'text';
    titleInput.className = 'form-control';
    titleInput.value = recordTitle;
    titleInput.addEventListener('input', () => {
      settings.title = titleInput.value;
    });
    titleField.append(titleLabel, titleInput);

    const priorityField = document.createElement('div');
    priorityField.className = 'form-group';
    const priorityLabel = document.createElement('label');
    priorityLabel.className = 'form-label';
    priorityLabel.textContent = 'Priority';
    const prioritySelect = document.createElement('select');
    prioritySelect.className = 'form-select form-control';
    [['1', 'High'], ['2', 'Normal'], ['3', 'Low']].forEach(([value, text]) => {
      const option = new Option(text, value, value === '2', value === '2');
      prioritySelect.add(option);
    });
    prioritySelect.addEventListener('change', () => {
      settings.priority = parseInt(prioritySelect.value, 10);
    });
    priorityField.append(priorityLabel, prioritySelect);

    const assigneeField = document.createElement('div');
    assigneeField.className = 'form-group';
    const assigneeLabel = document.createElement('label');
    assigneeLabel.className = 'form-label';
    assigneeLabel.textContent = 'Assignment';
    const assigneeSelect = document.createElement('select');
    assigneeSelect.className = 'form-select form-control';
    [['me', 'Assign to me'], ['open', 'Leave open for someone to take']].forEach(([value, text]) => {
      assigneeSelect.add(new Option(text, value, value === 'me', value === 'me'));
    });
    assigneeSelect.addEventListener('change', () => {
      settings.assignee = assigneeSelect.value;
    });
    assigneeField.append(assigneeLabel, assigneeSelect);

    form.append(titleField, priorityField, assigneeField);
    slide.html(form);
    wizard.unlockNextStep();
  });

  wizard.addFinalProcessingSlide(async () => {
    const settings = wizard.setup.settings;
    await createTask(board, settings.table, settings.uid, {
      title: settings.title,
      priority: settings.priority,
      assignee: settings.assignee,
    });
    wizard.dismiss();
  });

  wizard.show();
}

function getAllowedCreateTables() {
  const configuredTables = TYPO3.settings.ContentFlow?.createTargetTables;
  if (!Array.isArray(configuredTables) || configuredTables.length === 0) {
    return ['pages'];
  }

  const tables = configuredTables
    .map((table) => String(table).trim())
    .filter((table) => table !== '');

  return tables.length > 0 ? [...new Set(tables)] : ['pages'];
}

function parseSelectedRecord(value) {
  const match = String(value).match(/^(.*)_(\d+)$/);
  if (!match) {
    return null;
  }

  const uid = parseInt(match[2], 10);
  if (!Number.isInteger(uid) || uid < 1) {
    return null;
  }

  return {
    table: match[1],
    uid,
  };
}

function formatRecordLabel(table, uid) {
  return `${table}:${uid}`;
}

async function createTask(board, table, uid, details = {}) {
  const url = TYPO3.settings.ajaxUrls.contentflow_task_create;
  if (!url) {
    Notification.error('Content Flow', 'Task creation is not configured.');
    return;
  }

  try {
    const response = await new AjaxRequest(url).post({
      table,
      uid,
      ...details,
    });
    const result = await response.resolve();

    if (result.success !== true) {
      Notification.error('Content Flow', result.message || 'Could not create the task.');
      return;
    }

    Notification.success('Content Flow', 'Task created.');
    board.announce('Created task for ' + table + ':' + uid);
    window.location.reload();
  } catch (error) {
    Notification.error('Content Flow', 'Could not create the task.');
  }
}
