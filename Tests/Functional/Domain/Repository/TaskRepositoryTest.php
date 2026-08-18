<?php

declare(strict_types=1);

namespace GbWeb\ContentFlow\Tests\Functional\Domain\Repository;

use GbWeb\ContentFlow\Domain\Repository\TaskRepository;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * tx_contentflow_task and tx_contentflow_task_item have no TCA, so
 * DeletedRestriction is a silent no-op for both - every read here must filter
 * `deleted` explicitly or a soft-deleted task/member keeps showing up.
 */
final class TaskRepositoryTest extends FunctionalTestCase
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

    private function subject(): TaskRepository
    {
        return new TaskRepository($this->get(ConnectionPool::class));
    }

    private function createTask(array $overrides = []): int
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('tx_contentflow_task');
        $connection->insert('tx_contentflow_task', array_merge([
            'title' => 'About us',
            'subject_table' => 'pages',
            'subject_uid' => 2,
            'subject_pid' => 2,
            'state' => 'backlog',
        ], $overrides));

        return (int)$connection->lastInsertId();
    }

    private function softDeleteTask(int $taskUid): void
    {
        $this->getConnectionPool()->getConnectionForTable('tx_contentflow_task')->update(
            'tx_contentflow_task',
            ['deleted' => 1],
            ['uid' => $taskUid],
        );
    }

    private function softDeleteMember(int $recordUid, string $recordTable = 'tt_content'): void
    {
        $this->getConnectionPool()->getConnectionForTable('tx_contentflow_task_item')->update(
            'tx_contentflow_task_item',
            ['deleted' => 1],
            ['record_table' => $recordTable, 'record_uid' => $recordUid],
        );
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function addRawMember(int $taskUid, int $recordUid, array $overrides = []): int
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('tx_contentflow_task_item');
        $connection->insert('tx_contentflow_task_item', array_merge([
            'task' => $taskUid,
            'record_table' => 'tt_content',
            'record_uid' => $recordUid,
            'pid' => 2,
            'home_pid' => 2,
            'closed' => 0,
            'deleted' => 0,
        ], $overrides));

        return (int)$connection->lastInsertId();
    }

    /**
     * @return array<string, mixed>|false
     */
    private function findItem(int $itemUid): array|false
    {
        return $this->getConnectionPool()
            ->getConnectionForTable('tx_contentflow_task_item')
            ->select(['closed', 'deleted'], 'tx_contentflow_task_item', ['uid' => $itemUid])
            ->fetchAssociative();
    }

    #[Test]
    public function findByUidDoesNotReturnASoftDeletedTask(): void
    {
        $taskUid = $this->createTask();
        $this->softDeleteTask($taskUid);

        self::assertNull($this->subject()->findByUid($taskUid));
    }

    #[Test]
    public function findOpenBySubjectDoesNotReturnASoftDeletedTask(): void
    {
        $taskUid = $this->createTask(['subject_table' => 'pages', 'subject_uid' => 5]);
        $this->softDeleteTask($taskUid);

        self::assertNull($this->subject()->findOpenBySubject('pages', 5));
    }

    #[Test]
    public function findForBoardDoesNotReturnASoftDeletedTask(): void
    {
        $taskUid = $this->createTask(['subject_pid' => 2]);
        $this->softDeleteTask($taskUid);

        self::assertSame([], $this->subject()->findForBoard([2]));
    }

    #[Test]
    public function findForBoardReturnsEmptyForAnEmptyPageUidList(): void
    {
        $this->createTask(['subject_pid' => 2]);

        self::assertSame([], $this->subject()->findForBoard([]));
    }

    #[Test]
    public function findForBoardCollectsTasksAcrossMultiplePages(): void
    {
        // The board scope (depth/root scanning, see BoardScopeResolver) resolves
        // to a list of page uids, not one page - this is the query that must
        // aggregate across all of them in a single call.
        $homeTaskUid = $this->createTask(['subject_pid' => 1, 'subject_uid' => 1]);
        $aboutUsTaskUid = $this->createTask(['subject_pid' => 2, 'subject_uid' => 2]);

        $foundUids = array_map(
            static fn (array $task): int => (int)$task['uid'],
            $this->subject()->findForBoard([1, 2]),
        );

        self::assertEqualsCanonicalizing([$homeTaskUid, $aboutUsTaskUid], $foundUids);
    }

    #[Test]
    public function findForBoardIncludesClosedTasksSoTheyCanLandInDone(): void
    {
        // findOpenForBoard() used to filter these out entirely, which was the
        // reason a published task vanished from the board instead of showing
        // up in the Done column - see ContentFlowController::belongsInColumn().
        $taskUid = $this->createTask(['subject_pid' => 2, 'closed' => 1, 'state' => 'done']);

        $foundUids = array_map(
            static fn (array $task): int => (int)$task['uid'],
            $this->subject()->findForBoard([2]),
        );

        self::assertSame([$taskUid], $foundUids);
    }

    #[Test]
    public function closeResetsWorkspaceAndStageUidSoTheTaskMatchesTheDoneColumn(): void
    {
        // BoardColumnRegistry's Done column, and ContentFlowController::
        // belongsInColumn(), both require workspace_uid === 0 for a
        // Content-Flow-owned state like 'done' to match - a closed task that
        // kept its old workspace/stage uid matched no column at all (or, worse,
        // a review-stage column it no longer belonged to).
        $taskUid = $this->createTask(['workspace_uid' => 1, 'stage_uid' => 2]);

        $this->subject()->close($taskUid, 1);

        $task = $this->subject()->findByUid($taskUid);
        self::assertSame(0, (int)$task['workspace_uid']);
        self::assertSame(0, (int)$task['stage_uid']);
        self::assertSame('done', $task['state']);
        self::assertSame(1, (int)$task['closed']);
    }

    /**
     * The publish AJAX endpoint 500'd on this: closing collided with a member
     * row closed earlier under a different task, and the two-step fallback
     * (retry with `deleted => 1` alone) itself collided with a row already
     * soft-deleted while still open - because `one_open_task_per_record`
     * covers `closed` and `deleted` together. The same fallback chain as
     * RepairTaskDataCommand::retireItem() is needed: closed -> closed+deleted
     * -> hard delete, the last of which can never collide.
     */
    #[Test]
    public function closeFallsThroughToHardDeleteWhenEveryOtherSlotIsTaken(): void
    {
        $history = $this->createTask(['title' => 'long finished', 'closed' => 1]);
        // Every escape route for tt_content:10 is already occupied: closed,
        // soft-deleted while open, and both at once.
        $this->addRawMember($history, 10, ['closed' => 1]);
        $this->addRawMember($history, 10, ['closed' => 0, 'deleted' => 1]);
        $this->addRawMember($history, 10, ['closed' => 1, 'deleted' => 1]);

        $taskUid = $this->createTask();
        $stranded = $this->addRawMember($taskUid, 10);

        $this->subject()->close($taskUid, 1);

        self::assertFalse($this->findItem($stranded), 'the row that could go nowhere else is gone');
    }

    #[Test]
    public function findOpenByAssigneeDoesNotReturnASoftDeletedTask(): void
    {
        $taskUid = $this->createTask(['assignee' => 7]);
        $this->softDeleteTask($taskUid);

        self::assertSame([], $this->subject()->findOpenByAssignee(7));
    }

    #[Test]
    public function findUnassignedDoesNotReturnASoftDeletedTask(): void
    {
        $taskUid = $this->createTask(['assignee' => 0]);
        $this->softDeleteTask($taskUid);

        self::assertSame([], $this->subject()->findUnassigned());
    }

    #[Test]
    public function findMembersDoesNotReturnASoftDeletedMember(): void
    {
        $taskUid = $this->createTask();
        $this->subject()->addMember($taskUid, 'tt_content', 10, TaskRepository::ORIGIN_AUTO);
        $this->softDeleteMember(10);

        self::assertSame([], $this->subject()->findMembers($taskUid));
    }

    #[Test]
    public function findOpenTaskByMemberDoesNotReturnATaskThroughASoftDeletedMember(): void
    {
        $taskUid = $this->createTask();
        $this->subject()->addMember($taskUid, 'tt_content', 11, TaskRepository::ORIGIN_AUTO);
        $this->softDeleteMember(11);

        self::assertNull($this->subject()->findOpenTaskByMember('tt_content', 11));
    }

    #[Test]
    public function aPendingRecordBecomesAnExactSubjectAndMember(): void
    {
        $task = $this->subject()->createPendingSubjectTask(2, 'tt_content', [
            'title' => 'New hero',
            'state' => 'planned',
        ]);
        $taskUid = (int)$task['uid'];

        $this->subject()->attachCreatedSubject($taskUid, 'tt_content', 25, 2);

        $attached = $this->subject()->findByUid($taskUid);
        self::assertSame('tt_content', $attached['subject_table']);
        self::assertSame(25, (int)$attached['subject_uid']);
        self::assertSame(2, (int)$attached['subject_pid']);
        self::assertSame($taskUid, (int)$this->subject()->findOpenTaskByMember('tt_content', 25)['uid']);
    }
}
