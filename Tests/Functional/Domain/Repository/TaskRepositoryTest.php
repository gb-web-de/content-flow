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
    public function findOpenForBoardDoesNotReturnASoftDeletedTask(): void
    {
        $taskUid = $this->createTask(['subject_pid' => 2]);
        $this->softDeleteTask($taskUid);

        self::assertSame([], $this->subject()->findOpenForBoard([2]));
    }

    #[Test]
    public function findOpenForBoardReturnsEmptyForAnEmptyPageUidList(): void
    {
        $this->createTask(['subject_pid' => 2]);

        self::assertSame([], $this->subject()->findOpenForBoard([]));
    }

    #[Test]
    public function findOpenForBoardCollectsTasksAcrossMultiplePages(): void
    {
        // The board scope (depth/root scanning, see BoardScopeResolver) resolves
        // to a list of page uids, not one page - this is the query that must
        // aggregate across all of them in a single call.
        $homeTaskUid = $this->createTask(['subject_pid' => 1, 'subject_uid' => 1]);
        $aboutUsTaskUid = $this->createTask(['subject_pid' => 2, 'subject_uid' => 2]);

        $foundUids = array_map(
            static fn (array $task): int => (int)$task['uid'],
            $this->subject()->findOpenForBoard([1, 2]),
        );

        self::assertEqualsCanonicalizing([$homeTaskUid, $aboutUsTaskUid], $foundUids);
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
}
