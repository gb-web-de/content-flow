<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Domain\Repository;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;

/**
 * A stage's review checklist, and one task's progress against it.
 *
 * Two tables on purpose: `tx_editorialflow_stage_checklist_item` is the
 * definition (a stage's own policy, reused by every task passing through it),
 * `tx_editorialflow_task_checklist_state` is one task's completion of it. Mirrors
 * TaskRepository's member/item split - the relationship (has this task checked
 * this item?) has its own row and lifecycle, independent of both sides.
 */
final class TaskChecklistRepository
{
    private const TABLE_ITEM = 'tx_editorialflow_stage_checklist_item';
    private const TABLE_STATE = 'tx_editorialflow_task_checklist_state';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {
    }

    /**
     * A stage's checklist item definitions, in board order.
     *
     * @return list<array<string, mixed>>
     */
    public function findItemsForStage(int $workspaceUid, int $stageUid): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE_ITEM);
        $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());

        return $queryBuilder
            ->select('*')
            ->from(self::TABLE_ITEM)
            ->where(
                $queryBuilder->expr()->eq('workspace_uid', $queryBuilder->createNamedParameter($workspaceUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('stage_uid', $queryBuilder->createNamedParameter($stageUid, Connection::PARAM_INT)),
                // DeletedRestriction is a silent no-op here: it only ever adds a
                // constraint for tables with a TCA `ctrl.delete` entry, and this
                // extension's own tables deliberately have none (see ext_tables.sql).
                // Explicit, not decorative.
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->orderBy('sorting', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    public function addItem(int $workspaceUid, int $stageUid, string $title, int $sorting): int
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE_ITEM);
        $connection->insert(self::TABLE_ITEM, [
            'workspace_uid' => $workspaceUid,
            'stage_uid' => $stageUid,
            'title' => $title,
            'sorting' => $sorting,
            'crdate' => $GLOBALS['EXEC_TIME'],
            'tstamp' => $GLOBALS['EXEC_TIME'],
        ]);

        return (int)$connection->lastInsertId();
    }

    public function removeItem(int $itemUid): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE_ITEM)->update(
            self::TABLE_ITEM,
            ['deleted' => 1, 'tstamp' => $GLOBALS['EXEC_TIME']],
            ['uid' => $itemUid],
        );
    }

    /**
     * A task's completion for one stage's checklist, definitions joined with
     * whatever state exists - an item nobody has toggled yet simply has no state
     * row, which reads as "not completed" without needing a placeholder insert.
     *
     * @return list<array{uid: int, title: string, completed: bool}>
     */
    public function findChecklistForTask(int $taskUid, int $workspaceUid, int $stageUid): array
    {
        $items = $this->findItemsForStage($workspaceUid, $stageUid);
        if ($items === []) {
            return [];
        }
        $completedItemUids = $this->findCompletedItemUids($taskUid, array_map(static fn (array $item): int => (int)$item['uid'], $items));

        return array_map(static fn (array $item): array => [
            'uid' => (int)$item['uid'],
            'title' => (string)$item['title'],
            'completed' => in_array((int)$item['uid'], $completedItemUids, true),
        ], $items);
    }

    /**
     * How many of a stage's checklist items this task has not yet checked off -
     * the soft-warning executeStageAction() shows before letting a task leave a
     * stage with unfinished items. 0 when the stage has no checklist at all.
     */
    public function countIncomplete(int $taskUid, int $workspaceUid, int $stageUid): int
    {
        $items = $this->findItemsForStage($workspaceUid, $stageUid);
        if ($items === []) {
            return 0;
        }
        $completedItemUids = $this->findCompletedItemUids($taskUid, array_map(static fn (array $item): int => (int)$item['uid'], $items));

        return count($items) - count($completedItemUids);
    }

    /**
     * Toggle one item for one task. Upsert via insert-then-catch, the same
     * concurrency pattern TaskRepository::addMemberIfUnclaimed() uses - two
     * editors toggling the same item at once is expected, not a race to guard
     * against with a pre-check.
     */
    public function setCompletion(int $taskUid, int $checklistItemUid, bool $completed, int $beUserId): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE_STATE);

        try {
            $connection->insert(self::TABLE_STATE, [
                'task' => $taskUid,
                'checklist_item' => $checklistItemUid,
                'completed' => $completed ? 1 : 0,
                'completed_by' => $beUserId,
                'completed_at' => $GLOBALS['EXEC_TIME'],
                'crdate' => $GLOBALS['EXEC_TIME'],
                'tstamp' => $GLOBALS['EXEC_TIME'],
            ]);
            return;
        } catch (UniqueConstraintViolationException) {
            // A state row already exists - fall through to update it.
        }

        $connection->update(
            self::TABLE_STATE,
            [
                'completed' => $completed ? 1 : 0,
                'completed_by' => $beUserId,
                'completed_at' => $GLOBALS['EXEC_TIME'],
                'tstamp' => $GLOBALS['EXEC_TIME'],
            ],
            ['task' => $taskUid, 'checklist_item' => $checklistItemUid],
        );
    }

    /**
     * @param list<int> $itemUids
     * @return list<int>
     */
    private function findCompletedItemUids(int $taskUid, array $itemUids): array
    {
        if ($itemUids === []) {
            return [];
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE_STATE);

        $rows = $queryBuilder
            ->select('checklist_item')
            ->from(self::TABLE_STATE)
            ->where(
                $queryBuilder->expr()->eq('task', $queryBuilder->createNamedParameter($taskUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('completed', $queryBuilder->createNamedParameter(1, Connection::PARAM_INT)),
                $queryBuilder->expr()->in('checklist_item', $queryBuilder->createNamedParameter($itemUids, Connection::PARAM_INT_ARRAY)),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(static fn (array $row): int => (int)$row['checklist_item'], $rows);
    }
}
