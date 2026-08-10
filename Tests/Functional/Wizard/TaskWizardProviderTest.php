<?php

declare(strict_types=1);

namespace GbWeb\ContentFlow\Tests\Functional\Wizard;

use GbWeb\ContentFlow\Domain\Repository\TaskRepository;
use GbWeb\ContentFlow\Service\ActivityLogger;
use GbWeb\ContentFlow\Service\TaskSubjectRegistry;
use GbWeb\ContentFlow\Wizard\TaskWizardProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\NullLogger;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Covers TaskWizardProvider::handleSubmit() and getConfiguration() - the
 * migration target for what used to be TaskAjaxController::wizardSubmitAction(),
 * now driven through TYPO3 core's native WizardProviderInterface framework
 * (see the class docblock). Submission and step-branching are only useful if
 * they really turn a wizard run into task state, so these tests exercise both:
 * refining an auto-created task, splitting an edited element into its own
 * task, and the destination-choice step's dynamic follow-up steps.
 */
final class TaskWizardProviderTest extends FunctionalTestCase
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
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->create('en');
    }

    private function subject(): TaskWizardProvider
    {
        return new TaskWizardProvider(
            $this->get(TaskRepository::class),
            $this->get(TaskSubjectRegistry::class),
            $this->get(ActivityLogger::class),
            $this->get(\GbWeb\ContentFlow\Notification\AssignmentNotificationService::class),
            $this->get(\GbWeb\ContentFlow\Domain\Repository\CommentRepository::class),
            $this->get(UriBuilder::class),
            new NullLogger(),
        );
    }

    private function submitRequest(array $body): ServerRequestInterface
    {
        return (new ServerRequest())->withParsedBody($body)->withMethod('POST');
    }

    private function configRequest(array $data): ServerRequestInterface
    {
        return (new ServerRequest())->withQueryParams(['mode' => 'contentflow_task_wizard', 'data' => $data]);
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

        $result = $this->subject()->handleSubmit($this->submitRequest([
            'mode' => 'configure_auto_task',
            'taskUid' => $taskUid,
            'table' => 'pages',
            'uid' => 2,
            'title' => 'Landing page refresh',
            'description' => 'Rework the intro section.',
            'assignee' => 'open',
        ]))->jsonSerialize();

        self::assertTrue($result['success']);
        self::assertSame('reload', $result['finisher']['identifier']);
        self::assertSame($taskUid, $result['finisher']['data']['task']);

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

        $result = $this->subject()->handleSubmit($this->submitRequest([
            'mode' => 'route_member',
            'destination' => 'create_new_task',
            'table' => 'tt_content',
            'uid' => 10,
            'title' => 'Intro hero follow-up',
            'description' => 'Rewrite the teaser copy.',
            'assignee' => 'open',
            'stageChoice' => 'review',
        ]))->jsonSerialize();

        self::assertTrue($result['success']);
        $taskUid = (int)$result['finisher']['data']['task'];

        $task = $this->fetchRow('tx_contentflow_task', $taskUid);
        self::assertSame('tt_content', $task['subject_table']);
        self::assertSame(10, (int)$task['subject_uid']);
        self::assertSame('Intro hero follow-up', $task['title']);
        self::assertSame('Rewrite the teaser copy.', $task['description']);
        self::assertSame('review', $task['state']);
        self::assertSame(1, (int)$task['workspace_uid']);
        self::assertSame(0, (int)$task['assignee']);
    }

    #[Test]
    public function attachingToThePageTaskMovesTheMembership(): void
    {
        $pageTaskUid = $this->createTask([
            'subject_table' => 'pages',
            'subject_uid' => 2,
            'subject_pid' => 2,
            'auto_created' => 0,
        ]);
        $this->addOpenMembership($pageTaskUid, 'tt_content', 10, 2);

        $result = $this->subject()->handleSubmit($this->submitRequest([
            'mode' => 'route_member',
            'destination' => 'attach_to_page_task',
            'table' => 'tt_content',
            'uid' => 10,
            'pageTaskUid' => $pageTaskUid,
        ]))->jsonSerialize();

        self::assertTrue($result['success']);
        self::assertSame($pageTaskUid, $result['finisher']['data']['task']);
    }

    #[Test]
    public function creatingATaskFromThePickerCarriesOverPriorityAndDates(): void
    {
        $result = $this->subject()->handleSubmit($this->submitRequest([
            'mode' => 'create_from_picker',
            'table' => 'pages',
            'uid' => 2,
            'title' => 'New landing page',
            'assignee' => 'me',
            'priority' => '1',
            'startDate' => '2026-01-05',
        ]))->jsonSerialize();

        self::assertTrue($result['success']);
        $taskUid = (int)$result['finisher']['data']['task'];

        $task = $this->fetchRow('tx_contentflow_task', $taskUid);
        self::assertSame('New landing page', $task['title']);
        self::assertSame(1, (int)$task['priority']);
        self::assertSame('planned', $task['state']);
    }

    #[Test]
    public function missingTitleIsRejectedWithoutTouchingTheDatabase(): void
    {
        $taskUid = $this->createTask();

        $result = $this->subject()->handleSubmit($this->submitRequest([
            'mode' => 'configure_auto_task',
            'taskUid' => $taskUid,
            'table' => 'pages',
            'uid' => 2,
            'title' => '',
        ]))->jsonSerialize();

        self::assertFalse($result['success']);
        self::assertNotEmpty($result['errors']);

        $task = $this->fetchRow('tx_contentflow_task', $taskUid);
        self::assertSame('About us', $task['title']);
    }

    #[Test]
    public function routeMemberConfigurationStartsWithOnlyTheDestinationChoice(): void
    {
        $configuration = $this->subject()->getConfiguration($this->configRequest([
            'pending' => ['mode' => 'route_member', 'pageTaskTitle' => 'About us'],
        ]))->jsonSerialize();

        self::assertCount(1, $configuration['steps']);
        self::assertSame('@gb-web/content-flow/wizard/steps/route-choice-step.js', $configuration['steps'][0]['module']);
    }

    #[Test]
    public function routeMemberConfigurationLoadsDetailAndStageStepsForANewTask(): void
    {
        $configuration = $this->subject()->getConfiguration($this->configRequest([
            'pending' => ['mode' => 'route_member', 'defaultTitle' => 'Intro hero follow-up'],
            'destination' => 'create_new_task',
        ]))->jsonSerialize();

        $modules = array_column($configuration['steps'], 'module');
        self::assertSame([
            '@gb-web/content-flow/wizard/steps/task-details-step.js',
            '@gb-web/content-flow/wizard/steps/stage-choice-step.js',
        ], $modules);
    }

    #[Test]
    public function routeMemberConfigurationLoadsNoFurtherStepsWhenAttaching(): void
    {
        $configuration = $this->subject()->getConfiguration($this->configRequest([
            'pending' => ['mode' => 'route_member'],
            'destination' => 'attach_to_page_task',
        ]))->jsonSerialize();

        self::assertSame([], $configuration['steps']);
    }

    #[Test]
    public function creatingAPendingPageTaskLeavesTheSubjectEmptyUntilMaterialized(): void
    {
        $result = $this->subject()->handleSubmit($this->submitRequest([
            'mode' => 'create_pending_page',
            'parentPid' => 1,
            'title' => 'New landing page',
            'assignee' => 'me',
        ]))->jsonSerialize();

        self::assertTrue($result['success']);
        $taskUid = (int)$result['finisher']['data']['task'];

        $task = $this->fetchRow('tx_contentflow_task', $taskUid);
        self::assertSame('New landing page', $task['title']);
        self::assertSame('pages', $task['subject_table']);
        self::assertSame(0, (int)$task['subject_uid']);
        self::assertSame(1, (int)$task['subject_pid']);
    }

    #[Test]
    public function creatingAPendingPageTaskWithoutATitleIsRejected(): void
    {
        $result = $this->subject()->handleSubmit($this->submitRequest([
            'mode' => 'create_pending_page',
            'parentPid' => 1,
            'title' => '',
        ]))->jsonSerialize();

        self::assertFalse($result['success']);
        self::assertNotEmpty($result['errors']);
    }

    #[Test]
    public function updatingTheAutoGeneratedRegressionCommentReplacesItsContent(): void
    {
        $taskUid = $this->createTask();
        $commentUid = $this->get(\GbWeb\ContentFlow\Domain\Repository\CommentRepository::class)
            ->add($taskUid, 'Automatically reopened for editing - pages:2 was modified.', 1);

        $result = $this->subject()->handleSubmit($this->submitRequest([
            'mode' => 'regression_comment',
            'taskUid' => $taskUid,
            'commentUid' => $commentUid,
            'content' => 'Reopened because the intro needed another pass.',
        ]))->jsonSerialize();

        self::assertTrue($result['success']);

        $comment = $this->fetchRow('tx_contentflow_comment', $commentUid);
        self::assertSame('Reopened because the intro needed another pass.', $comment['content']);
    }

    #[Test]
    public function updatingTheRegressionCommentWithEmptyContentIsRejected(): void
    {
        $taskUid = $this->createTask();
        $commentUid = $this->get(\GbWeb\ContentFlow\Domain\Repository\CommentRepository::class)
            ->add($taskUid, 'Automatically reopened for editing.', 1);

        $result = $this->subject()->handleSubmit($this->submitRequest([
            'mode' => 'regression_comment',
            'taskUid' => $taskUid,
            'commentUid' => $commentUid,
            'content' => '',
        ]))->jsonSerialize();

        self::assertFalse($result['success']);

        $comment = $this->fetchRow('tx_contentflow_comment', $commentUid);
        self::assertSame('Automatically reopened for editing.', $comment['content']);
    }

    #[Test]
    public function regressionCommentConfigurationCarriesTheDefaultCommentIntoTheStep(): void
    {
        $configuration = $this->subject()->getConfiguration($this->configRequest([
            'pending' => ['mode' => 'regression_comment', 'defaultComment' => 'Automatically reopened.'],
        ]))->jsonSerialize();

        self::assertCount(1, $configuration['steps']);
        self::assertSame('@gb-web/content-flow/wizard/steps/comment-step.js', $configuration['steps'][0]['module']);
        self::assertSame('Automatically reopened.', $configuration['steps'][0]['configurationData']['defaultComment']);
    }
}
