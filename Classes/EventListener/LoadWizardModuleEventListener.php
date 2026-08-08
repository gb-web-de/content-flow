<?php

declare(strict_types=1);

namespace GbWeb\ContentFlow\EventListener;

use TYPO3\CMS\Backend\Controller\Event\AfterBackendPageRenderEvent;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Page\PageRenderer;

/**
 * Loads the post-save routing wizard everywhere in the backend.
 *
 * `Configuration/JavaScriptModules.php` only makes a module importable; nothing
 * reads a key like `includeInModules` to decide when to load one automatically -
 * that key existed in an earlier version of this extension and did nothing.
 *
 * There is no config-driven "always load this module" mechanism at all. The
 * correct place is AfterBackendPageRenderEvent, dispatched once by
 * BackendController for the outer backend chrome that every module loads into.
 * EXT:workspaces uses exactly this event for the same reason (loading
 * workspace-state.js everywhere) - copied from
 * TYPO3\CMS\Workspaces\EventListener\AfterBackendPageRenderEventListener.
 *
 * This is what makes the wizard reachable regardless of where an editor saves a
 * content element from - the Page module, the List module, or a direct
 * record_edit link - rather than only the two places that called
 * loadJavaScriptModule() by hand before (the board module and the page banner).
 */
final readonly class LoadWizardModuleEventListener
{
    public function __construct(
        private PageRenderer $pageRenderer,
        private ConnectionPool $connectionPool,
    ) {
    }

    #[AsEventListener(event: AfterBackendPageRenderEvent::class)]
    public function __invoke(): void
    {
        $this->pageRenderer->loadJavaScriptModule('@gb-web/content-flow/wizard.js');
        // Populated here, not only in ContentFlowController, for the same reason
        // the module itself is loaded here: the wizard's "assign to" field must
        // work from the Page module, the List module, or a direct record_edit
        // link, not only from the board.
        $this->pageRenderer->addInlineSetting('ContentFlow', 'assignableUsers', $this->getAssignableUsers());
    }

    /**
     * Every backend user, not just this workspace's members - core exposes no
     * simple "who has access to workspace X" lookup, and the assignee column
     * has never been restricted to workspace membership (an admin can already
     * assign a task to anyone). Fine at the scale this extension targets; a
     * large multi-thousand-user installation would want this list scoped or
     * paginated instead of sent whole on every backend page load.
     *
     * @return list<array{uid: int, name: string}>
     */
    private function getAssignableUsers(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('be_users');
        $rows = $queryBuilder
            ->select('uid', 'username', 'realName')
            ->from('be_users')
            ->orderBy('realName', 'ASC')
            ->addOrderBy('username', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(static fn (array $row): array => [
            'uid' => (int)$row['uid'],
            'name' => !empty($row['realName']) ? (string)$row['realName'] : (string)$row['username'],
        ], $rows);
    }
}
