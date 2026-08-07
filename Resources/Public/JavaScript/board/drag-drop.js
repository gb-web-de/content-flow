/*
 * Column drag and drop.
 *
 * A drop only ever PROPOSES a move - the server hands core stage columns to
 * TYPO3's DataHandler, which decides. Nothing here is drag-only: the same moves
 * are reachable from the keyboard and from the ticket view.
 */
import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import Notification from '@typo3/backend/notification.js';

export function registerDragAndDrop(board) {
    let draggedCard = null;

    board.board.querySelectorAll('.contentflow-card').forEach((card) => {
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
        board.board.querySelectorAll('.contentflow-column').forEach((col) => {
          col.classList.remove('is-drop-target-valid', 'is-drop-target-invalid');
        });
      });
    });

    board.board.querySelectorAll('.contentflow-column').forEach((column) => {
      column.addEventListener('dragover', (e) => {
        e.preventDefault();
        const acceptsDrop = column.dataset.contentflowAcceptsDrop !== 'false';
        if (acceptsDrop) {
          e.dataTransfer.dropEffect = 'move';
          column.classList.add('is-drop-target-valid');
          column.classList.remove('is-drop-target-invalid');
        } else {
          e.dataTransfer.dropEffect = 'none';
          column.classList.add('is-drop-target-invalid');
          column.classList.remove('is-drop-target-valid');
        }
      });

      column.addEventListener('dragleave', (e) => {
        if (!column.contains(e.relatedTarget)) {
          column.classList.remove('is-drop-target-valid', 'is-drop-target-invalid');
        }
      });

      column.addEventListener('drop', async (e) => {
        e.preventDefault();
        const acceptsDrop = column.dataset.contentflowAcceptsDrop !== 'false';
        column.classList.remove('is-drop-target-valid', 'is-drop-target-invalid');

        if (!acceptsDrop) {
          Notification.warning('Content Flow', 'This column does not accept manual card drops. Going live is an explicit action.');
          return;
        }

        const taskUid = e.dataTransfer.getData('text/plain') || (draggedCard ? draggedCard.dataset.contentflowTask : null);
        if (!taskUid) return;

        const targetState = column.dataset.contentflowState;
        const targetStageUid = parseInt(column.dataset.contentflowStage || '0', 10);
        const colTitle = column.querySelector('.contentflow-column-title')?.textContent || targetState;

        // Open Workspace Stage Confirmation Modal (comment & recipients)
        board.openStageTransitionModal(parseInt(taskUid, 10), targetState, targetStageUid, colTitle);
      });
    });
  }
