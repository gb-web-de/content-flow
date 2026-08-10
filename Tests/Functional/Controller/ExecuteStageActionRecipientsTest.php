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
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\NullLogger;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\DataHandling\History\RecordHistoryStore;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\View\ViewFactoryInterface;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class ExecuteStageActionRecipientsTest extends FunctionalTestCase
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
        $GLOBALS['BE_USER']->setWorkspace(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->create('en');
    }

    #[Test]
    public function additionalRecipientEmailsAreForwardedInTheFormatCoreWritesToHistory(): void
    {
        $stageUid = $this->createReviewStage();
        $this->editPageInWorkspace();

        $taskUid = $this->findOpenTaskUid();
        $versionUid = $this->versionUidOf('pages', 2);
        self::assertGreaterThan(0, $taskUid, 'editing should have opened a task');
        self::assertGreaterThan(0, $versionUid, 'editing should have created a workspace version');

        $response = $this->subject()->executeStageAction($this->jsonRequest([
            'task' => $taskUid,
            'stageUid' => $stageUid,
            'comment' => 'Ready for review.',
            'additional' => "review@example.org\nnot-an-email",
        ]));
        $payload = $this->decode($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($payload['success']);
        self::assertSame($stageUid, $payload['stageUid']);

        $entries = $this->stageHistoryFor('pages', $versionUid);
        self::assertCount(1, $entries, 'core should record exactly one stage change');

        $historyPayload = json_decode((string)$entries[0]['history_data'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Ready for review.', $historyPayload['comment']);
        self::assertSame([
            ['email' => 'review@example.org'],
        ], $historyPayload['recipients']);
    }

    private function subject(): TaskAjaxController
    {
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
            $this->get(\GbWeb\ContentFlow\Service\StageTransitionService::class),
            $this->get(\TYPO3\CMS\Workspaces\Service\StagesService::class),
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
    private function decode(ResponseInterface $response): array
    {
        $decoded = json_decode((string)$response->getBody(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function createReviewStage(): int
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('sys_workspace_stage');
        $connection->insert('sys_workspace_stage', [
            'pid' => 0,
            'parentid' => 1,
            'title' => 'Review',
        ]);

        return (int)$connection->lastInsertId('sys_workspace_stage');
    }

    private function editPageInWorkspace(): void
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start(['pages' => [2 => ['title' => 'About us (revised)']]], []);
        $dataHandler->process_datamap();
    }

    private function findOpenTaskUid(): int
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('tx_contentflow_task');
        $queryBuilder->getRestrictions()->removeAll();

        return (int)$queryBuilder
            ->select('uid')
            ->from('tx_contentflow_task')
            ->where(
                $queryBuilder->expr()->eq('subject_table', $queryBuilder->createNamedParameter('pages')),
                $queryBuilder->expr()->eq('subject_uid', $queryBuilder->createNamedParameter(2)),
                $queryBuilder->expr()->eq('closed', $queryBuilder->createNamedParameter(0)),
            )
            ->orderBy('uid', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();
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

    /**
     * @return list<array<string, mixed>>
     */
    private function stageHistoryFor(string $table, int $recordUid): array
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('sys_history');
        $queryBuilder->getRestrictions()->removeAll();

        return $queryBuilder
            ->select('*')
            ->from('sys_history')
            ->where(
                $queryBuilder->expr()->eq('tablename', $queryBuilder->createNamedParameter($table)),
                $queryBuilder->expr()->eq('recuid', $queryBuilder->createNamedParameter($recordUid)),
                $queryBuilder->expr()->eq('actiontype', $queryBuilder->createNamedParameter(RecordHistoryStore::ACTION_STAGECHANGE)),
            )
            ->executeQuery()
            ->fetchAllAssociative();
    }
}
