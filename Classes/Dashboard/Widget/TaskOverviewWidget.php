<?php

declare(strict_types=1);

namespace GbWeb\ContentFlow\Dashboard\Widget;

use TYPO3\CMS\Backend\View\BackendViewFactory;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Settings\SettingDefinition;
use TYPO3\CMS\Dashboard\Widgets\WidgetConfigurationInterface;
use TYPO3\CMS\Dashboard\Widgets\WidgetContext;
use TYPO3\CMS\Dashboard\Widgets\WidgetRendererInterface;
use TYPO3\CMS\Dashboard\Widgets\WidgetResult;

/**
 * How much work sits in each state, plus what nobody has picked up yet.
 *
 * The unassigned count is deliberately prominent: planning allows leaving a task
 * open so an editor can take it, and that only works if "up for grabs" is visible.
 */
final readonly class TaskOverviewWidget implements WidgetRendererInterface
{
    public function __construct(
        private WidgetConfigurationInterface $configuration,
        private BackendViewFactory $backendViewFactory,
        private ConnectionPool $connectionPool,
    ) {
    }

    /**
     * @return SettingDefinition[]
     */
    public function getSettingsDefinitions(): array
    {
        return [];
    }

    public function renderWidget(WidgetContext $context): WidgetResult
    {
        $view = $this->backendViewFactory->create($context->request, ['gb-web/content-flow']);
        $view->assignMultiple([
            'countsByState' => $this->countByState(),
            'unassigned' => $this->countUnassigned(),
            'configuration' => $this->configuration,
        ]);

        return new WidgetResult(
            content: $view->render('Dashboard/TaskOverview'),
            refreshable: true,
        );
    }

    /**
     * One grouped query rather than one per column.
     *
     * @return array<string, int>
     */
    private function countByState(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_contentflow_task');
        $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());

        $rows = $queryBuilder
            ->select('state')
            ->addSelectLiteral($queryBuilder->expr()->count('uid', 'amount'))
            ->from('tx_contentflow_task')
            ->where($queryBuilder->expr()->eq('closed', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)))
            ->groupBy('state')
            ->executeQuery()
            ->fetchAllAssociative();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string)$row['state']] = (int)$row['amount'];
        }

        return $counts;
    }

    private function countUnassigned(): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_contentflow_task');
        $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());

        return (int)$queryBuilder
            ->count('uid')
            ->from('tx_contentflow_task')
            ->where(
                $queryBuilder->expr()->eq('assignee', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('closed', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchOne();
    }
}
