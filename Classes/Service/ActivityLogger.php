<?php

declare(strict_types=1);

namespace GbWeb\ContentFlow\Service;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\DataHandling\History\RecordHistoryStore;

/**
 * The durable editorial record: what was decided, by whom, when.
 *
 * Why this exists next to sys_history - the reasoning matters, because the obvious
 * objection ("you are duplicating core's history") turns out to be wrong:
 *
 * - Core does NOT lose the trail on publish. RecordHistoryStore::publishRecord()
 *   calls migrateWorkspaceHistory(), which rewrites `recuid` from the version uid to
 *   the live uid. Every entry, including ACTION_STAGECHANGE rows carrying the stage
 *   comment and recipients, survives and stays reachable from the live record.
 * - What core does lose is *age*. EXT:scheduler registers sys_history in the table
 *   garbage collection task with an expirePeriod of **30 days** by default. An
 *   archived task therefore has no trail left after a month or two.
 *
 * So this table is not a second truth, it is the durable one. sys_history is a
 * volatile operational log; Content Flow keeps the decision itself (who moved this
 * from which stage to which, with what comment) and stores `history_uid` as a pointer
 * to core's full field-level detail *for as long as that detail exists*. Readers must
 * treat a dangling history_uid as "detail expired", never as an error.
 *
 * Field-level before/after values are deliberately NOT copied here. Those are bulky,
 * and for the common case - one edit that goes straight live - the sys_history row is
 * still there and the pointer alone is enough.
 */
class ActivityLogger
{
    private const TABLE = 'tx_contentflow_activity';

    public const EVENT_TASK_CREATED = 'task_created';
    public const EVENT_WORK_STARTED = 'work_started';
    public const EVENT_ASSIGNED = 'assigned';
    public const EVENT_STAGE_CHANGED = 'stage_changed';
    public const EVENT_CLOSED = 'closed';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {
    }

    /**
     * Record a decision at the moment it is made.
     *
     * @param array<string, mixed> $payload the essentials, durable
     * @param int $historyUid sys_history row holding the full detail, 0 if none
     */
    public function log(int $taskUid, string $event, int $beUserId, array $payload = [], int $historyUid = 0): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE)->insert(self::TABLE, [
            'task' => $taskUid,
            'event' => $event,
            'be_user' => $beUserId,
            'history_uid' => $historyUid,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'crdate' => $GLOBALS['EXEC_TIME'],
            'tstamp' => $GLOBALS['EXEC_TIME'],
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findByTask(int $taskUid): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());

        return $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('task', $queryBuilder->createNamedParameter($taskUid, Connection::PARAM_INT)),
            )
            ->orderBy('crdate', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Core's stage-change entries for a record, newest first.
     *
     * Used to pick up the sys_history uid of a stage change that core wrote, and to
     * reconcile transitions made outside the board (e.g. in the Workspaces module),
     * which Content Flow never sees happen.
     *
     * Pass the version uid while the version lives, or the live uid after publishing -
     * core migrates `recuid` to the live uid at publish time, so both are correct at
     * their respective moment.
     *
     * @return list<array<string, mixed>>
     */
    public function findStageChanges(string $table, int $recordUid): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_history');
        $queryBuilder->getRestrictions()->removeAll();

        return $queryBuilder
            ->select('uid', 'userid', 'tstamp', 'history_data')
            ->from('sys_history')
            ->where(
                $queryBuilder->expr()->eq('tablename', $queryBuilder->createNamedParameter($table)),
                $queryBuilder->expr()->eq('recuid', $queryBuilder->createNamedParameter($recordUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq(
                    'actiontype',
                    $queryBuilder->createNamedParameter(RecordHistoryStore::ACTION_STAGECHANGE, Connection::PARAM_INT),
                ),
            )
            ->orderBy('tstamp', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();
    }
}
