<?php

declare(strict_types=1);

namespace GbWeb\ContentFlow\Service;

use TYPO3\CMS\Backend\Tree\Repository\PageTreeRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Turns "this page, at this depth" into the page-uid list the board query needs.
 *
 * Depth follows the same convention EXT:workspaces' own module UI uses: 0 = just
 * the selected page, 1-4 = that many levels of subpages, 999 = the whole subtree.
 * Built on core's own PageTreeRepository::getFlattenedPages() - the same helper
 * the classic Recordlist module uses for its "search in subtree" - rather than
 * hand-rolling a tree walk.
 */
final class BoardScopeResolver
{
    /**
     * @return list<int>
     */
    public function resolvePageUids(int $pageUid, int $depth): array
    {
        if ($pageUid < 1) {
            return [];
        }
        if ($depth < 1) {
            return [$pageUid];
        }

        $repository = GeneralUtility::makeInstance(PageTreeRepository::class);
        $pages = $repository->getFlattenedPages([$pageUid], $depth);

        return array_values(array_unique(array_map(static fn (array $page): int => (int)$page['uid'], $pages)));
    }
}
