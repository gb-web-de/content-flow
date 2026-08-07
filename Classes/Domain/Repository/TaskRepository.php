<?php

declare(strict_types=1);

namespace GbWeb\ContentFlow\Domain\Repository;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use GbWeb\ContentFlow\Domain\Model\TaskState;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;

/**
 * Persistence for tx_contentflow_task.
 *
 * Deliberately plain Doctrine/QueryBuilder rather than Extbase: tasks are written
 * from a DataHandler hook and a PSR-14 listener, i.e. from inside another write
 * transaction, where an Extbase persistence session would be the wrong tool.
 */
class TaskRepository
{
    private const TABLE = 'tx_contentflow_task';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findOpenByRecord(string $recordTable, int $recordUid): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());

        $row = $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('record_table', $queryBuilder->createNamedParameter($recordTable)),
                $queryBuilder->expr()->eq('record_uid', $queryBuilder->createNamedParameter($recordUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('closed', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return $row === false ? null : $row;
    }

    /**
     * Get the open task for a record, creating it if there is none.
     *
     * The uniqueness of "one open task per record" is enforced by the
     * `open_task_per_record` unique key, not by the preceding SELECT: two editors
     * opening the same page simultaneously would both see "no task" and both insert.
     * The loser of that race gets a constraint violation and re-reads the winner's
     * row instead of creating a duplicate.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public function findOrCreateOpenByRecord(string $recordTable, int $recordUid, array $values): array
    {
        $existing = $this->findOpenByRecord($recordTable, $recordUid);
        if ($existing !== null) {
            return $existing;
        }

        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        try {
            $connection->insert(self::TABLE, array_merge($values, [
                'record_table' => $recordTable,
                'record_uid' => $recordUid,
                'crdate' => $GLOBALS['EXEC_TIME'],
                'tstamp' => $GLOBALS['EXEC_TIME'],
            ]));
        } catch (UniqueConstraintViolationException) {
            // Lost the race - the other request's task is authoritative.
            $winner = $this->findOpenByRecord($recordTable, $recordUid);
            if ($winner !== null) {
                return $winner;
            }
            throw new \RuntimeException(
                sprintf('Could not resolve open Content Flow task for %s:%d', $recordTable, $recordUid),
                1754563200,
            );
        }

        $created = $this->findOpenByRecord($recordTable, $recordUid);
        if ($created === null) {
            throw new \RuntimeException(
                sprintf('Content Flow task for %s:%d vanished right after insert', $recordTable, $recordUid),
                1754563201,
            );
        }
        return $created;
    }

    /**
     * Move a task onto a workspace version. Only ever widens state from an
     * unversioned one - a task already in review is not dragged back to IN_PROGRESS
     * just because someone touched the record again.
     */
    public function attachVersion(int $taskUid, int $workspaceUid, int $versionUid, int $stageUid): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->update(
            self::TABLE,
            [
                'workspace_uid' => $workspaceUid,
                'version_uid' => $versionUid,
                'stage_uid' => $stageUid,
                'state' => TaskState::fromStageId($stageUid)->value,
                'tstamp' => $GLOBALS['EXEC_TIME'],
            ],
            ['uid' => $taskUid],
        );
    }

    /**
     * Close a task after its version went live. The version uid is cleared because
     * the version record no longer exists once published.
     */
    public function close(int $taskUid, int $beUserId): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->update(
            self::TABLE,
            [
                'state' => TaskState::DONE->value,
                'closed' => 1,
                'closed_at' => $GLOBALS['EXEC_TIME'],
                'closed_by' => $beUserId,
                'version_uid' => 0,
                'tstamp' => $GLOBALS['EXEC_TIME'],
            ],
            ['uid' => $taskUid],
        );
    }

    /**
     * All open tasks below a page, for the board.
     *
     * One query for the whole board - the cards are grouped in PHP rather than by
     * running a query per column, so adding review stages never adds queries.
     *
     * @return list<array<string, mixed>>
     */
    public function findOpenForBoard(int $pageUid): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());

        return $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('record_pid', $queryBuilder->createNamedParameter($pageUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('closed', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->orderBy('tstamp', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();
    }
}
