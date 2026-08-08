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
use Psr\Log\NullLogger;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\View\ViewFactoryInterface;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The post-save wizard is only useful if its submit endpoint really turns the
 * saved choice into task state. These tests cover the two new paths: refining an
 * auto-created task, and splitting an edited element into its own task with the
 * optional metadata the wizard collected.
 */
final class TaskAjaxControllerWizardTest extends FunctionalTestCase
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

    private function subject(): TaskAjaxController
    {
        $connectionPool = $this->get(ConnectionPool::class);
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
            new NullLogger(),
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

    /**
     * @param array<string, mixed> $overrides
     */
    private function createTask(array $overrides = []): int
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('tx_contentflow_task');
        $connection->insert('tx_contentflow_task', array_merge([
            'title' => 'About us',
            'description' => '',
            'subject_table' => 'pages',
            'subject_uid' => 2,
            'subject_pid' => 2,
            'state' => 'in_progress',
            'workspace_uid' => 1,
            'assignee' => 1,
            'auto_created' => 1,
            'closed' => 0,
        ], $overrides));

        return (int)$connection->lastInsertId();
    }

    private function addOpenMembership(int $taskUid, string $table, int $uid, int $homePid): void
    {
        $this->getConnectionPool()->getConnectionForTable('tx_contentflow_task_item')->insert('tx_contentflow_task_item', [
            'task' => $taskUid,
            'record_table' => $table,
            'record_uid' => $uid,
            'origin' => 'auto',
            'home_pid' => $homePid,
            'shared' => 0,
            'closed' => 0,
            'deleted' => 0,
            'crdate' => $GLOBALS['EXEC_TIME'],
            'tstamp' => $GLOBALS['EXEC_TIME'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchMembership(string $table, int $uid): array
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('tx_contentflow_task_item');
        $queryBuilder->getRestrictions()->removeAll();
        $row = $queryBuilder
            ->select('*')
            ->from('tx_contentflow_task_item')
            ->where(
                $queryBuilder->expr()->eq('record_table', $queryBuilder->createNamedParameter($table)),
                $queryBuilder->expr()->eq('record_uid', $queryBuilder->createNamedParameter($uid, \TYPO3\CMS\Core\Database\Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('closed', $queryBuilder->createNamedParameter(0, \TYPO3\CMS\Core\Database\Connection::PARAM_INT)),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        self::assertIsArray($row);

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchRow(string $table, int $uid): array
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $row = $queryBuilder
            ->select('*')
            ->from($table)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, \TYPO3\CMS\Core\Database\Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();

        self::assertIsArray($row);

        return $row;
    }

    #[Test]
    public function configuringAnAutoCreatedTaskUpdatesItsHumanFacingDetails(): void
    {
        $taskUid = $this->createTask();

        $payload = $this->decode($this->subject()->wizardSubmitAction($this->jsonRequest([
            'actionType' => 'configure_auto_task',
            'taskUid' => $taskUid,
            'table' => 'pages',
            'uid' => 2,
            'title' => 'Landing page refresh',
            'description' => 'Rework the intro section.',
            'assignee' => 'open',
        ])));

        self::assertTrue($payload['success']);
        self::assertSame('configured', $payload['action']);

        $task = $this->fetchRow('tx_contentflow_task', $taskUid);
        self::assertSame('Landing page refresh', $task['title']);
        self::assertSame('Rework the intro section.', $task['description']);
        self::assertSame(0, (int)$task['assignee']);
        self::assertSame(1, (int)$task['auto_created'], 'refining the task must not erase how it originated');
    }

    #[Test]
    public function creatingANewTaskFromTheRoutingWizardCarriesOverOptionalFields(): void
    {
        $GLOBALS['BE_USER']->setWorkspace(1);

        $pageTaskUid = $this->createTask([
            'title' => 'About us',
            'subject_table' => 'pages',
            'subject_uid' => 2,
            'subject_pid' => 2,
            'auto_created' => 0,
        ]);
        $this->addOpenMembership($pageTaskUid, 'tt_content', 10, 2);

        $payload = $this->decode($this->subject()->wizardSubmitAction($this->jsonRequest([
            'actionType' => 'create_new_task',
            'table' => 'tt_content',
            'uid' => 10,
            'title' => 'Intro hero follow-up',
            'description' => 'Rewrite the teaser copy.',
            'assignee' => 'open',
            'stageChoice' => 'review',
        ])));

        self::assertTrue($payload['success']);
        self::assertSame('created', $payload['action']);

        $taskUid = (int)$payload['task'];
        $task = $this->fetchRow('tx_contentflow_task', $taskUid);
        self::assertSame('tt_content', $task['subject_table']);
        self::assertSame(10, (int)$task['subject_uid']);
        self::assertSame('Intro hero follow-up', $task['title']);
        self::assertSame('Rewrite the teaser copy.', $task['description']);
        self::assertSame('review', $task['state']);
        self::assertSame(1, (int)$task['workspace_uid']);
        self::assertSame(0, (int)$task['assignee']);

        $membership = $this->fetchMembership('tt_content', 10);
        self::assertSame($taskUid, (int)$membership['task']);
        self::assertSame('tt_content', $membership['record_table']);
        self::assertSame(10, (int)$membership['record_uid']);
    }
}
