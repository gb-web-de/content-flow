/*
 * The "+ New task" flow.
 *
 * Two core APIs, each doing what it is good at, deliberately NOT nested because
 * core modals do not stack: the element browser picks the page (tree, live
 * search, depth), then MultiStepWizard collects title, priority and assignment.
 */
import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import Notification from '@typo3/backend/notification.js';
import Modal from '@typo3/backend/modal.js';
import { SeverityEnum } from '@typo3/backend/enum/severity.js';

export /**
   * Open a task like a ticket. Uses core's Modal in ajax mode, so the markup is
   * rendered server-side by Fluid rather than assembled here - diff markup and
   * escaping stay out of the browser.
   */
  registerTicketButtons() {
    document.querySelectorAll('[data-contentflow-open-ticket]').forEach((button) => {
      button.addEventListener('click', (event) => {
        // The card body toggles selection; opening the ticket must not also select.
        event.stopPropagation();
        event.preventDefault();
        board.openTicket(button.dataset.contentflowOpenTicket, button.textContent.trim());
      });
    });
  }

  openTicket(taskUid, title) {
    const url = TYPO3.settings.ajaxUrls.contentflow_task_ticket;
    if (!url) {
      Notification.error('Content Flow', 'Ticket view is not available.');
      return;
    }
    board.announce('Opened task ' + title);
    Modal.advanced({
      type: Modal.types.ajax,
      title: title,
      content: url + '&task=' + encodeURIComponent(taskUid),
      size: Modal.sizes.large,
      severity: SeverityEnum.notice,
    });
  }

  registerCreateButton() {
    document.querySelectorAll('[data-contentflow-action="create-task"]').forEach((button) => {
      button.addEventListener('click', (event) => {
        event.preventDefault();
        // On a page banner the page is already known; on the board it is not.
        const bannerPageId = parseInt(button.dataset.contentflowPage || '0', 10);
        if (bannerPageId > 0) {
          board.openNewTaskWizard(bannerPageId, button.dataset.contentflowPageTitle || ('Page ' + bannerPageId));
        } else {
          board.openPagePicker();
        }
      });
    });
  }

export /**
   * The "+" button: pick a page, then fill in the details.
   *
   * Two core APIs, each doing what it is good at, and deliberately NOT nested -
   * core modals do not stack:
   *
   *   1. the element browser picks the page (tree, live search, depth)
   *   2. MultiStepWizard collects title, priority and assignment
   *
   * Three separate reasons no page could be selected before:
   *   - registerCreateButton() was called but did not exist, so the click threw
   *     and nothing opened at all;
   *   - the URL used the pipe-delimited `bparams` string, deprecated in v14;
   *   - the result was awaited on window.setFormValueFromBrowseWin, a legacy
   *     callback v14 no longer calls. The browser reports through the
   *     `typo3:element-browser:message` event now.
   */
  openPagePicker() {
    const baseUrl = TYPO3.settings.ContentFlow?.elementBrowserUrl;
    if (!baseUrl) {
      Notification.error('Content Flow', 'Element browser is not configured.');
      return;
    }

    // The same parameters core's FormEngine.openPopupWindow() builds.
    const params = new URLSearchParams({ mode: 'db', allowedTypes: 'pages', useEvents: '1' });
    const modal = Modal.advanced({
      type: Modal.types.iframe,
      title: 'Select a page',
      content: baseUrl + (baseUrl.includes('?') ? '&' : '?') + params.toString(),
      size: Modal.sizes.large,
      severity: SeverityEnum.notice,
    });

    modal.addEventListener('typo3:element-browser:message', (event) => {
      const { actionName, value, label } = event.detail;
      if (actionName !== 'typo3:elementBrowser:elementAdded') {
        return;
      }
      // value looks like "pages_5".
      const uid = parseInt(String(value).split('_').pop(), 10);
      modal.hideModal();
      if (Number.isInteger(uid) && uid > 0) {
        board.openNewTaskWizard(uid, label || ('Page ' + uid));
      }
    });
  }

export /**
   * Details step, on core's MultiStepWizard.
   *
   * Leaving a task unassigned is offered as a first-class choice rather than as
   * an empty field: "somebody will pick this up" is a real plan, and the board
   * lists those separately.
   */
  openNewTaskWizard(pageUid, pageTitle) {
    const wizard = TYPO3.MultiStepWizard;
    if (!wizard) {
      // Never lose the click just because the wizard is unavailable.
      board.createTask('pages', pageUid);
      return;
    }

    wizard.set('pageUid', pageUid);
    wizard.set('title', pageTitle);
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
      // Assigned as a property, never interpolated into markup - the page title
      // is editor-supplied content.
      titleInput.value = pageTitle;
      titleInput.addEventListener('input', () => { settings.title = titleInput.value; });
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
      prioritySelect.addEventListener('change', () => { settings.priority = parseInt(prioritySelect.value, 10); });
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
      assigneeSelect.addEventListener('change', () => { settings.assignee = assigneeSelect.value; });
      assigneeField.append(assigneeLabel, assigneeSelect);

      form.append(titleField, priorityField, assigneeField);
      slide.html(form);
      wizard.unlockNextStep();
    });

    wizard.addFinalProcessingSlide(async () => {
      const settings = wizard.setup.settings;
      await board.createTask('pages', settings.pageUid, {
        title: settings.title,
        priority: settings.priority,
        assignee: settings.assignee,
      });
      wizard.dismiss();
    });

    wizard.show();
  }

export async function createTask(board, table, uid, details = {})table, uid, details = {}
