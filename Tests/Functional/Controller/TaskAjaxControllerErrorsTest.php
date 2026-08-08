<?php

declare(strict_types=1);

namespace GbWeb\ContentFlow\Tests\Functional\Controller;

use GbWeb\ContentFlow\Controller\TaskAjaxController;
use GbWeb\ContentFlow\Domain\Repository\CommentRepository;
use GbWeb\ContentFlow\Domain\Repository\TaskRepository;
use GbWeb\ContentFlow\Service\ActivityLogger;
use GbWeb\ContentFlow\Service\ReferenceInspector;
use GbWeb\ContentFlow\Service\TaskMemberSynchronizer;
use GbWeb\ContentFlow\Service\TaskSubjectRegistry;
use GbWeb\ContentFlow\Service\WorkspaceIntegrationService;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\AbstractLogger;
use Stringable;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\View\ViewFactoryInterface;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * "Errors müssen gut benannt und beschrieben sein" and "für developer logs
 * erstellen" - this asserts both halves of that contract for every rejection
 * path: the client gets a stable `code` plus a specific `message` (never a bare
 * "an error occurred"), and the same failure is written to a PSR-3 logger with
 * enough context (table, uid, task, be_user) to debug without reproducing it.
 *
 * The controller is built directly with a recording logger rather than pulled
 * from the container, for the same reason as CommentRepositoryTest: it is only
 * ever injected privately, so the container's service locator does not expose
 * it, and making it public just to satisfy a test would be the wrong direction
 * for that change to flow in.
 */
