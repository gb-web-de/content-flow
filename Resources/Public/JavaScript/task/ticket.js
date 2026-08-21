/*
 * Opening a task like a ticket.
 *
 * The markup is rendered server-side by Fluid and loaded into core's Modal, so
 * diff markup and escaping stay out of the browser.
 */
export function registerTicketButtons(board) {
  document.querySelectorAll('[data-editorialflow-open-ticket]').forEach((button) => {
    button.addEventListener('click', (event) => {
      // The card body toggles selection; opening the ticket must not also select.
      event.stopPropagation();
      event.preventDefault();
      board.openTicket(button.dataset.editorialflowOpenTicket, button.textContent.trim());
    });
  });
}
