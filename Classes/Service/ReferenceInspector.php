<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Service;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;

/**
 * Finds out whether a record is used on pages other than its own.
 *
 * The case that motivates this: a shortcut content element pulls a content element
 * in from another page. An editor working on page A changes it, and page B silently
 * changes too. A board that hides this is a task list; a board that shows it is a
 * planning tool.
 *
 * Deliberately built on sys_refindex rather than on CType='shortcut': the reference
 * index already records every kind of reuse - shortcuts, inline relations, links,
 * file references - so this warns about reuse the shortcut check would miss, and it
 * keeps working when an extension invents its own relation type.
 *
 * The index can be stale (it is rebuilt by a scheduler task), so treat a negative
 * answer as "no reuse known", not as proof. Warnings are advisory; nothing is
 * blocked on this.
 */
final class ReferenceInspector
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {
    }

    /**
     * Pages that reference this record, excluding the page it lives on.
     *
     * @return list<int> page uids, unique
     */
    public function findReferencingPages(string $table, int $uid, int $homePid): array
    {
        $referencingRecords = $this->findReferencingRecords($table, $uid);
        if ($referencingRecords === []) {
            return [];
        }

        $pageUids = [];
        foreach ($referencingRecords as $referencingTable => $recordUids) {
            foreach ($this->resolvePageUids($referencingTable, $recordUids) as $pageUid) {
                if ($pageUid > 0 && $pageUid !== $homePid) {
                    $pageUids[$pageUid] = true;
                }
            }
        }
        return array_map('intval', array_keys($pageUids));
    }

    public function isSharedAcrossPages(string $table, int $uid, int $homePid): bool
    {
        return $this->findReferencingPages($table, $uid, $homePid) !== [];
    }

    /**
     * Batch version: which of these records are reused on other pages?
     *
     * Synchronising a page means asking this about every element on it. Done one at
     * a time that is one refindex query per element, plus one pid lookup per
     * referencing table per element - a page with twenty elements would fire dozens
     * of round-trips. Here it is two phases regardless of how many records are
     * passed in: one refindex query, then one pid query per distinct referencing
     * table.
     *
     * @param list<int> $uids
     * @return array<int, bool> uid => reused elsewhere
     */
    public function findSharedFlags(string $table, array $uids, int $homePid): array
    {
        $flags = array_fill_keys($uids, false);
        if ($uids === []) {
            return $flags;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_refindex');
        $queryBuilder->getRestrictions()->removeAll();

        $rows = $queryBuilder
            ->select('tablename', 'recuid', 'ref_uid')
            ->from('sys_refindex')
            ->where(
                $queryBuilder->expr()->eq('ref_table', $queryBuilder->createNamedParameter($table)),
                $queryBuilder->expr()->in(
                    'ref_uid',
                    $queryBuilder->createNamedParameter($uids, Connection::PARAM_INT_ARRAY),
                ),
                $queryBuilder->expr()->gt('recuid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        if ($rows === []) {
            return $flags;
        }

        // Collect the referencing records per table once, so the pid lookup below
        // runs per table rather than per referenced record.
        $referencingUidsByTable = [];
        foreach ($rows as $row) {
            $referencingTable = (string)$row['tablename'];
            $referencingUid = (int)$row['recuid'];
            $referencingUidsByTable[$referencingTable][$referencingUid] = $referencingUid;
        }

        $pidByTableAndUid = [];
        foreach ($referencingUidsByTable as $referencingTable => $referencingUids) {
            $pidByTableAndUid[$referencingTable] = $this->resolvePidMap(
                $referencingTable,
                array_values($referencingUids),
            );
        }

        foreach ($rows as $row) {
            $referencedUid = (int)$row['ref_uid'];
            $referencingTable = (string)$row['tablename'];
            $referencingUid = (int)$row['recuid'];
            if ($referencingTable === $table && $referencingUid === $referencedUid) {
                continue;
            }
            $pid = $pidByTableAndUid[$referencingTable][$referencingUid] ?? 0;
            if ($pid > 0 && $pid !== $homePid) {
                $flags[$referencedUid] = true;
            }
        }

        return $flags;
    }

    /**
     * @return array<string, list<int>> referencing table => uids
     */
    private function findReferencingRecords(string $table, int $uid): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_refindex');
        $queryBuilder->getRestrictions()->removeAll();

        $rows = $queryBuilder
            ->select('tablename', 'recuid')
            ->from('sys_refindex')
            ->where(
                $queryBuilder->expr()->eq('ref_table', $queryBuilder->createNamedParameter($table)),
                $queryBuilder->expr()->eq('ref_uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)),
                // refindex stores -1/-2 for pseudo-relations such as fe_group "all".
                $queryBuilder->expr()->gt('recuid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $grouped = [];
        foreach ($rows as $row) {
            $referencingTable = (string)$row['tablename'];
            $referencingUid = (int)$row['recuid'];
            if ($referencingTable === $table && $referencingUid === $uid) {
                continue;
            }
            $grouped[$referencingTable][$referencingUid] = $referencingUid;
        }

        return array_map(static fn (array $uids): array => array_values($uids), $grouped);
    }

    /**
     * @param list<int> $recordUids
     * @return array<int, int> uid => pid
     */
    private function resolvePidMap(string $table, array $recordUids): array
    {
        if ($recordUids === []) {
            return [];
        }
        if ($table === 'pages') {
            // A page referencing something is itself the page.
            return array_combine($recordUids, $recordUids);
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());

        try {
            $rows = $queryBuilder
                ->select('uid', 'pid')
                ->from($table)
                ->where(
                    $queryBuilder->expr()->in(
                        'uid',
                        $queryBuilder->createNamedParameter($recordUids, Connection::PARAM_INT_ARRAY),
                    ),
                )
                ->executeQuery()
                ->fetchAllAssociative();
        } catch (\Doctrine\DBAL\Exception) {
            // Stale refindex pointing at a table that no longer exists.
            return [];
        }

        $map = [];
        foreach ($rows as $row) {
            $map[(int)$row['uid']] = (int)$row['pid'];
        }
        return $map;
    }

    /**
     * Resolve the pages the referencing records sit on - one query per table, not
     * one per record.
     *
     * @param list<int> $recordUids
     * @return list<int>
     */
    private function resolvePageUids(string $table, array $recordUids): array
    {
        if ($recordUids === []) {
            return [];
        }
        // A page referencing something is itself the page.
        if ($table === 'pages') {
            return $recordUids;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());

        try {
            $rows = $queryBuilder
                ->select('pid')
                ->from($table)
                ->where(
                    $queryBuilder->expr()->in(
                        'uid',
                        $queryBuilder->createNamedParameter($recordUids, Connection::PARAM_INT_ARRAY),
                    ),
                )
                ->executeQuery()
                ->fetchAllAssociative();
        } catch (\Doctrine\DBAL\Exception) {
            // Stale refindex pointing at a table that no longer exists.
            return [];
        }

        return array_map(static fn (array $row): int => (int)$row['pid'], $rows);
    }
}
