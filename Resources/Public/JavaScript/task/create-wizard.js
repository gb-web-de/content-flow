/*
 * The "+" flow: pick a record with core's element browser, then collect the
 * task details on top of TYPO3's supported MultiStepWizard.
 */
import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import Notification from '@typo3/backend/notification.js';
import Modal from '@typo3/backend/modal.js';
import MultiStepWizard from '@typo3/backend/multi-step-wizard.js';
import { SeverityEnum } from '@typo3/backend/enum/severity.js';

import { buildTaskDetailsForm } from '@gb-web/content-flow/task/task-details-form.js';

const ELEMENT_BROWSER_FIELD_REFERENCE_PREFIX = 'contentflow-create-target';

function updateTitleRequirement(wizard, titleInput) {
  if (titleInput.value.trim() === '') {
    wizard.lockNextStep();
    return;
  }

  wizard.unlockNextStep();
}

function addPriorityField(container, settings) {
  const priorityChoices = [
    ['1', 'High'],
    ['2', 'Normal'],
    ['3', 'Low'],
  ];
  const selectedPriority = Number.isInteger(settings.priority) ? String(settings.priority) : '2';

  const field = document.createElement('div');
  field.className = 'form-group';

  const label = document.createElement('label');
  label.className = 'form-label';
  label.textContent = 'Priority';

  const select = document.createElement('select');
  select.className = 'form-select form-control';
  priorityChoices.forEach(([value, text]) => {
    select.add(new Option(text, value, value === selectedPriority, value === selectedPriority));
  });
  settings.priority = parseInt(select.value, 10);
  select.addEventListener('change', () => {
    settings.priority = parseInt(select.value, 10);
  });

  field.append(label, select);
  container.append(field);
}

async function submitTaskCreation(board, table, uid, details, successLabel) {
  const result = await createTask(table, uid, details);
  if (result.success !== true) {
    Notification.error('Content Flow', result.message || 'Could not create the task.');
    return false;
  }

  board.announce('Created task ' + successLabel);
  Notification.success('Content Flow', 'Task created.');
  return true;
}

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
 *   2. MultiStepWizard collects title, description, priority and assignment
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
  if (!MultiStepWizard) {
    void submitTaskCreation(board, table, uid, { title: recordTitle }, recordTitle).then((created) => {
      if (created) {
        window.location.reload();
      }
    }).catch(() => {
      Notification.error('Content Flow', 'Could not reach the server.');
    });
    return;
  }

  MultiStepWizard.set('table', table);
  MultiStepWizard.set('uid', uid);
  MultiStepWizard.set('title', recordTitle);
  MultiStepWizard.set('description', '');
  MultiStepWizard.set('assignee', 'me');
  MultiStepWizard.set('priority', 2);

  MultiStepWizard.addSlide(
    'contentflow-details',
    'New task',
    '',
    SeverityEnum.notice,
    'Details',
    (slide, settings) => {
      const wrapper = document.createElement('div');
      const fields = buildTaskDetailsForm(settings, {
        title: recordTitle,
        description: '',
        assignee: 'me',
      });

      wrapper.append(fields.element);
      addPriorityField(wrapper, settings);
      slide.html(wrapper);

      updateTitleRequirement(MultiStepWizard, fields.titleInput);
      fields.titleInput.addEventListener('input', () => {
        updateTitleRequirement(MultiStepWizard, fields.titleInput);
      });
    },
  );

  MultiStepWizard.addFinalProcessingSlide(async () => {
    const settings = MultiStepWizard.setup.settings;
    const title = String(settings.title || '').trim();
    if (title === '') {
      Notification.error('Content Flow', 'A title is required.');
      MultiStepWizard.previous();
      return;
    }

    try {
      const created = await submitTaskCreation(
        board,
        settings.table,
        settings.uid,
        {
          title,
          description: String(settings.description || '').trim(),
          priority: settings.priority,
          assignee: settings.assignee,
        },
        title,
      );

      if (!created) {
        MultiStepWizard.previous();
        return;
      }

      MultiStepWizard.dismiss();
      window.location.reload();
    } catch (error) {
      Notification.error('Content Flow', 'Could not reach the server.');
      MultiStepWizard.previous();
    }
  });

  MultiStepWizard.show();
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

export async function createTask(table, uid, details = {}) {
  const url = TYPO3.settings.ajaxUrls.contentflow_task_create;
  if (!url) {
    return {
      success: false,
      message: 'Task creation is not configured.',
    };
  }

  const response = await new AjaxRequest(url).post({ table, uid, ...details });
  return await response.resolve();
}
