<?php

declare(strict_types=1);

namespace GbWeb\ContentFlow\Dashboard\Widget;

use TYPO3\CMS\Backend\View\BackendViewFactory;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Settings\SettingDefinition;
use TYPO3\CMS\Dashboard\Widgets\WidgetConfigurationInterface;
use TYPO3\CMS\Dashboard\Widgets\WidgetContext;
use TYPO3\CMS\Dashboard\Widgets\WidgetRendererInterface;
use TYPO3\CMS\Dashboard\Widgets\WidgetResult;

/**
 * What happened lately across the editorial board: stage moves, assignments,
 * comments, closures.
 *
 * Reads tx_contentflow_activity rather than sys_history on purpose - the activity
 * trail is the durable one (sys_history is garbage-collected after 30 days by
 * default), and it holds the editorial decisions rather than field-level noise.
 */
final readonly class RecentActivityWidget implements WidgetRendererInterface
{
    public function __construct(
        private WidgetConfigurationInterface $configuration,
        private BackendViewFactory $backendViewFactory,
        private ConnectionPool $connectionPool,
        /** @var array{limit?: int} */
        private array $options = [],
    ) {
    }

    /**
     * @return SettingDefinition[]
     */
    public function getSettingsDefinitions(): array
    {
        return [
            new SettingDefinition(
                key: 'limit',
                type: 'int',
                default: (int)($this->options['limit'] ?? 15),
                label: 'Number of entries',
                readonly: array_key_exists('limit', $this->options),
            ),
        ];
    }

    public function renderWidget(WidgetContext $context): WidgetResult
    {
        // SettingsInterface extends ContainerInterface, so get() takes no default -
        // has() must be checked first.
        $limit = $context->settings->has('limit')
            ? (int)$context->settings->get('limit')
            : (int)($this->options['limit'] ?? 15);

        $view = $this->backendViewFactory->create($context->request, ['gb-web/content-flow']);
        $view->assignMultiple([
            'activities' => $this->findRecent($limit),
            'configuration' => $this->configuration,
        ]);

        return new WidgetResult(
            content: $view->render('Dashboard/RecentActivity'),
            refreshable: true,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function findRecent(int $limit): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_contentflow_activity');
        $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());

        return $queryBuilder
            ->select('a.*')
            ->from('tx_contentflow_activity', 'a')
            ->orderBy('a.crdate', 'DESC')
            ->setMaxResults(max(1, $limit))
            ->executeQuery()
            ->fetchAllAssociative();
    }
}
