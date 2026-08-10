<?php

declare(strict_types=1);

namespace GbWeb\ContentFlow\Tests\Functional\Controller;

use GbWeb\ContentFlow\Controller\TaskAjaxController;
use GbWeb\ContentFlow\Domain\Model\TaskState;
use GbWeb\ContentFlow\Domain\Repository\TaskRepository;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Workspaces\Service\StagesService;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * A ticket planned with "Neue Seite erstellen" has no page behind it yet, and
 * the board has to keep letting an editor plan with it.
 *
 * It used to refuse every move into a column that is not a stage - including
 * Backlog to Planned, which writes nothing but this extension's own column and
 * is the entire point of planning a page before it exists. An editor doing
 * exactly the right thing was told to do something else ("move it to a review
 * stage to create it"), and the page is not created by a review stage anyway:
 * it is created on the way into Editing.
 */
final class PendingPageTicketMovesTest extends FunctionalTestCase
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
        // A stage move reaches core's WorkspaceStageRepository, which reads
        // $GLOBALS['LANG'] to title the stages.
        $GLOBALS['LANG'] = $this->get(\TYPO3\CMS\Core\Localization\LanguageServiceFactory::class)
            ->createFromUserPreferences($GLOBALS['BE_USER']);
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
     * subject_uid 0 with subject_table "pages" is what makes a ticket pending -
     * see TaskRepository::createPendingPageTask().
     *
     * @param array<string, mixed> $overrides
     */
    private function createPendingTicket(array $overrides = []): int
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('tx_contentflow_task');
        $connection->insert('tx_contentflow_task', array_merge([
            'title' => 'Trail guide',
            'subject_table' => 'pages',
            'subject_uid' => 0,
            'subject_pid' => 2,
            'state' => TaskState::BACKLOG->value,
            'workspace_uid' => 0,
            'closed' => 0,
        ], $overrides));

        return (int)$connection->lastInsertId();
    }

    #[Test]
    public function itMovesFromBacklogToPlannedWithoutAPage(): void
    {
        $taskUid = $this->createPendingTicket();

        $payload = $this->decode($this->subject()->moveStageAction($this->moveRequest([
            'task' => $taskUid,
            'state' => TaskState::PLANNED->value,
        ])));

        self::assertTrue($payload['success'], 'planning a page that does not exist yet is the whole point');
        self::assertSame(
            TaskState::PLANNED->value,
            $this->get(TaskRepository::class)->findByUid($taskUid)['state'],
        );
    }

    /**
     * "Seite soll erst beim Wechsel von Planned zu Editing angelegt werden."
     */
    #[Test]
    public function thePageIsCreatedOnTheWayIntoEditing(): void
    {
        $taskUid = $this->createPendingTicket(['state' => TaskState::PLANNED->value]);

        $payload = $this->decode($this->subject()->moveStageAction($this->moveRequest([
            'task' => $taskUid,
            'state' => TaskState::IN_PROGRESS->value,
            'stageUid' => StagesService::STAGE_EDIT_ID,
        ])));

        self::assertTrue($payload['success']);

        $task = $this->get(TaskRepository::class)->findByUid($taskUid);
        self::assertGreaterThan(0, (int)$task['subject_uid'], 'the page must exist now');

        // Read the way the backend reads it. The record is created inside the
        // workspace, and a raw connection opened by the test does not see it.
        $page = BackendUtility::getRecord('pages', (int)$task['subject_uid']);
        self::assertIsArray($page);
        self::assertSame('Trail guide', $page['title']);
        self::assertSame(2, (int)$page['pid'], 'under the parent the ticket was planned below');
        self::assertSame(1, (int)$page['t3ver_wsid'], 'created in the workspace, not on live');
        self::assertSame(
            StagesService::STAGE_EDIT_ID,
            (int)$page['t3ver_stage'],
            'a brand new workspace record starts in Editing',
        );
    }

    #[Test]
    public function itCannotBeFinishedWhileItIsStillOnlyAPlan(): void
    {
        $taskUid = $this->createPendingTicket(['state' => TaskState::PLANNED->value]);

        $payload = $this->decode($this->subject()->moveStageAction($this->moveRequest([
            'task' => $taskUid,
            'state' => TaskState::DONE->value,
        ])));

        self::assertFalse($payload['success']);
        // Its own code, not the blanket "needs a review stage" rejection that
        // used to answer every non-stage move.
        self::assertSame('pending-page-cannot-be-done', $payload['code']);
    }
}
