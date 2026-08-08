<?php

declare(strict_types=1);

namespace GbWeb\ContentFlow\Service;

use TYPO3\CMS\Backend\Tree\Repository\PageTreeRepository;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Turns "this page, at this depth" (or "this workspace's own root pages") into the
 * page-uid list the board query needs.
 *
 * Depth follows the same convention EXT:workspaces' own module UI uses: 0 = just
 * the selected page, 1-4 = that many levels of subpages, 999 = the whole subtree.
 * Built on core's own PageTreeRepository::getFlattenedPages() - the same helper
 * the classic Recordlist module uses for its "search in subtree" - rather than
 * hand-rolling a tree walk.
 *
 * getFlattenedPages() itself is permission-unaware (it fetches "all non-deleted
 * pages" - see its own docblock), so every result here is filtered through
 * BackendUtility::readPageAccess() before it reaches the board query. Depth/root
 * scanning must never surface a task on a page the current editor cannot see.
 */
final class BoardScopeResolver
{
    /**
     * @return list<int>
     */
    public function resolvePageUids(int $pageUid, int $depth, BackendUserAuthentication $backendUser): array
    {
        if ($pageUid < 1) {
            return [];
        }
        if ($depth < 1) {
            return $this->filterByAccess([$pageUid], $backendUser);
        }

        $repository = GeneralUtility::makeInstance(PageTreeRepository::class);
        $pages = $repository->getFlattenedPages([$pageUid], $depth);
        $pageUids = array_values(array_unique(array_map(static fn (array $page): int => (int)$page['uid'], $pages)));

        return $this->filterByAccess($pageUids, $backendUser);
    }

    /**
     * @return list<int>
     */
    public function resolveWorkspaceRootPageUids(int $workspaceUid, BackendUserAuthentication $backendUser): array
    {
        if ($workspaceUid < 1) {
            return [];
        }
        $workspace = BackendUtility::getRecord('sys_workspace', $workspaceUid, 'db_mountpoints');
        $mountpoints = GeneralUtility::intExplode(',', (string)($workspace['db_mountpoints'] ?? ''), true);
        if ($mountpoints === []) {
            return [];
        }

        $pageUids = [];
        foreach ($mountpoints as $mountpoint) {
            // 999 = the whole subtree below each mount point, matching this class's
            // own "root" depth convention.
            $pageUids = array_merge($pageUids, $this->resolvePageUids($mountpoint, 999, $backendUser));
        }

        return array_values(array_unique($pageUids));
    }

    /**
     * @param list<int> $pageUids
     * @return list<int>
     */
    private function filterByAccess(array $pageUids, BackendUserAuthentication $backendUser): array
    {
        if ($backendUser->isAdmin()) {
            return $pageUids;
        }
        $permsClause = $backendUser->getPagePermsClause(Permission::PAGE_SHOW);

        return array_values(array_filter(
            $pageUids,
            static fn (int $pageUid): bool => BackendUtility::readPageAccess($pageUid, $permsClause) !== false,
        ));
    }
}
