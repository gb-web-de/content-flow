<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Tests\Functional\Service;

use GbWeb\EditorialFlow\Service\ActivityLogger;
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
        'gb-web/editorial-flow',
    ];

    private function subject(): ActivityLogger
    {
        return new ActivityLogger($this->get(ConnectionPool::class));
    }

    private function createTask(): int
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('tx_editorialflow_task');
        $connection->insert('tx_editorialflow_task', [
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
     * tx_editorialflow_activity has no TCA, so DeletedRestriction is a silent no-op
     * for it - findByTask() must filter `deleted` explicitly or a soft-deleted
     * entry keeps showing up in the ticket timeline.
     */
    #[Test]
    public function aSoftDeletedEntryIsExcluded(): void
    {
        $taskUid = $this->createTask();
        $activityUid = $this->subject()->log($taskUid, ActivityLogger::EVENT_CLOSED, 1);
        $this->getConnectionPool()->getConnectionForTable('tx_editorialflow_activity')->update(
            'tx_editorialflow_activity',
            ['deleted' => 1],
            ['uid' => $activityUid],
        );

        self::assertSame([], $this->subject()->findByTask($taskUid));
    }

    /**
     * The insert has to name `payload` as JSON itself rather than let
     * Connection::insert() infer it from the column schema. On MariaDB a `json`
     * column without a `json_valid` CHECK constraint is reported as plain text,
     * the encode is skipped, and mysqli is handed a raw PHP array - which is how
     * publishing died with "Array to string conversion" deep inside
     * CloseTaskAfterPublishListener, taking the surrounding task setup with it.
     *
     * Asserting the stored value is exactly one layer of JSON also pins the other
     * half: encoding it here as well would store a double-encoded string, which is
     * what WorkspaceIntegrationService::decodePayload() still has to peel for old
     * rows.
     */
    #[Test]
    public function aPayloadIsStoredAsPlainSingleEncodedJson(): void
    {
        $taskUid = $this->createTask();
        $payload = ['workspaceId' => 1, 'table' => 'tt_content', 'liveUid' => 46];

        $activityUid = $this->subject()->log($taskUid, ActivityLogger::EVENT_CLOSED, 1, $payload);

        $stored = $this->getConnectionPool()
            ->getConnectionForTable('tx_editorialflow_activity')
            ->select(['payload'], 'tx_editorialflow_activity', ['uid' => $activityUid])
            ->fetchOne();

        self::assertIsString($stored);
        self::assertSame($payload, json_decode($stored, true));
    }
}
