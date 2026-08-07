<?php

declare(strict_types=1);

namespace GbWeb\ContentFlow\Service;

use GbWeb\ContentFlow\Domain\Repository\TaskRepository;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;

/**
 * Pulls the content of a page into that page's task.
 *
 * "Wenn eine Seite einen geplanten Task bekommt, werden alle Inhalte und Records
 * die auf der Seite liegen dazu hinzugefügt" - so a card means "this page and
 * everything on it", not one card per content element.
 *
 * Records already claimed by another open task are skipped, which is exactly what
 * makes an editor's detach permanent: re-syncing cannot take the element back.
 */
class TaskMemberSynchronizer
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly TaskSubjectRegistry $subjectRegistry,
        private readonly ReferenceInspector $referenceInspector,
        private readonly TaskRepository $taskRepository,
    ) {
    }

    /**
     * Attach every trackable record sitting on the page to the task.
     *
     * Runs one query per workspace-aware table. That is a handful of dozens of
     * cheap pid-indexed lookups, and it happens when a task is created or
     * explicitly resynced - not on every board render.
     *
     * @return int number of records newly claimed
     */
    public function syncPageMembers(int $taskUid, int $pageUid): int
    {
        if ($pageUid < 1) {
            return 0;
        }

        $claimed = 0;
        foreach ($this->subjectRegistry->getAggregatableTables() as $table) {
            foreach ($this->findRecordUidsOnPage($table, $pageUid) as $recordUid) {
                // Flag content that other pages reuse, so the board can warn before
                // an editor changes something that shows up elsewhere too.
                $shared = $this->referenceInspector->isSharedAcrossPages($table, $recordUid, $pageUid);
                if ($this->taskRepository->addMemberIfUnclaimed(
                    $taskUid,
                    $table,
                    $recordUid,
                    TaskRepository::ORIGIN_AUTO,
                    $pageUid,
                    $shared,
                )) {
                    $claimed++;
                }
            }
        }
        return $claimed;
    }

    /**
     * Does any member of this task still have an unpublished version?
     *
     * A task covers a page and everything on it, so publishing one content element
     * must not close it. The task is done when nothing it covers is pending any more.
     */
    public function hasPendingVersions(int $taskUid, int $workspaceUid): bool
    {
        if ($workspaceUid < 1) {
            return false;
        }
        foreach ($this->taskRepository->findMembers($taskUid) as $member) {
            $table = (string)$member['record_table'];
            if (!$this->subjectRegistry->isTrackable($table)) {
                continue;
            }
            if ($this->findVersionUid($table, (int)$member['record_uid'], $workspaceUid) > 0) {
                return true;
            }
        }
        return false;
    }

    private function findVersionUid(string $table, int $liveUid, int $workspaceUid): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());

        try {
            $uid = $queryBuilder
                ->select('uid')
                ->from($table)
                ->where(
                    $queryBuilder->expr()->eq('t3ver_oid', $queryBuilder->createNamedParameter($liveUid, Connection::PARAM_INT)),
                    $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter($workspaceUid, Connection::PARAM_INT)),
                )
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchOne();
        } catch (\Doctrine\DBAL\Exception) {
            return 0;
        }

        return (int)($uid ?: 0);
    }

    /**
     * Live records on a page. Workspace versions are excluded: a version is not a
     * separate piece of work, it is the in-progress state of its live record, and
     * claiming both would double-count the same element.
     *
     * @return list<int>
     */
    private function findRecordUidsOnPage(string $table, int $pageUid): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());

        try {
            $rows = $queryBuilder
                ->select('uid')
                ->from($table)
                ->where(
                    $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pageUid, Connection::PARAM_INT)),
                    $queryBuilder->expr()->eq('t3ver_oid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                )
                ->executeQuery()
                ->fetchAllAssociative();
        } catch (\Doctrine\DBAL\Exception) {
            // A table declared workspace-aware in TCA but missing its columns in the
            // database (stale schema, half-installed extension) must not break the
            // whole sync - skip it.
            return [];
        }

        return array_map(static fn(array $row): int => (int)$row['uid'], $rows);
    }
}
