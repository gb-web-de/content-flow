<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Tests\Functional\Service;

use GbWeb\EditorialFlow\Service\WorkspaceConflictDetector;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The gap this feature closes: TaskAutoCreationService::captureEdit() claims a
 * live record onto whichever task already has it (see findOpenTaskByMember() at
 * the top of that method), regardless of which workspace that task's own
 * pending version lives in. A second workspace independently versioning the
 * same live record is therefore accepted by core but never surfaces anywhere in
 * Editorial Flow's own bookkeeping - these tests reproduce exactly that path and
 * prove WorkspaceConflictDetector still finds it, because it reads
 * t3ver_oid/t3ver_wsid directly rather than trusting task membership.
 */
final class WorkspaceConflictDetectorTest extends FunctionalTestCase
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
        'gb-web/editorial-flow',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->setUpBackendUser(1);
        $this->createSecondWorkspace();
    }

    private function subject(): WorkspaceConflictDetector
    {
        return $this->get(WorkspaceConflictDetector::class);
    }

    /**
     * pages.csv already ships workspace uid=1 ("Editorial") - a second,
     * independent workspace is what makes the conflict scenario possible.
     */
    private function createSecondWorkspace(): void
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('sys_workspace');
        $connection->insert('sys_workspace', [
            'uid' => 2,
            'pid' => 0,
            'title' => 'Legal',
            'deleted' => 0,
        ]);
    }

    /**
     * Edit a page inside a workspace, the way the backend form does - same
     * helper as TaskAutoCreationTest, since this reproduces the same
     * DataHandler path.
     */
    private function editInWorkspace(string $table, int $uid, array $fields, int $workspaceUid): void
    {
        $GLOBALS['BE_USER']->setWorkspace($workspaceUid);

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([$table => [$uid => $fields]], []);
        $dataHandler->process_datamap();
    }

    #[Test]
    public function aRecordEditedInOnlyOneWorkspaceIsNotAConflict(): void
    {
        $this->editInWorkspace('pages', 2, ['title' => 'About us (Editorial draft)'], 1);

        self::assertSame([1], $this->subject()->findPendingWorkspaces('pages', 2));
        self::assertSame([], $this->subject()->findConflicts(['pages' => [2]]));
    }

    #[Test]
    public function editingTheSameRecordInTwoWorkspacesIsDetectedAsAConflict(): void
    {
        $this->editInWorkspace('pages', 2, ['title' => 'About us (Editorial draft)'], 1);
        // Reproduces the captureEdit() gap described in the class docblock:
        // this second save must not throw or corrupt the first workspace's task.
        $this->editInWorkspace('pages', 2, ['title' => 'About us (Legal draft)'], 2);

        self::assertSame([1, 2], $this->subject()->findPendingWorkspaces('pages', 2));
        self::assertSame(['pages' => [2 => [1, 2]]], $this->subject()->findConflicts(['pages' => [2]]));

        $taskQueryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('tx_editorialflow_task');
        $taskQueryBuilder->getRestrictions()->removeAll();
        $tasks = $taskQueryBuilder
            ->select('*')->from('tx_editorialflow_task')->executeQuery()->fetchAllAssociative();
        self::assertCount(1, $tasks, 'the pre-existing bug this feature works around: workspace 2 gets no task of its own');
        self::assertSame(1, (int)$tasks[0]['workspace_uid'], 'the one task that exists still only knows about workspace 1');
    }

    #[Test]
    public function discardingOneSideMakesTheConflictDisappearWithoutAnyFlagToClean(): void
    {
        $this->editInWorkspace('pages', 2, ['title' => 'About us (Editorial draft)'], 1);
        $this->editInWorkspace('pages', 2, ['title' => 'About us (Legal draft)'], 2);
        self::assertCount(2, $this->subject()->findPendingWorkspaces('pages', 2));

        // Discard workspace 1's version the way core's own "Discard" action does:
        // a cmdmap version.clearWSID command.
        $GLOBALS['BE_USER']->setWorkspace(1);
        $versionUid = $this->findVersionUid('pages', 2, 1);
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], ['pages' => [$versionUid => ['version' => ['action' => 'clearWSID']]]]);
        $dataHandler->process_cmdmap();

        self::assertSame(
            [2],
            $this->subject()->findPendingWorkspaces('pages', 2),
            'the query-based detector self-corrects - nothing had to be told the conflict was resolved',
        );
    }

    #[Test]
    public function findConflictsForTasksAttributesTheConflictToTheOwningTaskAndExcludesItsOwnWorkspaceFromTheLabel(): void
    {
        $this->editInWorkspace('pages', 2, ['title' => 'About us (Editorial draft)'], 1);
        $this->editInWorkspace('pages', 2, ['title' => 'About us (Legal draft)'], 2);

        $taskQueryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('tx_editorialflow_task');
        $taskQueryBuilder->getRestrictions()->removeAll();
        $task = $taskQueryBuilder
            ->select('*')->from('tx_editorialflow_task')->executeQuery()->fetchAssociative();
        $taskUid = (int)$task['uid'];

        $memberQueryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('tx_editorialflow_task_item');
        $memberQueryBuilder->getRestrictions()->removeAll();
        $members = $memberQueryBuilder
            ->select('*')->from('tx_editorialflow_task_item')
            ->where($memberQueryBuilder->expr()->eq('task', $memberQueryBuilder->createNamedParameter($taskUid)))
            ->executeQuery()->fetchAllAssociative();

        $result = $this->subject()->findConflictsForTasks(
            [$taskUid => $members],
            [$taskUid => (int)$task['workspace_uid']],
        );

        self::assertTrue($result[$taskUid]['hasConflict']);
        self::assertSame('pages', $result[$taskUid]['conflictTable']);
        self::assertSame(2, $result[$taskUid]['conflictUid']);
        // Task belongs to workspace 1 - the label names the OTHER workspace,
        // not a redundant "also edited in your own workspace".
        self::assertSame('Legal', $result[$taskUid]['conflictLabel']);
    }

    #[Test]
    public function resolveWorkspaceTitlesFallsBackToAHashUidForAMissingWorkspace(): void
    {
        self::assertSame([404 => '#404'], $this->subject()->resolveWorkspaceTitles([404]));
    }

    private function findVersionUid(string $table, int $liveUid, int $workspaceUid): int
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $row = $queryBuilder
            ->select('uid')
            ->from($table)
            ->where(
                $queryBuilder->expr()->eq('t3ver_oid', $queryBuilder->createNamedParameter($liveUid)),
                $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter($workspaceUid)),
            )
            ->executeQuery()
            ->fetchAssociative();

        self::assertIsArray($row, sprintf('no version of %s:%d found in workspace %d', $table, $liveUid, $workspaceUid));

        return (int)$row['uid'];
    }
}
