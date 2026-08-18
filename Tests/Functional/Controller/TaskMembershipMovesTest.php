<?php

declare(strict_types=1);

namespace GbWeb\ContentFlow\Tests\Functional\Controller;

use GbWeb\ContentFlow\Controller\TaskAjaxController;
use GbWeb\ContentFlow\Domain\Repository\TaskRepository;
use GbWeb\ContentFlow\Service\ActivityLogger;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Moving a record between tasks, and the one promise those operations make:
 * nothing an editor has typed is lost by them.
 *
 * That promise is structural rather than careful - the workspace version hangs
 * on the RECORD and a task only ever holds a membership row pointing at it - but
 * "structural" is exactly the kind of claim that quietly stops being true, so it
 * is asserted here against a real version created by core.
 */
final class TaskMembershipMovesTest extends FunctionalTestCase
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
        // setWorkspace(), not ->workspace =, so workspaceRec is populated and
        // DataHandler actually versions - see AGENDA.md.
        $GLOBALS['BE_USER']->setWorkspace(1);
        // The refusal message and the picker's stage labels are translated, and
        // sL() has nowhere to go without this.
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)
            ->createFromUserPreferences($GLOBALS['BE_USER']);
    }

    #[Test]
    public function splittingARecordKeepsItsPendingVersionAndItsChanges(): void
    {
        $pageTaskUid = $this->editInWorkspace(10, 'Intro text (revised)');
        $versionUid = $this->versionUidOf('tt_content', 10);
        self::assertGreaterThan(0, $versionUid, 'editing should have produced a workspace version');

        $payload = $this->decode($this->subject()->detachAction($this->jsonRequest([
            'table' => 'tt_content',
            'uid' => 10,
        ])));
        self::assertTrue($payload['success']);
        self::assertNotSame($pageTaskUid, (int)$payload['task']);

        // The version is untouched: same row, same edited value. A split that
        // discarded work would show up here as a missing row or a stale header.
        self::assertSame($versionUid, $this->versionUidOf('tt_content', 10));
        self::assertSame('Intro text (revised)', $this->headerOf($versionUid));

        // ... and the record now belongs to the new task, not to the page's.
        $member = $this->get(TaskRepository::class)->findOpenTaskByMember('tt_content', 10);
        self::assertNotNull($member);
        self::assertSame((int)$payload['task'], (int)$member['uid']);
    }

    #[Test]
    public function movingARecordOntoAnotherTaskKeepsItsPendingVersion(): void
    {
        $pageTaskUid = $this->editInWorkspace(10, 'Intro text (revised)');
        $versionUid = $this->versionUidOf('tt_content', 10);
        $otherTaskUid = $this->createTask(['title' => 'Campaign copy']);

        $payload = $this->decode($this->subject()->attachAction($this->jsonRequest([
            'task' => $otherTaskUid,
            'records' => [['table' => 'tt_content', 'uid' => 10]],
        ])));

        self::assertTrue($payload['success']);
        self::assertSame([], $payload['refused']);
        self::assertSame($versionUid, $this->versionUidOf('tt_content', 10));
        self::assertSame('Intro text (revised)', $this->headerOf($versionUid));

        $member = $this->get(TaskRepository::class)->findOpenTaskByMember('tt_content', 10);
        self::assertSame($otherTaskUid, (int)$member['uid']);
        self::assertNotSame($pageTaskUid, (int)$member['uid']);
    }

    #[Test]
    public function theSplitDialogsFieldsBecomeTheNewTask(): void
    {
        $this->editInWorkspace(10, 'Intro text (revised)');

        $payload = $this->decode($this->subject()->detachAction($this->jsonRequest([
            'table' => 'tt_content',
            'uid' => 10,
            'title' => 'Rewrite the intro',
            'description' => 'Shorter, and without the buzzwords.',
            'assignee' => 2,
        ])));

        $task = $this->get(TaskRepository::class)->findByUid((int)$payload['task']);
        self::assertNotNull($task);
        self::assertSame('Rewrite the intro', $task['title']);
        self::assertSame('Shorter, and without the buzzwords.', $task['description']);
        self::assertSame(2, (int)$task['assignee']);
    }

    /**
     * Omitting them is still valid - the wizard route and any caller that just
     * wants the record out keep the derived title and the acting editor.
     */
    #[Test]
    public function splittingWithoutDetailsFallsBackToTheRecordsOwnTitle(): void
    {
        $this->editInWorkspace(10, 'Intro text (revised)');

        $payload = $this->decode($this->subject()->detachAction($this->jsonRequest([
            'table' => 'tt_content',
            'uid' => 10,
        ])));

        $task = $this->get(TaskRepository::class)->findByUid((int)$payload['task']);
        // The LIVE title, which is what deriveTitle() reads here as it does
        // everywhere else in this controller - the pending version's own header
        // is exactly what the dialog offers to change.
        self::assertSame('Intro text', $task['title']);
        self::assertSame(1, (int)$task['assignee'], 'the acting editor takes it');
    }

    /**
     * A task bound to another workspace cannot receive work: its records would
     * be unreachable from here, and the ticket could only say "switch workspace".
     * Checked once, on the task, rather than per record - the condition is a
     * property of the target, not of anything handed to it.
     */
    #[Test]
    public function movingIntoATaskOfAnotherWorkspaceIsRefusedByName(): void
    {
        $this->editInWorkspace(10, 'Intro text (revised)');
        $foreignTaskUid = $this->createTask(['title' => 'Other workspace', 'workspace_uid' => 2]);

        $response = $this->subject()->attachAction($this->jsonRequest([
            'task' => $foreignTaskUid,
            'records' => [['table' => 'tt_content', 'uid' => 10]],
        ]));
        $payload = $this->decode($response);

        self::assertSame(400, $response->getStatusCode());
        self::assertFalse($payload['success']);
        self::assertSame('task-in-other-workspace', $payload['code']);

        // Nothing moved.
        $member = $this->get(TaskRepository::class)->findOpenTaskByMember('tt_content', 10);
        self::assertNotSame($foreignTaskUid, (int)$member['uid']);
    }

    #[Test]
    public function theMovePickerOffersTheOtherOpenTasksButNotTheCurrentOne(): void
    {
        $pageTaskUid = $this->editInWorkspace(10, 'Intro text (revised)');
        $otherTaskUid = $this->createTask(['title' => 'Campaign copy']);
        $foreignTaskUid = $this->createTask(['title' => 'Other workspace', 'workspace_uid' => 2]);

        $payload = $this->decode($this->subject()->moveTargetsAction($this->queryRequest([
            'table' => 'tt_content',
            'uid' => 10,
        ])));

        self::assertTrue($payload['success']);
        self::assertSame($pageTaskUid, (int)$payload['currentTask']);

        $offered = array_map(static fn (array $task): int => (int)$task['uid'], $payload['tasks']);
        self::assertContains($otherTaskUid, $offered);
        // Offering the task the record already sits in would be a list of one
        // wrong answer - which is why this cannot reuse openTasksForContext().
        self::assertNotContains($pageTaskUid, $offered);
        // Never offer what attachAction() would then refuse.
        self::assertNotContains($foreignTaskUid, $offered);
    }

    /**
     * Both tasks, because otherwise the source task's trail simply loses a
     * record with no record of where it went - and this trail has to outlive
     * sys_history's garbage collection, which is the only other place a move
     * would be visible at all.
     */
    #[Test]
    public function aMoveIsWrittenToTheTrailOfBothTasks(): void
    {
        $pageTaskUid = $this->editInWorkspace(10, 'Intro text (revised)');
        $otherTaskUid = $this->createTask(['title' => 'Campaign copy']);

        $this->subject()->attachAction($this->jsonRequest([
            'task' => $otherTaskUid,
            'records' => [['table' => 'tt_content', 'uid' => 10]],
        ]));

        self::assertCount(1, $this->activityEvents($pageTaskUid, ActivityLogger::EVENT_MEMBER_MOVED));
        $arrival = $this->activityEvents($otherTaskUid, ActivityLogger::EVENT_MEMBER_MOVED);
        self::assertCount(1, $arrival);

        $payload = json_decode((string)$arrival[0]['payload'], true);
        self::assertSame('tt_content', $payload['table']);
        self::assertSame(10, (int)$payload['recordUid']);
        self::assertSame($pageTaskUid, (int)$payload['fromTask']);
        self::assertSame($otherTaskUid, (int)$payload['toTask']);
    }

    #[Test]
    public function aSplitIsWrittenToTheTrailOfBothTasks(): void
    {
        $pageTaskUid = $this->editInWorkspace(10, 'Intro text (revised)');

        $payload = $this->decode($this->subject()->detachAction($this->jsonRequest([
            'table' => 'tt_content',
            'uid' => 10,
        ])));
        $newTaskUid = (int)$payload['task'];

        self::assertCount(1, $this->activityEvents($pageTaskUid, ActivityLogger::EVENT_MEMBER_SPLIT));
        self::assertCount(1, $this->activityEvents($newTaskUid, ActivityLogger::EVENT_MEMBER_SPLIT));
        self::assertCount(1, $this->activityEvents($newTaskUid, ActivityLogger::EVENT_TASK_CREATED));
    }

    private function subject(): TaskAjaxController
    {
        return $this->get(TaskAjaxController::class);
    }

    /**
     * Edit a content element the way an editor would, so the workspace version
     * and the page's task both come into existence through the real path
     * (DataHandler plus TaskAutoCreationDataHandlerHook).
     *
     * @return int the task that claimed the record
     */
    private function editInWorkspace(int $uid, string $header): int
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start(['tt_content' => [$uid => ['header' => $header]]], []);
        $dataHandler->process_datamap();
        self::assertSame([], $dataHandler->errorLog);

        $task = $this->get(TaskRepository::class)->findOpenTaskByMember('tt_content', $uid);
        self::assertNotNull($task, 'editing should have opened a task for the record');

        return (int)$task['uid'];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createTask(array $overrides = []): int
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('tx_contentflow_task');
        $connection->insert('tx_contentflow_task', array_merge([
            'title' => 'Another task',
            'subject_table' => 'pages',
            'subject_uid' => 2,
            'subject_pid' => 2,
            'state' => 'backlog',
            'workspace_uid' => 1,
            'closed' => 0,
        ], $overrides));

        return (int)$connection->lastInsertId();
    }

    private function versionUidOf(string $table, int $liveUid): int
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();

        return (int)$queryBuilder
            ->select('uid')
            ->from($table)
            ->where(
                $queryBuilder->expr()->eq('t3ver_oid', $queryBuilder->createNamedParameter($liveUid)),
                $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter(1)),
            )
            ->executeQuery()
            ->fetchOne();
    }

    private function headerOf(int $uid): string
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll();

        return (string)$queryBuilder
            ->select('header')
            ->from('tt_content')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid)))
            ->executeQuery()
            ->fetchOne();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function activityEvents(int $taskUid, string $event): array
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('tx_contentflow_activity');
        $queryBuilder->getRestrictions()->removeAll();

        return $queryBuilder
            ->select('*')
            ->from('tx_contentflow_activity')
            ->where(
                $queryBuilder->expr()->eq('task', $queryBuilder->createNamedParameter($taskUid)),
                $queryBuilder->expr()->eq('event', $queryBuilder->createNamedParameter($event)),
            )
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * @param array<string, mixed> $body
     */
    private function jsonRequest(array $body): ServerRequestInterface
    {
        return (new ServerRequest())->withParsedBody($body)->withMethod('POST');
    }

    /**
     * @param array<string, string|int> $query
     */
    private function queryRequest(array $query): ServerRequestInterface
    {
        return (new ServerRequest())->withQueryParams($query);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(ResponseInterface $response): array
    {
        $decoded = json_decode((string)$response->getBody(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
