<?php

declare(strict_types=1);

namespace GbWeb\ContentFlow\Tests\Functional\Dashboard;

use GbWeb\ContentFlow\Dashboard\Widget\RecentActivityWidget;
use GbWeb\ContentFlow\Dashboard\Widget\RecentCommentsWidget;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\View\BackendViewFactory;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Settings\Settings;
use TYPO3\CMS\Dashboard\Widgets\WidgetConfiguration;
use TYPO3\CMS\Dashboard\Widgets\WidgetContext;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Both dashboard widgets join comments/activity back to their task's title so an
 * editor scanning the dashboard knows *which* task a line is about, not just that
 * something happened. RecentActivityWidget's template already referenced
 * `activity.task_title`, but nothing ever populated that key - it silently
 * rendered empty, which reading the code did not catch, only running it did.
 */
final class DashboardWidgetsResolveTaskTitlesTest extends FunctionalTestCase
{
    /**
     * @var string[]
     */
    protected array $coreExtensionsToLoad = [
        'typo3/cms-workspaces',
        'typo3/cms-dashboard',
    ];

    /**
     * @var string[]
     */
    protected array $testExtensionsToLoad = [
        'gb-web/content-flow',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->setUpBackendUser(1);
    }

    private function context(): WidgetContext
    {
        return new WidgetContext(
            'test',
            [],
            new WidgetConfiguration('test', 'test', [], 'Test', '', '', 'medium', 'medium'),
            new Settings([], []),
            new ServerRequest(),
        );
    }

    private function createTask(string $title): int
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('tx_contentflow_task');
        $connection->insert('tx_contentflow_task', [
            'title' => $title,
            'subject_table' => 'pages',
            'subject_uid' => 2,
            'subject_pid' => 2,
            'state' => 'in_progress',
        ]);

        return (int)$connection->lastInsertId();
    }

    #[Test]
    public function recentActivityWidgetShowsTheTaskTitleItReferences(): void
    {
        $taskUid = $this->createTask('About us');
        $this->getConnectionPool()->getConnectionForTable('tx_contentflow_activity')->insert(
            'tx_contentflow_activity',
            ['task' => $taskUid, 'event' => 'work_started', 'crdate' => time()],
        );

        $widget = new RecentActivityWidget(
            new WidgetConfiguration('test', 'test', [], 'Test', '', '', 'medium', 'medium'),
            $this->get(BackendViewFactory::class),
            $this->get(ConnectionPool::class),
        );

        $content = $widget->renderWidget($this->context())->content;

        self::assertStringContainsString('About us', $content, 'the task title must appear, not just the event name');
    }

    #[Test]
    public function recentCommentsWidgetShowsTheTaskTitleItReferences(): void
    {
        $taskUid = $this->createTask('Products');
        $this->getConnectionPool()->getConnectionForTable('tx_contentflow_comment')->insert(
            'tx_contentflow_comment',
            ['task' => $taskUid, 'content' => 'Looks good to me.', 'crdate' => time()],
        );

        $widget = new RecentCommentsWidget(
            new WidgetConfiguration('test', 'test', [], 'Test', '', '', 'medium', 'medium'),
            $this->get(BackendViewFactory::class),
            $this->get(ConnectionPool::class),
        );

        $content = $widget->renderWidget($this->context())->content;

        self::assertStringContainsString('Products', $content);
        self::assertStringContainsString('Looks good to me.', $content);
    }
}
