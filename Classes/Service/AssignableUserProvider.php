<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Service;

use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Every backend user, not just this workspace's members - core exposes no
 * simple "who has access to workspace X" lookup, and the assignee column has
 * never been restricted to workspace membership (an admin can already assign
 * a task to anyone). Fine at the scale this extension targets; a large
 * multi-thousand-user installation would want this list scoped or paginated
 * instead of sent whole on every backend page load.
 *
 * Shared between LoadWizardModuleEventListener (the outer backend chrome) and
 * EditorialFlowController (the board's own content iframe) - two genuinely
 * separate PageRenderer/TYPO3.settings contexts that do not share inline
 * settings with each other, so both need this added independently. See
 * LoadWizardModuleEventListener's docblock for why that split exists at all.
 */
final class AssignableUserProvider
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {
    }

    /**
     * @return list<array{uid: int, name: string}>
     */
    public function getAssignableUsers(): array
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
