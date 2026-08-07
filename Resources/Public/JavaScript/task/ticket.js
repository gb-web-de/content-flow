/*
 * Opening a task like a ticket. The markup is rendered server-side by Fluid and
 * loaded into core's Modal, so diff markup and escaping stay out of the browser.
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
