/*
 * Column drag and drop.
 *
 * A drop only ever PROPOSES a move - the server hands core stage columns to
 * TYPO3's DataHandler, which decides. Nothing here is drag-only: the same moves
 * are reachable from the keyboard and from the ticket view.
 */
import Notification from '@typo3/backend/notification.js';

export function registerDragAndDrop(board) {
  let draggedCard = null;

  const clearDropTargetStyles = () => {
    board.board.querySelectorAll('.editorialflow-column').forEach((column) => {
      column.classList.remove('is-drop-target-valid', 'is-drop-target-invalid');
    });
  };

  const updateDropTargetStyles = (card) => {
    board.board.querySelectorAll('.editorialflow-column').forEach((column) => {
      const valid = board.canDropCardIntoColumn(card, column);
      column.classList.toggle('is-drop-target-valid', valid);
      column.classList.toggle('is-drop-target-invalid', !valid);
    });
  };

  board.board.querySelectorAll('.editorialflow-card').forEach((card) => {
    card.addEventListener('dragstart', (event) => {
      draggedCard = card;
      card.classList.add('is-dragged');
      updateDropTargetStyles(card);

      if (event.dataTransfer !== null) {
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', card.dataset.editorialflowTask || '');
      }
    });

    card.addEventListener('dragend', () => {
      if (draggedCard !== null) {
        draggedCard.classList.remove('is-dragged');
        draggedCard = null;
      }
      clearDropTargetStyles();
    });
  });

  board.board.querySelectorAll('.editorialflow-column').forEach((column) => {
    column.addEventListener('dragover', (event) => {
      if (draggedCard === null) {
        return;
      }

      event.preventDefault();
      if (event.dataTransfer !== null) {
        event.dataTransfer.dropEffect = board.canDropCardIntoColumn(draggedCard, column) ? 'move' : 'none';
      }
    });

    column.addEventListener('drop', async (event) => {
      event.preventDefault();

      if (draggedCard === null) {
        return;
      }

      const taskUid = event.dataTransfer?.getData('text/plain') || draggedCard.dataset.editorialflowTask || '';
      const valid = board.canDropCardIntoColumn(draggedCard, column);
      clearDropTargetStyles();

      if (!valid) {
        const message = board.getDropRejectionMessage(draggedCard, column);
        Notification.warning('Editorial Flow', message);
        board.announce(message);
        return;
      }

      if (taskUid === '') {
        return;
      }

      await board.handleCardDrop(parseInt(taskUid, 10), column, draggedCard);
    });
  });
}
