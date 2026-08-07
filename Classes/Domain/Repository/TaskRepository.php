<?php

declare(strict_types=1);

namespace GbWeb\ContentFlow\Domain\Repository;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use GbWeb\ContentFlow\Domain\Model\TaskState;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;

/**
 * Persistence for tasks and their members.
 *
 * Deliberately plain Doctrine/QueryBuilder rather than Extbase: tasks are written
 * from a DataHandler hook and a PSR-14 listener, i.e. from inside another write
 * transaction, where an Extbase persistence session would be the wrong tool.
 */
final class TaskRepository
{
    public const ORIGIN_SUBJECT = 'subject';
    public const ORIGIN_AUTO = 'auto';
    public const ORIGIN_MANUAL = 'manual';

    private const TABLE = 'tx_contentflow_task';
    private const TABLE_ITEM = 'tx_contentflow_task_item';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findOpenBySubject(string $subjectTable, int $subjectUid): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());

        $row = $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('subject_table', $queryBuilder->createNamedParameter($subjectTable)),
                $queryBuilder->expr()->eq('subject_uid', $queryBuilder->createNamedParameter($subjectUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('closed', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return $row === false ? null : $row;
    }

    /**
     * The open task a record currently belongs to, if any.
     *
     * This is what makes detaching stick: a record already owned by another open
     * task is never reclaimed by its page's task.
     *
     * @return array<string, mixed>|null
     */
    public function findOpenTaskByMember(string $recordTable, int $recordUid): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE_ITEM);
        $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());

        $taskUid = $queryBuilder
            ->select('task')
            ->from(self::TABLE_ITEM)
            ->where(
                $queryBuilder->expr()->eq('record_table', $queryBuilder->createNamedParameter($recordTable)),
                $queryBuilder->expr()->eq('record_uid', $queryBuilder->createNamedParameter($recordUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('closed', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        return $taskUid ? $this->findByUid((int)$taskUid) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByUid(int $taskUid): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());

        $row = $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($taskUid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();

        return $row === false ? null : $row;
    }

    /**
     * Get the open task for a subject, creating it if there is none.
     *
     * Uniqueness is enforced by the `one_open_task_per_record` unique key on the
     * item table, not by the preceding SELECT: two editors opening the same page
     * simultaneously would both see "no task" and both insert. The loser catches
     * the constraint violation and adopts the winner's task.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public function findOrCreateOpenForSubject(string $subjectTable, int $subjectUid, array $values): array
    {
        $existing = $this->findOpenBySubject($subjectTable, $subjectUid);
        if ($existing !== null) {
            return $existing;
        }

        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->insert(self::TABLE, array_merge($values, [
            'subject_table' => $subjectTable,
            'subject_uid' => $subjectUid,
            'crdate' => $GLOBALS['EXEC_TIME'],
            'tstamp' => $GLOBALS['EXEC_TIME'],
        ]));
        $taskUid = (int)$connection->lastInsertId();

        try {
            // The subject is a member of its own task. This insert is what actually
            // claims the record, so it is also what detects a concurrent creation.
            $this->addMember($taskUid, $subjectTable, $subjectUid, self::ORIGIN_SUBJECT);
        } catch (UniqueConstraintViolationException) {
            // Someone else got there first - drop our now-orphaned task and use theirs.
            $connection->delete(self::TABLE, ['uid' => $taskUid]);
            $winner = $this->findOpenBySubject($subjectTable, $subjectUid);
            if ($winner !== null) {
                return $winner;
            }
            throw new \RuntimeException(
                sprintf('Could not resolve open Content Flow task for %s:%d', $subjectTable, $subjectUid),
                1754563200,
            );
        }

        $created = $this->findByUid($taskUid);
        if ($created === null) {
            throw new \RuntimeException(
                sprintf('Content Flow task for %s:%d vanished right after insert', $subjectTable, $subjectUid),
                1754563201,
            );
        }
        return $created;
    }

    /**
     * Claim a record for a task. Throws UniqueConstraintViolationException when the
     * record already belongs to another open task - callers decide whether that is
     * an error or simply "leave it where the editor put it".
     */
    public function addMember(
        int $taskUid,
        string $recordTable,
        int $recordUid,
        string $origin,
        int $homePid = 0,
        bool $shared = false,
    ): void {
        $this->connectionPool->getConnectionForTable(self::TABLE_ITEM)->insert(self::TABLE_ITEM, [
            'task' => $taskUid,
            'record_table' => $recordTable,
            'record_uid' => $recordUid,
            'origin' => $origin,
            'home_pid' => $homePid,
            'shared' => $shared ? 1 : 0,
            'closed' => 0,
            'crdate' => $GLOBALS['EXEC_TIME'],
            'tstamp' => $GLOBALS['EXEC_TIME'],
        ]);
    }

    /**
     * Attach a record unless it already belongs to an open task.
     *
     * @return bool true when this call claimed the record
     */
    public function addMemberIfUnclaimed(
        int $taskUid,
        string $recordTable,
        int $recordUid,
        string $origin,
        int $homePid = 0,
        bool $shared = false,
    ): bool {
        try {
            $this->addMember($taskUid, $recordTable, $recordUid, $origin, $homePid, $shared);
            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    /**
     * Move a record's membership to another existing task.
     *
     * The "add to task" half of the wizard, and also how an editor resolves the
     * cross-page case: content reached through a shortcut can be moved onto the task
     * of the page they are actually working on, instead of the page it lives on.
     * `origin` becomes manual so a later resync does not treat it as auto-collected.
     */
    public function moveMemberToTask(string $recordTable, int $recordUid, int $targetTaskUid): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE_ITEM)->update(
            self::TABLE_ITEM,
            [
                'task' => $targetTaskUid,
                'origin' => self::ORIGIN_MANUAL,
                'tstamp' => $GLOBALS['EXEC_TIME'],
            ],
            [
                'record_table' => $recordTable,
                'record_uid' => $recordUid,
                'closed' => 0,
                'deleted' => 0,
            ],
        );
    }

    /**
     * Members that need an editor's attention: content living on another page, or
     * reused elsewhere. Both mean "changing this changes more than this page".
     *
     * @return list<array<string, mixed>>
     */
    public function findWarnedMembers(int $taskUid, int $subjectPid): array
    {
        return array_values(array_filter(
            $this->findMembers($taskUid),
            static fn (array $member): bool => (int)($member['shared'] ?? 0) === 1
                || ((int)($member['home_pid'] ?? 0) > 0 && (int)$member['home_pid'] !== $subjectPid),
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findMembers(int $taskUid): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE_ITEM);
        $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());

        return $queryBuilder
            ->select('*')
            ->from(self::TABLE_ITEM)
            ->where(
                $queryBuilder->expr()->eq('task', $queryBuilder->createNamedParameter($taskUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('closed', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Move a record out of its current task into a new task of its own.
     *
     * The editor's escape hatch from aggregation: "this one element is its own piece
     * of work". Implemented as a membership move, so the page's task can never take
     * it back - the unique key sees the slot as occupied.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed> the new task
     */
    public function detachIntoOwnTask(string $recordTable, int $recordUid, array $values): array
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->insert(self::TABLE, array_merge($values, [
            'subject_table' => $recordTable,
            'subject_uid' => $recordUid,
            'crdate' => $GLOBALS['EXEC_TIME'],
            'tstamp' => $GLOBALS['EXEC_TIME'],
        ]));
        $taskUid = (int)$connection->lastInsertId();

        // Re-point the existing membership rather than delete-then-insert, so the
        // record is never momentarily unclaimed and the unique key never trips.
        $this->connectionPool->getConnectionForTable(self::TABLE_ITEM)->update(
            self::TABLE_ITEM,
            [
                'task' => $taskUid,
                'origin' => self::ORIGIN_MANUAL,
                'tstamp' => $GLOBALS['EXEC_TIME'],
            ],
            [
                'record_table' => $recordTable,
                'record_uid' => $recordUid,
                'closed' => 0,
                'deleted' => 0,
            ],
        );

        $created = $this->findByUid($taskUid);
        if ($created === null) {
            throw new \RuntimeException(
                sprintf('Detached Content Flow task for %s:%d vanished right after insert', $recordTable, $recordUid),
                1754563202,
            );
        }
        return $created;
    }

    /**
     * Update the editable task details an editor can refine after auto-creation.
     *
     * The task already exists at this point - the save that triggered it has
     * happened - so this does not create or move anything, only replaces the
     * human-facing metadata with what the wizard collected.
     */
    public function updateDetails(int $taskUid, string $title, string $description, int $assignee): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE)->update(
            self::TABLE,
            [
                'title' => $title,
                'description' => $description,
                'assignee' => $assignee,
                'tstamp' => $GLOBALS['EXEC_TIME'],
            ],
            ['uid' => $taskUid],
        );
    }

    /**
     * Move a task onto a workspace version. Only widens state from an unversioned
     * one - a task already in review is not dragged back to IN_PROGRESS just
     * because someone touched one of its members again.
     */
    public function attachWorkspace(int $taskUid, int $workspaceUid, int $stageUid): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE)->update(
            self::TABLE,
            [
                'workspace_uid' => $workspaceUid,
                'stage_uid' => $stageUid,
                'state' => TaskState::fromStageId($stageUid)->value,
                'tstamp' => $GLOBALS['EXEC_TIME'],
            ],
            ['uid' => $taskUid],
        );
    }

    /**
     * Close a task once everything it covers has gone live. Members are closed too,
     * which releases their slot in the unique key for future work on the same records.
     */
    public function close(int $taskUid, int $beUserId): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE)->update(
            self::TABLE,
            [
                'state' => TaskState::DONE->value,
                'closed' => 1,
                'closed_at' => $GLOBALS['EXEC_TIME'],
                'closed_by' => $beUserId,
                'tstamp' => $GLOBALS['EXEC_TIME'],
            ],
            ['uid' => $taskUid],
        );
        $this->connectionPool->getConnectionForTable(self::TABLE_ITEM)->update(
            self::TABLE_ITEM,
            ['closed' => 1, 'tstamp' => $GLOBALS['EXEC_TIME']],
            ['task' => $taskUid],
        );
    }

    /**
     * All open tasks below a page, for the board. One query - the cards are grouped
     * in PHP, so adding review stages never adds queries.
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
                $queryBuilder->expr()->eq('subject_pid', $queryBuilder->createNamedParameter($pageUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('closed', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->orderBy('tstamp', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Open tasks assigned to one editor - the "my tasks" view.
     *
     * @return list<array<string, mixed>>
     */
    public function findOpenByAssignee(int $beUserId, int $limit = 10): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());

        return $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('assignee', $queryBuilder->createNamedParameter($beUserId, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('closed', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->orderBy('tstamp', 'DESC')
            ->setMaxResults(max(1, $limit))
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Open tasks nobody has taken yet. The board's "up for grabs" list - planning
     * deliberately allows leaving a task unassigned so an editor can pick it up.
     *
     * @return list<array<string, mixed>>
     */
    public function findUnassigned(int $limit = 10): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());

        return $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('assignee', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('closed', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->orderBy('sorting', 'ASC')
            ->setMaxResults(max(1, $limit))
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Mirror the column a task now sits in.
     *
     * For core stage columns this is only a read cache: TYPO3 has already written
     * t3ver_stage, and this keeps the board sortable without touching every version.
     */
    public function moveToColumn(int $taskUid, string $state, int $stageUid): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE)->update(
            self::TABLE,
            [
                'state' => $state,
                'stage_uid' => $stageUid,
                'tstamp' => $GLOBALS['EXEC_TIME'],
            ],
            ['uid' => $taskUid],
        );
    }

    /**
     * Give the task an owner. Passing 0 puts it back up for grabs.
     */
    public function assignTo(int $taskUid, int $beUserId): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE)->update(
            self::TABLE,
            [
                'assignee' => $beUserId,
                'tstamp' => $GLOBALS['EXEC_TIME'],
            ],
            ['uid' => $taskUid],
        );
    }
}
