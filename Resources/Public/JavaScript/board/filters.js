/*
 * Client-side search and filtering of the rendered cards.
 */

export function registerFilters(board) {
    const searchInput = document.querySelector('#cf-search-input');
    const assigneeSelect = document.querySelector('#cf-filter-assignee');
    const statusSelect = document.querySelector('#cf-filter-status');
    // Only present when ContentFlowController::indexAction() found more than one
    // workspace the current user has access to - see Index.html. A checkbox per
    // workspace, not a single select: several other workspaces' stages are
    // merged straight into the board's own stage columns now, so narrowing is
    // no longer a single choice.
    const workspaceCheckboxes = document.querySelectorAll('[data-contentflow-filter-workspace]');
    const workspaceCountBadge = document.querySelector('#cf-filter-workspace-count');
    const clearBtn = document.querySelector('#cf-clear-filters');

    if (!searchInput) return;

    const filterCards = () => {
      const query = searchInput.value.toLowerCase().trim();
      const assigneeFilter = assigneeSelect ? assigneeSelect.value : 'all';
      const statusFilter = statusSelect ? statusSelect.value : 'all';
      // Own cards (and unversioned Backlog/Planned/Done ones, workspace 0)
      // always match - this filter only ever narrows which *other*
      // workspaces' merged-in cards are visible, mirroring the old single
      // -select's documented intent.
      const currentWorkspaceId = String(TYPO3.settings.ContentFlow?.currentWorkspaceId ?? 0);
      const checkedWorkspaces = Array.from(workspaceCheckboxes).filter((checkbox) => checkbox.checked);
      const selectedWorkspaceIds = new Set(checkedWorkspaces.map((checkbox) => checkbox.value));
      if (workspaceCountBadge) {
        workspaceCountBadge.textContent = `${checkedWorkspaces.length}/${workspaceCheckboxes.length}`;
      }

      let totalVisible = 0;
      let totalCards = 0;
      board.board.querySelectorAll('.contentflow-column').forEach((column) => {
        let visibleCount = 0;
        column.querySelectorAll('.contentflow-card').forEach((card) => {
          totalCards++;
          const title = (card.dataset.contentflowTitle || '').toLowerCase();
          const record = (card.dataset.contentflowRecord || '').toLowerCase();
          const cardAssignee = parseInt(card.dataset.contentflowAssignee || '0', 10);
          const isAuto = card.dataset.contentflowAuto === '1';
          const isWarned = parseInt(card.dataset.contentflowWarned || '0', 10) > 0;
          const cardWorkspace = card.dataset.contentflowWorkspace || '0';

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

          // Match workspace filter - a card's own workspace (or none, workspace
          // 0 for Backlog/Planned/Done) always matches; only cards merged in
          // from another workspace are actually narrowed by the checkboxes.
          const matchesWorkspace = workspaceCheckboxes.length === 0
            || cardWorkspace === '0'
            || cardWorkspace === currentWorkspaceId
            || selectedWorkspaceIds.has(cardWorkspace);

          const visible = matchesQuery && matchesAssignee && matchesStatus && matchesWorkspace;
          card.style.display = visible ? 'flex' : 'none';
          if (visible) {
            visibleCount++;
            totalVisible++;
          }
        });

        // Update column pill count badge dynamically
        const badge = column.querySelector('.contentflow-column-header .badge');
        if (badge) {
          badge.textContent = visibleCount;
        }
      });

      // A board that only communicates a filter's effect by moving pixels is
      // unusable without sight - see board.js's own announce() docblock.
      board.announce(`Showing ${totalVisible} of ${totalCards} tasks.`);
    };

    searchInput.addEventListener('input', filterCards);
    assigneeSelect?.addEventListener('change', filterCards);
    statusSelect?.addEventListener('change', filterCards);
    workspaceCheckboxes.forEach((checkbox) => checkbox.addEventListener('change', filterCards));
    clearBtn?.addEventListener('click', () => {
      searchInput.value = '';
      if (assigneeSelect) assigneeSelect.value = 'all';
      if (statusSelect) statusSelect.value = 'all';
      workspaceCheckboxes.forEach((checkbox) => { checkbox.checked = true; });
      filterCards();
    });

    filterCards();
  }
