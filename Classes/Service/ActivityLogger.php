<?php

declare(strict_types=1);

namespace GbWeb\ContentFlow\Service;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;

/**
 * Append-only trail of Content Flow's own events.
 *
 * What this does NOT do is copy field-level diffs. TYPO3 already records those in
 * `sys_history` (who changed which field, from what, to what, when) and duplicating
 * them into a JSON blob would only create a second, drifting truth.
 *
 * The one thing sys_history cannot survive is publication: once the version record is
 * published its uid is gone, and the sys_history rows that referenced it no longer
 * resolve - the trail would break exactly when the task closes. So on publish a
 * compact summary is snapshotted into `payload` (see snapshotHistory()), and
 * everything before that is read live from sys_history.
 */
class ActivityLogger
{
    private const TABLE = 'tx_contentflow_activity';

    public const EVENT_TASK_CREATED = 'task_created';
    public const EVENT_WORK_STARTED = 'work_started';
    public const EVENT_ASSIGNED = 'assigned';
    public const EVENT_STAGE_CHANGED = 'stage_changed';
    public const EVENT_PUBLISHED = 'published';
    public const EVENT_CLOSED = 'closed';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function log(int $taskUid, string $event, int $beUserId, array $payload = []): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE)->insert(self::TABLE, [
            'task' => $taskUid,
            'event' => $event,
            'be_user' => $beUserId,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'crdate' => $GLOBALS['EXEC_TIME'],
            'tstamp' => $GLOBALS['EXEC_TIME'],
        ]);
    }

    /**
     * Freeze what sys_history knows about a version, right before publishing makes
     * that version - and therefore its history rows - unresolvable.
     *
     * Stores a summary (which fields changed, by whom, when), not full before/after
     * values: the published live record is the "after", and keeping full payloads
     * here would grow without bound on busy editorial sites.
     */
    public function snapshotHistory(int $taskUid, string $table, int $versionUid, int $beUserId): void
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_history');
        $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());

        $rows = $queryBuilder
            ->select('uid', 'usertype', 'userid', 'actiontype', 'tstamp', 'history_data')
            ->from('sys_history')
            ->where(
                $queryBuilder->expr()->eq('tablename', $queryBuilder->createNamedParameter($table)),
                $queryBuilder->expr()->eq('recuid', $queryBuilder->createNamedParameter($versionUid, Connection::PARAM_INT)),
            )
            ->orderBy('tstamp', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        $summary = [];
        foreach ($rows as $row) {
            $historyData = json_decode((string)($row['history_data'] ?? '{}'), true);
            $summary[] = [
                'historyUid' => (int)$row['uid'],
                'userId' => (int)$row['userid'],
                'actionType' => (int)$row['actiontype'],
                'timestamp' => (int)$row['tstamp'],
                'fields' => is_array($historyData['newRecord'] ?? null)
                    ? array_keys($historyData['newRecord'])
                    : [],
            ];
        }

        $this->log($taskUid, self::EVENT_PUBLISHED, $beUserId, [
            'table' => $table,
            'versionUid' => $versionUid,
            'history' => $summary,
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
}
