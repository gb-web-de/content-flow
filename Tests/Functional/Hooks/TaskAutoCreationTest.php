<?php

declare(strict_types=1);

namespace GbWeb\ContentFlow\Tests\Functional\Hooks;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The central promise of the extension: an editor who just opens a page and types
 * ends up with a task, without ever being asked to create one.
 *
 * These tests drive DataHandler exactly the way the backend does, so they exercise
 * the real auto-versioning path rather than a simulation of it.
 */
final class TaskAutoCreationTest extends FunctionalTestCase
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
    }

    /**
     * Edit a page inside a workspace, the way the backend form does.
     */
    private function editInWorkspace(string $table, int $uid, array $fields, int $workspaceUid = 1): void
    {
        // setWorkspace(), not ->workspace = : the setter validates the workspace and
        // populates workspaceRec, which DataHandler needs before it will version.
        $GLOBALS['BE_USER']->setWorkspace($workspaceUid);

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([$table => [$uid => $fields]], []);
        $dataHandler->process_datamap();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function selectAll(string $table): array
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        return $queryBuilder->select('*')->from($table)->executeQuery()->fetchAllAssociative();
    }

    #[Test]
    public function editingAPageInAWorkspaceCreatesATaskForIt(): void
    {
        $this->editInWorkspace('pages', 2, ['title' => 'About us (revised)']);

        $tasks = $this->selectAll('tx_contentflow_task');
        self::assertCount(1, $tasks, 'exactly one task should have been created');
        self::assertSame('pages', $tasks[0]['subject_table']);
        self::assertSame(2, (int)$tasks[0]['subject_uid']);
        // Nobody planned this - it opened itself.
        self::assertSame(1, (int)$tasks[0]['auto_created']);
    }

    #[Test]
    public function editingOnLiveDoesNotCreateATask(): void
    {
        $this->editInWorkspace('pages', 2, ['title' => 'Live edit'], workspaceUid: 0);

        self::assertSame([], $this->selectAll('tx_contentflow_task'));
    }

    #[Test]
    public function aPagesTaskAlsoCoversTheContentOnThatPage(): void
    {
        $this->editInWorkspace('pages', 2, ['title' => 'About us (revised)']);

        $members = $this->selectAll('tx_contentflow_task_item');
        $claimed = array_map(
            static fn (array $row): string => $row['record_table'] . ':' . $row['record_uid'],
            $members,
        );

        // The page itself, plus both content elements sitting on it - one card
        // covering "this page and everything on it".
        self::assertContains('pages:2', $claimed);
        self::assertContains('tt_content:10', $claimed);
        self::assertContains('tt_content:11', $claimed);
    }

    #[Test]
    public function editingAContentElementJoinsItsPagesTaskInsteadOfOpeningItsOwn(): void
    {
        $this->editInWorkspace('tt_content', 10, ['header' => 'Intro text (revised)']);

        $tasks = $this->selectAll('tx_contentflow_task');
        self::assertCount(1, $tasks, 'a content element must not get a card of its own');
        self::assertSame('pages', $tasks[0]['subject_table']);
        self::assertSame(2, (int)$tasks[0]['subject_uid'], 'it should belong to the page it sits on');
    }

    #[Test]
    public function editingTwoElementsOnTheSamePageKeepsOneTask(): void
    {
        $this->editInWorkspace('tt_content', 10, ['header' => 'first change']);
        $this->editInWorkspace('tt_content', 11, ['header' => 'second change']);

        self::assertCount(
            1,
            $this->selectAll('tx_contentflow_task'),
            'the board must not flood with a card per content element',
        );
    }

    #[Test]
    public function aRecordBelongsToAtMostOneOpenTask(): void
    {
        $this->editInWorkspace('pages', 2, ['title' => 'About us (revised)']);

        $connection = $this->getConnectionPool()->getConnectionForTable('tx_contentflow_task_item');
        $duplicates = $connection->executeQuery(
            'SELECT record_table, record_uid, COUNT(*) AS amount'
            . ' FROM tx_contentflow_task_item WHERE closed = 0 AND deleted = 0'
            . ' GROUP BY record_table, record_uid HAVING amount > 1',
        )->fetchAllAssociative();

        self::assertSame([], $duplicates, 'the unique key must prevent double membership');
    }

    #[Test]
    public function theTaskMovesToInProgressOnceAVersionExists(): void
    {
        $this->editInWorkspace('pages', 2, ['title' => 'About us (revised)']);

        $tasks = $this->selectAll('tx_contentflow_task');
        self::assertSame('in_progress', $tasks[0]['state']);
        self::assertSame(1, (int)$tasks[0]['workspace_uid']);
    }

    #[Test]
    public function startingWorkIsRecordedInTheActivityTrail(): void
    {
        $this->editInWorkspace('pages', 2, ['title' => 'About us (revised)']);

        $events = array_column($this->selectAll('tx_contentflow_activity'), 'event');

        self::assertContains('task_created', $events);
        self::assertContains('work_started', $events);
    }
}
