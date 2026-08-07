<?php

declare(strict_types=1);

namespace GbWeb\ContentFlow\Domain\Repository;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\HiddenRestriction;

/**
 * Reads board columns and cards from xima_typo3_content_planner's status model
 * (tx_ximatypo3contentplanner_domain_model_status / the tx_ximatypo3contentplanner_status
 * and tx_ximatypo3contentplanner_assignee fields it adds to `pages`).
 *
 * Content Flow does not own this data - it only reads it, so upgrading
 * xima/xima-typo3-content-planner never requires a Content Flow migration.
 */
class StatusBoardRepository
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {
    }

    /**
     * @return list<array{id: int, title: string, icon: string, color: string, sorting: int}>
     */
    public function getStatusColumns(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_ximatypo3contentplanner_domain_model_status');
        $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction())->add(new HiddenRestriction());

        $result = $queryBuilder
            ->select('uid', 'title', 'icon', 'color', 'sorting')
            ->from('tx_ximatypo3contentplanner_domain_model_status')
            ->orderBy('sorting', 'ASC')
            ->executeQuery();

        $columns = [];
        while ($row = $result->fetchAssociative()) {
            $columns[] = [
                'id' => (int)$row['uid'],
                'title' => (string)$row['title'],
                'icon' => (string)($row['icon'] ?? ''),
                'color' => (string)($row['color'] ?? ''),
                'sorting' => (int)$row['sorting'],
            ];
        }
        return $columns;
    }

    /**
     * Pages under $pagePid (direct children) carrying a status, grouped by status uid.
     * Pages without a status (tx_ximatypo3contentplanner_status IS NULL) are omitted -
     * the board only visualises pages an editor has actively put into the workflow.
     *
     * @return array<int, list<array{uid: int, title: string, statusId: int, assigneeBeUser: int|null}>>
     */
    public function getCardsGroupedByStatus(int $pagePid): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction())->add(new HiddenRestriction());

        $result = $queryBuilder
            ->select('uid', 'title', 'tx_ximatypo3contentplanner_status', 'tx_ximatypo3contentplanner_assignee')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pagePid, Connection::PARAM_INT)),
                $queryBuilder->expr()->isNotNull('tx_ximatypo3contentplanner_status'),
            )
            ->orderBy('sorting', 'ASC')
            ->executeQuery();

        $grouped = [];
        while ($row = $result->fetchAssociative()) {
            $statusId = (int)$row['tx_ximatypo3contentplanner_status'];
            $grouped[$statusId][] = [
                'uid' => (int)$row['uid'],
                'title' => (string)$row['title'],
                'statusId' => $statusId,
                'assigneeBeUser' => $row['tx_ximatypo3contentplanner_assignee'] !== null
                    ? (int)$row['tx_ximatypo3contentplanner_assignee']
                    : null,
            ];
        }
        return $grouped;
    }
}
