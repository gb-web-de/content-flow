<?php

declare(strict_types=1);

namespace GbWeb\ContentFlow\Tests\Functional\Domain\Repository;

use GbWeb\ContentFlow\Domain\Repository\CommentRepository;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class CommentRepositoryTest extends FunctionalTestCase
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

    /**
     * Constructed directly rather than pulled from the container: the repository
     * is only ever injected privately, so the test container's service locator
     * does not expose it. Making it public just for tests would change production
     * wiring to suit the test, which is the wrong way round.
     */
    private function subject(): CommentRepository
    {
        return new CommentRepository($this->get(ConnectionPool::class));
    }

    private function createTask(): int
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('tx_contentflow_task');
        $connection->insert('tx_contentflow_task', [
            'title' => 'About us',
            'subject_table' => 'pages',
            'subject_uid' => 2,
            'subject_pid' => 2,
            'state' => 'backlog',
            'comments' => 0,
        ]);

        return (int)$connection->lastInsertId();
    }

    private function commentCounterOf(int $taskUid): int
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('tx_contentflow_task');
        $queryBuilder->getRestrictions()->removeAll();

        return (int)$queryBuilder
            ->select('comments')
            ->from('tx_contentflow_task')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($taskUid)))
            ->executeQuery()
            ->fetchOne();
    }

    #[Test]
    public function aCommentIsStoredAndFoundAgain(): void
    {
        $taskUid = $this->createTask();
        $this->subject()->add($taskUid, 'Images are still missing.', 1);

        $comments = $this->subject()->findByTask($taskUid);

        self::assertCount(1, $comments);
        self::assertSame('Images are still missing.', $comments[0]['content']);
    }

    #[Test]
    public function anAnchoredCommentKeepsItsLinkToTheAction(): void
    {
        $taskUid = $this->createTask();
        $this->subject()->add($taskUid, 'Sent back.', 1, activityUid: 42);

        $comments = $this->subject()->findByTask($taskUid);

        // The anchor is what lets the ticket nest this under the stage change it
        // explains instead of listing it separately.
        self::assertSame(42, (int)$comments[0]['activity']);
    }

    #[Test]
    public function aStandaloneCommentHasNoAnchor(): void
    {
        $taskUid = $this->createTask();
        $this->subject()->add($taskUid, 'Just a remark.', 1);

        self::assertSame(0, (int)$this->subject()->findByTask($taskUid)[0]['activity']);
    }

    /**
     * The counter is incremented in SQL rather than read-modify-write, precisely so
     * concurrent posts cannot lose each other. Several sequential adds are the
     * cheapest way to show the counter tracks reality.
     */
    #[Test]
    public function theDenormalisedCounterKeepsUpWithTheComments(): void
    {
        $taskUid = $this->createTask();
        $this->subject()->add($taskUid, 'one', 1);
        $this->subject()->add($taskUid, 'two', 1);
        $this->subject()->add($taskUid, 'three', 1);

        self::assertSame(3, $this->commentCounterOf($taskUid));
    }
}
