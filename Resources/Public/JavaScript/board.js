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
 *   Icons              @typo3/backend/icons.js
 *
 * Custom code is limited to what core has no opinion about: the board's own
 * column drag-and-drop, card selection, and calls to this extension's routes.
 *
 * Accessibility is a hard requirement (see ARCHITECTURE.md): nothing is
 * drag-only, and every change is announced through the live region.
 */
import DocumentService from '@typo3/core/document-service.js';

import { registerFilters } from '@gb-web/content-flow/board/filters.js';
import { registerDragAndDrop } from '@gb-web/content-flow/board/drag-drop.js';
import { registerAssignButtons } from '@gb-web/content-flow/task/assign.js';
import { registerTicketButtons } from '@gb-web/content-flow/task/ticket.js';
import { registerCreateButton } from '@gb-web/content-flow/task/create-wizard.js';

class ContentFlowBoard {
  constructor() {
    this.selection = new Set();
    DocumentService.ready().then(() => this.initialize());
  }

  initialize() {
    this.announcer = document.querySelector('.contentflow-announcer');

    // Registered before the board check on purpose: both also appear in the page
    // module banner, where there is no board element at all.
    registerCreateButton(this);
    registerTicketButtons(this);

    this.board = document.querySelector('.contentflow-board');
    if (this.board === null) {
      return;
    }

    this.registerCardEvents();
    registerDragAndDrop(this);
    registerFilters(this);
    registerAssignButtons(this);
  }

  /**
   * Announce a change to screen readers. Every mutation goes through here - a
   * board that only communicates by moving pixels is unusable without sight.
   */
  announce(message) {
    if (this.announcer !== null && this.announcer !== undefined) {
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
    this.announce((wasSelected ? 'Deselected ' : 'Selected ') + card.dataset.contentflowTitle);
  }
}

export default new ContentFlowBoard();
