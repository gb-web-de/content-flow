<?php

declare(strict_types=1);

namespace GbWeb\ContentFlow\Tests\Functional\Updates;

use GbWeb\ContentFlow\Updates\MigrateExistingWorkspaceChangesToTasksUpdate;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * "Bring existing workspace changes to task as an initial import - everything on
 * one page in one task." Covers the upgrade wizard that backfills tasks for
 * workspace changes made before Content Flow was installed.
 */
final class MigrateExistingWorkspaceChangesToTasksUpdateTest extends FunctionalTestCase
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
    }

    private function subject(): MigrateExistingWorkspaceChangesToTasksUpdate
    {
        return GeneralUtility::makeInstance(MigrateExistingWorkspaceChangesToTasksUpdate::class);
    }

    /**
     * Directly inserts an offline version row, bypassing DataHandler and
     * therefore Content Flow's own capture hook - exactly the state a
     * pre-existing workspace change is in: real to core, invisible to
     * Content Flow until this wizard runs.
     *
     * @param array<string, mixed> $fields
     */
    private function insertVersion(string $table, int $liveUid, int $pid, array $fields, int $workspaceUid = 1, int $stage = 0): int
    {
        $connection = $this->getConnectionPool()->getConnectionForTable($table);
        $connection->insert($table, array_merge([
            'pid' => $pid,
            't3ver_oid' => $liveUid,
            't3ver_wsid' => $workspaceUid,
            't3ver_stage' => $stage,
        ], $fields));

        return (int)$connection->lastInsertId();
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
    public function updateIsNotNecessaryWithoutAnyWorkspaceVersion(): void
    {
        self::assertFalse($this->subject()->updateNecessary());
    }

    #[Test]
    public function updateIsNecessaryWhenAPendingVersionHasNoTaskYet(): void
    {
        $this->insertVersion('pages', 2, 1, ['title' => 'About us (pre-existing change)']);

        self::assertTrue($this->subject()->updateNecessary());
    }

    #[Test]
    public function groupsEveryChangeOnOnePageIntoOneTask(): void
    {
        $this->insertVersion('pages', 2, 1, ['title' => 'About us (pre-existing change)']);
        $this->insertVersion('tt_content', 11, 2, ['header' => 'Second element (pre-existing change)']);

        self::assertTrue($this->subject()->executeUpdate());

        $tasks = $this->selectAll('tx_contentflow_task');
        self::assertCount(1, $tasks, 'one page task should cover both pre-existing changes');
        self::assertSame('pages', $tasks[0]['subject_table']);
        self::assertSame(2, (int)$tasks[0]['subject_uid']);
        self::assertSame(1, (int)$tasks[0]['workspace_uid']);
        self::assertSame('in_progress', $tasks[0]['state']);

        $claimed = array_map(
            static fn (array $row): string => $row['record_table'] . ':' . $row['record_uid'],
            $this->selectAll('tx_contentflow_task_item'),
        );
        // The page, both content elements it aggregates automatically, and the
        // element whose own version triggered the task in the first place.
        self::assertContains('pages:2', $claimed);
        self::assertContains('tt_content:10', $claimed);
        self::assertContains('tt_content:11', $claimed);
    }

    #[Test]
    public function aContentElementVersionAloneOpensItsPagesTaskNotItsOwn(): void
    {
        $this->insertVersion('tt_content', 10, 2, ['header' => 'Intro text (pre-existing change)']);

        self::assertTrue($this->subject()->executeUpdate());

        $tasks = $this->selectAll('tx_contentflow_task');
        self::assertCount(1, $tasks);
        self::assertSame('pages', $tasks[0]['subject_table']);
        self::assertSame(2, (int)$tasks[0]['subject_uid']);
    }

    #[Test]
    public function aVersionAlreadyInReviewIsMigratedStraightIntoThatStage(): void
    {
        // Stage 1 is a custom Review stage in this fixture's terms - see
        // TaskState::fromStageId(). Nothing to regress past during a migration,
        // unlike a live edit landing on a task already past Editing.
        $this->insertVersion('pages', 2, 1, ['title' => 'About us (awaiting review)'], stage: 1);

        self::assertTrue($this->subject()->executeUpdate());

        self::assertSame('review', $this->selectAll('tx_contentflow_task')[0]['state']);
    }

    #[Test]
    public function runningTheWizardTwiceDoesNotDuplicateTasks(): void
    {
        $this->insertVersion('pages', 2, 1, ['title' => 'About us (pre-existing change)']);

        self::assertTrue($this->subject()->executeUpdate());
        self::assertTrue($this->subject()->executeUpdate());

        self::assertCount(1, $this->selectAll('tx_contentflow_task'));
    }

    #[Test]
    public function updateIsNoLongerNecessaryAfterMigrating(): void
    {
        $this->insertVersion('pages', 2, 1, ['title' => 'About us (pre-existing change)']);

        self::assertTrue($this->subject()->executeUpdate());

        self::assertFalse($this->subject()->updateNecessary());
    }

    #[Test]
    public function aVersionAlreadyCapturedByAnEarlierLiveEditIsNotMigratedAgain(): void
    {
        // An edit made after Content Flow was already installed - the live
        // capture hook already created a task for it, so the wizard must leave
        // it alone rather than opening a second one.
        $taskConnection = $this->getConnectionPool()->getConnectionForTable('tx_contentflow_task');
        $taskConnection->insert('tx_contentflow_task', [
            'title' => 'About us',
            'subject_table' => 'pages',
            'subject_uid' => 2,
            'subject_pid' => 2,
            'state' => 'in_progress',
            'workspace_uid' => 1,
            'closed' => 0,
        ]);
        $taskUid = (int)$taskConnection->lastInsertId();
        $this->getConnectionPool()->getConnectionForTable('tx_contentflow_task_item')->insert('tx_contentflow_task_item', [
            'task' => $taskUid,
            'record_table' => 'pages',
            'record_uid' => 2,
            'origin' => 'subject',
            'home_pid' => 2,
            'closed' => 0,
        ]);
        $this->insertVersion('pages', 2, 1, ['title' => 'About us (already captured)']);

        self::assertFalse($this->subject()->updateNecessary());
        self::assertTrue($this->subject()->executeUpdate());
        self::assertCount(1, $this->selectAll('tx_contentflow_task'), 'no second task should appear');
    }
}
