<?php

declare(strict_types=1);

namespace GbWeb\ContentFlow\Tests\Functional\EventListener;

use GbWeb\ContentFlow\Domain\Model\TaskState;
use GbWeb\ContentFlow\EventListener\ContentElementTaskBadgeListener;
use GbWeb\ContentFlow\Service\ActiveTaskSession;
use GbWeb\ContentFlow\Service\TaskColor;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\View\Event\AfterPageContentPreviewRenderedEvent;
use TYPO3\CMS\Backend\View\PageLayoutContext;
use TYPO3\CMS\Core\Domain\RecordFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The Page module used to say nothing about an element another task already
 * holds, while the Visual Editor marked the very same element with a bubble -
 * so the safest-looking place to edit was the one with no warning in it.
 */
final class ContentElementTaskBadgeListenerTest extends FunctionalTestCase
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
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->setUpBackendUser(1);
        $GLOBALS['BE_USER']->setWorkspace(1);
    }

    private function subject(): ContentElementTaskBadgeListener
    {
        return $this->get(ContentElementTaskBadgeListener::class);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createTask(array $overrides = []): int
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('tx_contentflow_task');
        $connection->insert('tx_contentflow_task', array_merge([
            'title' => 'Rewrite the intro',
            'subject_table' => 'pages',
            'subject_uid' => 2,
            'subject_pid' => 2,
            'state' => TaskState::IN_PROGRESS->value,
            'workspace_uid' => 1,
            'closed' => 0,
        ], $overrides));

        return (int)$connection->lastInsertId();
    }

    private function addMember(int $taskUid, int $recordUid): void
    {
        $this->getConnectionPool()->getConnectionForTable('tx_contentflow_task_item')->insert(
            'tx_contentflow_task_item',
            ['task' => $taskUid, 'record_table' => 'tt_content', 'record_uid' => $recordUid, 'pid' => 2, 'closed' => 0],
        );
    }

    private function renderPreviewFor(int $contentUid): string
    {
        $row = $this->getConnectionPool()
            ->getConnectionForTable('tt_content')
            ->select(['*'], 'tt_content', ['uid' => $contentUid])
            ->fetchAssociative();
        self::assertIsArray($row);

        $record = $this->get(RecordFactory::class)->createResolvedRecordFromDatabaseRow('tt_content', $row);
        $context = $this->createMock(PageLayoutContext::class);

        $event = new AfterPageContentPreviewRenderedEvent(
            'tt_content',
            'text',
            $record,
            $context,
            '<p>the element as it renders today</p>',
        );
        $this->subject()->__invoke($event);

        return $event->getPreviewContent();
    }

    #[Test]
    public function anElementHeldByATaskIsNamedAboveItsPreview(): void
    {
        $taskUid = $this->createTask();
        $this->addMember($taskUid, 10);

        $output = $this->renderPreviewFor(10);

        self::assertStringContainsString('contentflow-element-badge', $output);
        // Never colour alone - the task is named, not just tinted.
        self::assertStringContainsString('Rewrite the intro', $output);
        self::assertStringContainsString('--contentflow-task-hue: ' . TaskColor::hueFor($taskUid), $output);
        // The element's own preview survives; the badge is added, not swapped in.
        self::assertStringContainsString('the element as it renders today', $output);
    }

    #[Test]
    public function anUnclaimedElementIsLeftExactlyAsItWas(): void
    {
        $this->createTask();

        $output = $this->renderPreviewFor(11);

        self::assertSame('<p>the element as it renders today</p>', $output);
    }

    /**
     * The task the editor declared in the Visual Editor reads differently from
     * somebody else's - that difference is the whole point of the marker.
     */
    #[Test]
    public function theEditorsOwnTaskIsMarkedApartFromTheRest(): void
    {
        $taskUid = $this->createTask();
        $this->addMember($taskUid, 10);
        $this->get(ActiveTaskSession::class)->remember($GLOBALS['BE_USER'], 2, $taskUid);

        $output = $this->renderPreviewFor(10);

        self::assertStringContainsString('contentflow-element-badge--active', $output);
    }

    #[Test]
    public function aFinishedTaskNoLongerHoldsItsElements(): void
    {
        $taskUid = $this->createTask(['state' => TaskState::DONE->value, 'closed' => 1]);
        $this->addMember($taskUid, 10);

        $output = $this->renderPreviewFor(10);

        self::assertStringNotContainsString('contentflow-element-badge', $output);
    }
}
