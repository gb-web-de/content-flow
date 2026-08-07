<?php

declare(strict_types=1);

namespace GbWeb\ContentFlow\Dashboard\Widget;

use GbWeb\ContentFlow\Domain\Repository\TaskRepository;
use TYPO3\CMS\Backend\View\BackendViewFactory;
use TYPO3\CMS\Core\Settings\SettingDefinition;
use TYPO3\CMS\Dashboard\Widgets\WidgetConfigurationInterface;
use TYPO3\CMS\Dashboard\Widgets\WidgetContext;
use TYPO3\CMS\Dashboard\Widgets\WidgetRendererInterface;
use TYPO3\CMS\Dashboard\Widgets\WidgetResult;

/**
 * "My tasks" - what this editor is responsible for right now.
 *
 * Built on v14's WidgetRendererInterface rather than the legacy WidgetInterface:
 * core's own widgets use it, it renders through BackendViewFactory (which gets the
 * request and therefore proper backend context), and it supports SettingDefinition
 * so the editor can configure the widget without us writing a settings UI.
 */
final readonly class MyTasksWidget implements WidgetRendererInterface
{
    public function __construct(
        private WidgetConfigurationInterface $configuration,
        private BackendViewFactory $backendViewFactory,
        private TaskRepository $taskRepository,
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
                default: (int)($this->options['limit'] ?? 10),
                label: 'Number of tasks',
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
            : (int)($this->options['limit'] ?? 10);
        $beUserId = (int)($this->getBackendUser()['uid'] ?? 0);

        $view = $this->backendViewFactory->create($context->request, ['gb-web/content-flow']);
        $view->assignMultiple([
            'tasks' => $beUserId > 0 ? $this->taskRepository->findOpenByAssignee($beUserId, $limit) : [],
            'configuration' => $this->configuration,
        ]);

        return new WidgetResult(
            content: $view->render('Dashboard/MyTasks'),
            refreshable: true,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function getBackendUser(): array
    {
        return $GLOBALS['BE_USER']->user ?? [];
    }
}
