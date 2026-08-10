<?php

declare(strict_types=1);

namespace GbWeb\ContentFlow\Tests\Functional\Controller;

use GbWeb\ContentFlow\Controller\TaskAjaxController;
use GbWeb\ContentFlow\Domain\Model\TaskState;
use GbWeb\ContentFlow\Domain\Repository\TaskRepository;
use GbWeb\ContentFlow\Service\PendingPageHandoff;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\StringUtility;
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
     * "Seite soll erst beim Wechsel von Planned zu Editing angelegt werden ...
     * mit dem PageWizard von TYPO3."
     *
     * The move therefore does not create anything by itself any more. It asks
     * for core's page wizard and notes which ticket is waiting for the result.
     */
    #[Test]
    public function theMoveIntoEditingAsksForCoresPageWizard(): void
    {
        $taskUid = $this->createPendingTicket(['state' => TaskState::PLANNED->value]);

        $payload = $this->decode($this->subject()->moveStageAction($this->moveRequest([
            'task' => $taskUid,
            'state' => TaskState::IN_PROGRESS->value,
            'stageUid' => StagesService::STAGE_EDIT_ID,
        ])));

        self::assertTrue($payload['success']);
        self::assertTrue($payload['requiresPageWizard']);
        // The shape core's own openPageWizardModal() callers pass.
        self::assertSame(2, (int)$payload['positionData']['pageUid'], 'prefilled with the planned parent');
        self::assertSame('inside', $payload['positionData']['insertPosition']);

        self::assertSame(
            $taskUid,
            $this->get(PendingPageHandoff::class)->resolve($GLOBALS['BE_USER']),
            'the ticket must be on record as waiting for a page',
        );

        $task = $this->get(TaskRepository::class)->findByUid($taskUid);
        self::assertSame(0, (int)$task['subject_uid'], 'nothing is created before the wizard runs');
        self::assertSame(TaskState::PLANNED->value, $task['state'], 'and the ticket has not moved yet');
    }

    /**
     * The other half: whatever core's wizard creates is claimed by the waiting
     * ticket through the DataHandler hook, because core hands no uid back to a
     * third package - it redirects the browser instead.
     */
    #[Test]
    public function thePageTheWizardCreatesIsClaimedByTheWaitingTicket(): void
    {
        $taskUid = $this->createPendingTicket(['state' => TaskState::PLANNED->value]);
        $this->subject()->moveStageAction($this->moveRequest([
            'task' => $taskUid,
            'state' => TaskState::IN_PROGRESS->value,
            'stageUid' => StagesService::STAGE_EDIT_ID,
        ]));

        // Exactly what core's PageWizardProvider::handleSubmit() does with the
        // wizard's form data: one new page, through DataHandler.
        $newPageUid = $this->createPageThroughDataHandler(2, 'Trail guide');

        $task = $this->get(TaskRepository::class)->findByUid($taskUid);
        self::assertSame($newPageUid, (int)$task['subject_uid'], 'the ticket now points at the created page');
        self::assertSame(1, (int)$task['workspace_uid']);
        self::assertSame(StagesService::STAGE_EDIT_ID, (int)$task['stage_uid']);
        self::assertSame(TaskState::IN_PROGRESS->value, $task['state']);

        $page = BackendUtility::getRecord('pages', $newPageUid);
        self::assertIsArray($page);
        self::assertSame(1, (int)$page['t3ver_wsid'], 'created in the workspace, not on live');
    }

    /**
     * A cancelled wizard must not leave a claim behind that adopts the next
     * page the editor creates for something else entirely.
     */
    #[Test]
    public function aPageCreatedAfterTheWizardWasCancelledStaysUnclaimed(): void
    {
        $taskUid = $this->createPendingTicket(['state' => TaskState::PLANNED->value]);
        $this->subject()->moveStageAction($this->moveRequest([
            'task' => $taskUid,
            'state' => TaskState::IN_PROGRESS->value,
            'stageUid' => StagesService::STAGE_EDIT_ID,
        ]));

        $this->subject()->cancelPageWizardAction($this->moveRequest([]));

        $newPageUid = $this->createPageThroughDataHandler(2, 'Something else');

        $task = $this->get(TaskRepository::class)->findByUid($taskUid);
        self::assertSame(0, (int)$task['subject_uid'], 'the ticket must not have taken this page');
        self::assertNotSame($newPageUid, (int)$task['subject_uid']);
    }

    private function createPageThroughDataHandler(int $parentPageUid, string $title): int
    {
        $placeholder = StringUtility::getUniqueId('NEW');
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start(['pages' => [$placeholder => ['pid' => $parentPageUid, 'title' => $title, 'doktype' => 1]]], []);
        $dataHandler->process_datamap();

        $uid = (int)($dataHandler->substNEWwithIDs[$placeholder] ?? 0);
        self::assertGreaterThan(0, $uid, 'the page itself must have been created');

        return $uid;
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