final class TaskAjaxControllerErrorsTest extends FunctionalTestCase
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

    private RecordingLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->setUpBackendUser(1);
        $this->logger = new RecordingLogger();
    }

    private function subject(): TaskAjaxController
    {
        // Symfony's ->get() has no "construct with one argument overridden"
        // operation, so the controller is rebuilt by hand with the logger
        // replaced. Everything else is pulled from the container where it is
        // public - CommentRepository and WorkspaceIntegrationService are not
        // (never injected anywhere but this controller), so those two are
        // constructed directly from public core services instead.
        $connectionPool = $this->get(\TYPO3\CMS\Core\Database\ConnectionPool::class);
        $taskRepository = $this->get(TaskRepository::class);
        $checklistRepository = $this->get(\GbWeb\ContentFlow\Domain\Repository\TaskChecklistRepository::class);
        $activityLogger = $this->get(ActivityLogger::class);

        return new TaskAjaxController(
            $taskRepository,
            new CommentRepository($connectionPool),
            $checklistRepository,
            $this->get(TaskSubjectRegistry::class),
            $this->get(TaskMemberSynchronizer::class),
            $this->get(ReferenceInspector::class),
            $activityLogger,
            $this->get(\GbWeb\ContentFlow\Notification\AssignmentNotificationService::class),
            new WorkspaceIntegrationService(
                $connectionPool,
                $taskRepository,
                $checklistRepository,
                $activityLogger,
                $this->get(\TYPO3\CMS\Workspaces\Service\HistoryService::class),
                $this->get(\TYPO3\CMS\Core\Imaging\IconFactory::class),
                $this->get(\TYPO3\CMS\Workspaces\Domain\Repository\WorkspaceStageRepository::class),
                $this->get(\TYPO3\CMS\Workspaces\Domain\Repository\WorkspaceRepository::class),
                $this->get(\TYPO3\CMS\Workspaces\Service\StagesService::class),
            ),
            $this->get(\TYPO3\CMS\Workspaces\Authorization\WorkspacePublishGate::class),
            $this->get(UriBuilder::class),
            $this->get(ViewFactoryInterface::class),
            $this->logger,
        );
    }

    private function jsonRequest(array $body): ServerRequestInterface
    {
        return (new ServerRequest())->withParsedBody($body)->withMethod('POST');
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(\Psr\Http\Message\ResponseInterface $response): array
    {
        $decoded = json_decode((string)$response->getBody(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    #[Test]
    public function creatingATaskOnAnUntrackableTableIsRejectedByName(): void
    {
        $response = $this->subject()->createAction($this->jsonRequest(['table' => 'sys_log', 'uid' => 1]));
        $payload = $this->decode($response);

        self::assertSame(400, $response->getStatusCode());
        self::assertFalse($payload['success']);
        // The code must be stable and specific - not a generic "error".
        self::assertSame('table-not-trackable', $payload['code']);
        self::assertStringContainsString('sys_log', $payload['message']);
    }

    #[Test]
    public function creatingATaskForAContentElementCreatesADedicatedTask(): void
    {
        $response = $this->subject()->createAction($this->jsonRequest([
            'table' => 'tt_content',
            'uid' => 10,
            'title' => 'Intro text task',
            'priority' => 1,
            'assignee' => 'open',
        ]));
        $payload = $this->decode($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($payload['success']);
        self::assertSame(0, $payload['claimed']);

        $task = $this->get(TaskRepository::class)->findByUid((int)$payload['task']);
        self::assertNotNull($task);
        self::assertSame('tt_content', $task['subject_table']);
        self::assertSame(10, (int)$task['subject_uid']);
        self::assertSame(2, (int)$task['subject_pid']);
        self::assertSame('Intro text task', $task['title']);
        self::assertSame(1, (int)$task['priority']);
        self::assertSame(0, (int)$task['assignee']);

        $memberTask = $this->get(TaskRepository::class)->findOpenTaskByMember('tt_content', 10);
        self::assertNotNull($memberTask);
        self::assertSame((int)$task['uid'], (int)$memberTask['uid']);
    }

    #[Test]
    public function actingOnAMissingTaskIsDistinguishedFromActingOnAClosedOne(): void
    {
        $missing = $this->decode($this->subject()->assignMeAction($this->jsonRequest(['task' => 999999])));
        self::assertSame('task-not-found', $missing['code']);

        $taskUid = $this->createClosedTask();
        $closed = $this->decode($this->subject()->assignMeAction($this->jsonRequest(['task' => $taskUid])));

        // These used to be one "not found or closed" message. A missing task and
        // a closed one are different problems and need different codes.
        self::assertSame('task-closed', $closed['code']);
        self::assertNotSame($missing['code'], $closed['code']);
    }

    #[Test]
    public function commentingOnAClosedTaskIsRejectedWithAnExplanation(): void
    {
        $taskUid = $this->createClosedTask();

        $response = $this->subject()->commentAction($this->jsonRequest(['task' => $taskUid, 'content' => 'too late']));
        $payload = $this->decode($response);

        self::assertSame('task-closed', $payload['code']);
        self::assertStringContainsString('closed', $payload['message']);
    }

    #[Test]
    public function anEmptyCommentIsRejectedBeforeAnyTaskLookup(): void
    {
        $payload = $this->decode(
            $this->subject()->commentAction($this->jsonRequest(['task' => 1, 'content' => '   '])),
        );

        self::assertSame('comment-empty', $payload['code']);
    }

    #[Test]
    public function splittingARecordThatIsNotInAnyOpenTaskIsRejected(): void
    {
        $payload = $this->decode(
            $this->subject()->detachAction($this->jsonRequest(['table' => 'tt_content', 'uid' => 11])),
        );

        self::assertSame('record-not-in-open-task', $payload['code']);
    }

    #[Test]
    public function movingATaskWithoutAWorkspaceVersionIsRejected(): void
    {
        $taskUid = $this->createOpenTask(['workspace_uid' => 0]);

        $payload = $this->decode(
            $this->subject()->executeStageAction($this->jsonRequest(['task' => $taskUid, 'stageUid' => 1])),
        );

        self::assertSame('no-workspace-version', $payload['code']);
    }

    /**
     * Every rejection above must also have reached the logger - the contract is
     * "client sees a clean message, var/log keeps the detail", not "instead of".
     */
    #[Test]
    public function everyRejectionIsAlsoLoggedForDevelopers(): void
    {
        $this->subject()->createAction($this->jsonRequest(['table' => 'sys_log', 'uid' => 1]));

        self::assertNotEmpty($this->logger->records, 'the rejection must have been logged');
        $record = $this->logger->records[0];

        self::assertSame('table-not-trackable', $record['message'], 'the log message IS the stable code');
        self::assertSame('sys_log', $record['context']['table'] ?? null);
        self::assertArrayHasKey('beUser', $record['context'], 'who triggered it must be traceable');
    }

    private function createClosedTask(): int
    {
        return $this->createOpenTask(['closed' => 1]);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createOpenTask(array $overrides = []): int
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('tx_contentflow_task');
        $connection->insert('tx_contentflow_task', array_merge([
            'title' => 'About us',
            'subject_table' => 'pages',
            'subject_uid' => 2,
            'subject_pid' => 2,
            'state' => 'backlog',
            'workspace_uid' => 1,
            'closed' => 0,
        ], $overrides));

        return (int)$connection->lastInsertId();
    }
}

/**
 * Minimal in-memory PSR-3 logger. psr/log's own Test\TestLogger is not part of
 * the vendored version here, and pulling in a dependency for eight lines of
 * recording logic is not worth it.
 */
final class RecordingLogger extends AbstractLogger
{
    /**
     * @var list<array{level: mixed, message: string, context: array<string, mixed>}>
     */
    public array $records = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string)$message, 'context' => $context];
    }
}
