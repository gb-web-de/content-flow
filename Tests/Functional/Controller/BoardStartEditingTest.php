<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Tests\Functional\Controller;

use GbWeb\EditorialFlow\Controller\TaskAjaxController;
use GbWeb\EditorialFlow\Domain\Model\TaskState;
use GbWeb\EditorialFlow\Domain\Repository\TaskRepository;
use GbWeb\EditorialFlow\Service\ActiveTaskSession;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Workspaces\Service\StagesService;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Dragging a Backlog/Planned task into Editing is now a start-work action, not
 * a dead end. Page tasks become the page's active task and send the editor to
 * the page module; record tasks jump straight to their record edit form and are
 * active only for that exact record.
 */
final class BoardStartEditingTest extends FunctionalTestCase
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

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->setUpBackendUser(1);
        $GLOBALS['BE_USER']->setWorkspace(1);
    }

    private function subject(): TaskAjaxController
    {
        return $this->get(TaskAjaxController::class);
    }

    private function moveRequest(array $body): ServerRequestInterface
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

    /**
     * @param array<string, mixed> $overrides
     */
    private function createTask(array $overrides = []): int
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('tx_editorialflow_task');
        $connection->insert('tx_editorialflow_task', array_merge([
            'title' => 'About us',
            'subject_table' => 'pages',
            'subject_uid' => 2,
            'subject_pid' => 2,
            'state' => TaskState::PLANNED->value,
            'workspace_uid' => 0,
            'closed' => 0,
        ], $overrides));

        return (int)$connection->lastInsertId();
    }

    #[Test]
    public function droppingAnExistingPageTaskIntoEditingStartsItAndMakesItActiveForThePage(): void
    {
        $taskUid = $this->createTask();

        $payload = $this->decode($this->subject()->moveStageAction($this->moveRequest([
            'task' => $taskUid,
            'state' => TaskState::IN_PROGRESS->value,
            'stageUid' => StagesService::STAGE_EDIT_ID,
        ])));

        self::assertTrue($payload['success']);
        self::assertTrue($payload['startedEditing']);
        self::assertNotSame('', (string)$payload['redirectUrl']);

        $task = $this->get(TaskRepository::class)->findByUid($taskUid);
        self::assertSame(TaskState::IN_PROGRESS->value, $task['state']);
        self::assertSame(1, (int)$task['workspace_uid']);
        self::assertSame(StagesService::STAGE_EDIT_ID, (int)$task['stage_uid']);
        self::assertSame($taskUid, $this->get(ActiveTaskSession::class)->resolve($GLOBALS['BE_USER'], 2));
    }

    #[Test]
    public function droppingARecordTaskIntoEditingMakesOnlyThatRecordActive(): void
    {
        $taskUid = $this->createTask([
            'title' => 'Intro text',
            'subject_table' => 'tt_content',
            'subject_uid' => 10,
            'subject_pid' => 2,
        ]);

        $payload = $this->decode($this->subject()->moveStageAction($this->moveRequest([
            'task' => $taskUid,
            'state' => TaskState::IN_PROGRESS->value,
            'stageUid' => StagesService::STAGE_EDIT_ID,
        ])));

        self::assertTrue($payload['success']);
        self::assertTrue($payload['startedEditing']);
        self::assertNotSame('', (string)$payload['redirectUrl']);

        $task = $this->get(TaskRepository::class)->findByUid($taskUid);
        self::assertSame(TaskState::IN_PROGRESS->value, $task['state']);
        self::assertSame(1, (int)$task['workspace_uid']);
        self::assertSame(StagesService::STAGE_EDIT_ID, (int)$task['stage_uid']);
        self::assertNull(
            $this->get(ActiveTaskSession::class)->resolve($GLOBALS['BE_USER'], 2),
            'editing one record must not route every later save on that page onto this task',
        );
        self::assertSame(
            $taskUid,
            $this->get(ActiveTaskSession::class)->resolveForEdit($GLOBALS['BE_USER'], 'tt_content', 10, 2),
        );
        self::assertNull(
            $this->get(ActiveTaskSession::class)->resolveForEdit($GLOBALS['BE_USER'], 'tt_content', 11, 2),
        );
    }
}
