/*
 * Client-side search and filtering of the rendered cards.
 */

export function registerFilters(board) {
    const searchInput = document.querySelector('#cf-search-input');
    const assigneeSelect = document.querySelector('#cf-filter-assignee');
    const statusSelect = document.querySelector('#cf-filter-status');
    const clearBtn = document.querySelector('#cf-clear-filters');

    if (!searchInput) return;

    const filterCards = () => {
      const query = searchInput.value.toLowerCase().trim();
      const assigneeFilter = assigneeSelect ? assigneeSelect.value : 'all';
      const statusFilter = statusSelect ? statusSelect.value : 'all';

      board.board.querySelectorAll('.contentflow-column').forEach((column) => {
        let visibleCount = 0;
        column.querySelectorAll('.contentflow-card').forEach((card) => {
          const title = (card.dataset.contentflowTitle || '').toLowerCase();
          const record = (card.dataset.contentflowRecord || '').toLowerCase();
          const cardAssignee = parseInt(card.dataset.contentflowAssignee || '0', 10);
          const isAuto = card.dataset.contentflowAuto === '1';
          const isWarned = parseInt(card.dataset.contentflowWarned || '0', 10) > 0;

          // Match search query
          const matchesQuery = query === '' || title.includes(query) || record.includes(query);

          // Match Assignee filter
          let matchesAssignee = true;
          const currentUserId = TYPO3.settings.ContentFlow?.currentUserId || 0;
          if (assigneeFilter === 'me') {
            matchesAssignee = (cardAssignee > 0 && currentUserId > 0 && cardAssignee === currentUserId) || card.querySelector('.contentflow-card-assignee') !== null;
          } else if (assigneeFilter === 'unassigned') {
            matchesAssignee = cardAssignee === 0 && card.querySelector('.contentflow-card-assignee') === null;
          }

          // Match Status filter
          let matchesStatus = true;
          if (statusFilter === 'auto') {
            matchesStatus = isAuto;
          } else if (statusFilter === 'warned') {
            matchesStatus = isWarned;
          }

          const visible = matchesQuery && matchesAssignee && matchesStatus;
          card.style.display = visible ? 'flex' : 'none';
          if (visible) {
            visibleCount++;
          }
        });

        // Update column pill count badge dynamically
        const badge = column.querySelector('.contentflow-column-header .badge');
        if (badge) {
          badge.textContent = visibleCount;
        }
      });
    };

    searchInput.addEventListener('input', filterCards);
    assigneeSelect?.addEventListener('change', filterCards);
    statusSelect?.addEventListener('change', filterCards);
    clearBtn?.addEventListener('click', () => {
      searchInput.value = '';
      if (assigneeSelect) assigneeSelect.value = 'all';
      if (statusSelect) statusSelect.value = 'all';
      filterCards();
    });
  }
