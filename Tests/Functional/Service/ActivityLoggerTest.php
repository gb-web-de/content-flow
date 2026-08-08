<?php

declare(strict_types=1);

namespace GbWeb\ContentFlow\Tests\Functional\Service;

use GbWeb\ContentFlow\Service\ActivityLogger;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class ActivityLoggerTest extends FunctionalTestCase
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

    private function subject(): ActivityLogger
    {
        return new ActivityLogger($this->get(ConnectionPool::class));
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
        ]);

        return (int)$connection->lastInsertId();
    }

    #[Test]
    public function loggedEntriesAreFoundAgain(): void
    {
        $taskUid = $this->createTask();
        $this->subject()->log($taskUid, ActivityLogger::EVENT_WORK_STARTED, 1);

        $activities = $this->subject()->findByTask($taskUid);

        self::assertCount(1, $activities);
        self::assertSame(ActivityLogger::EVENT_WORK_STARTED, $activities[0]['event']);
    }

    /**
     * tx_contentflow_activity has no TCA, so DeletedRestriction is a silent no-op
     * for it - findByTask() must filter `deleted` explicitly or a soft-deleted
     * entry keeps showing up in the ticket timeline.
     */
    #[Test]
    public function aSoftDeletedEntryIsExcluded(): void
    {
        $taskUid = $this->createTask();
        $activityUid = $this->subject()->log($taskUid, ActivityLogger::EVENT_CLOSED, 1);
        $this->getConnectionPool()->getConnectionForTable('tx_contentflow_activity')->update(
            'tx_contentflow_activity',
            ['deleted' => 1],
            ['uid' => $activityUid],
        );

        self::assertSame([], $this->subject()->findByTask($taskUid));
    }
}
