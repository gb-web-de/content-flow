<?php

declare(strict_types=1);

namespace GbWeb\ContentFlow\Tests\Functional\Controller;

use GbWeb\ContentFlow\Domain\Model\TaskState;
use GbWeb\ContentFlow\Domain\Repository\TaskRepository;
use GbWeb\ContentFlow\Service\ActiveTaskSession;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Workspaces\Service\StagesService;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The three endpoints behind the Visual Editor's task select, which had no
 * coverage at all while the feature was shipped.
 *
 * Two of them answer questions nothing else in the extension asks, and both had
 * a wrong answer: the list did not say which task was already active, so the
 * select came back blank after a reload while the server kept routing saves to
 * it; and the markers named members by their live uid alone, which is never the
 * uid EXT:visual_editor writes onto an element once a workspace version exists.
 */
final class VisualEditorTaskSelectTest extends FunctionalTestCase
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
        $GLOBALS['LANG'] = $this->get(\TYPO3\CMS\Core\Localization\LanguageServiceFactory::class)
            ->createFromUserPreferences($GLOBALS['BE_USER']);
    }

    private function subject(): \GbWeb\ContentFlow\Controller\TaskAjaxController
    {
        return $this->get(\GbWeb\ContentFlow\Controller\TaskAjaxController::class);
    }

    private function getRequest(array $query): ServerRequestInterface
    {
        return (new ServerRequest())->withQueryParams($query);
    }

    private function postRequest(array $body): ServerRequestInterface
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
        $connection = $this->getConnectionPool()->getConnectionForTable('tx_contentflow_task');
        $connection->insert('tx_contentflow_task', array_merge([
            'title' => 'About us',
            'subject_table' => 'pages',
            'subject_uid' => 2,
            'subject_pid' => 2,
            'state' => TaskState::BACKLOG->value,
            'workspace_uid' => 0,
            'closed' => 0,
        ], $overrides));

        return (int)$connection->lastInsertId();
    }

    private function addMember(int $taskUid, string $table, int $recordUid): void
    {
        $this->getConnectionPool()->getConnectionForTable('tx_contentflow_task_item')->insert(
            'tx_contentflow_task_item',
            [
                'task' => $taskUid,
                'record_table' => $table,
                'record_uid' => $recordUid,
                'pid' => 2,
                'closed' => 0,
            ],
        );
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

    private function editInWorkspace(string $table, int $uid, string $field, string $value): void
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([$table => [$uid => [$field => $value]]], []);
        $dataHandler->process_datamap();
    }

    private function stageOf(string $table, int $uid): int
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();

        return (int)$queryBuilder
            ->select('t3ver_stage')
            ->from($table)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid)))
            ->executeQuery()
            ->fetchOne();
    }

    #[Test]
    public function theListOffersOpenTasksOnThePageAndHidesFinishedOnes(): void
    {
        $open = $this->createTask(['title' => 'Rewrite the intro']);
        $this->createTask(['title' => 'Last month', 'state' => TaskState::DONE->value]);
        $this->createTask(['title' => 'Archived', 'closed' => 1]);

        $payload = $this->decode($this->subject()->listOpenTasksForPageAction($this->getRequest(['pageUid' => 2])));

        self::assertTrue($payload['success']);
        $titles = array_column($payload['tasks'], 'title');
        self::assertSame(['Rewrite the intro'], $titles);
        self::assertSame($open, (int)$payload['tasks'][0]['uid']);
    }

    /**
     * The gap that made the select forget its own state: the choice keeps
     * routing saves server-side, so the list has to name it.
     */
    #[Test]
    public function theListNamesTheTaskThatIsAlreadyActiveForThePage(): void
    {
        $taskUid = $this->createTask();

        $before = $this->decode($this->subject()->listOpenTasksForPageAction($this->getRequest(['pageUid' => 2])));
        self::assertSame(0, (int)$before['activeTaskUid']);

        $this->subject()->setActiveTaskForPageAction($this->postRequest(['pageUid' => 2, 'taskUid' => $taskUid]));

        $after = $this->decode($this->subject()->listOpenTasksForPageAction($this->getRequest(['pageUid' => 2])));
        self::assertSame($taskUid, (int)$after['activeTaskUid']);
    }

    #[Test]
    public function theActiveTaskIsNotCarriedOverToAnotherPage(): void
    {
        $taskUid = $this->createTask();
        $this->subject()->setActiveTaskForPageAction($this->postRequest(['pageUid' => 2, 'taskUid' => $taskUid]));

        $payload = $this->decode($this->subject()->listOpenTasksForPageAction($this->getRequest(['pageUid' => 1])));

        self::assertSame(0, (int)$payload['activeTaskUid']);
    }

    #[Test]
    public function pickingABacklogTaskStartsWorkOnIt(): void
    {
        $taskUid = $this->createTask(['state' => TaskState::BACKLOG->value, 'workspace_uid' => 0]);

        $payload = $this->decode(
            $this->subject()->setActiveTaskForPageAction($this->postRequest(['pageUid' => 2, 'taskUid' => $taskUid])),
        );

        self::assertTrue($payload['success']);
        self::assertTrue($payload['transitioned']);

        $task = $this->get(TaskRepository::class)->findByUid($taskUid);
        self::assertSame(TaskState::IN_PROGRESS->value, $task['state']);
        self::assertSame(1, (int)$task['workspace_uid']);
        self::assertSame(StagesService::STAGE_EDIT_ID, (int)$task['stage_uid']);
    }

    /**
     * "Task nach Editing fallen wieder zurück auf Editing" - and the stage has
     * to be read off the record core actually wrote, not off our own row.
     */
    #[Test]
    public function pickingATaskThatIsPastEditingRegressesItBackToEditing(): void
    {
        $taskUid = $this->createTask();
        $this->addMember($taskUid, 'pages', 2);
        $this->editInWorkspace('pages', 2, 'title', 'About us (revised)');
        $versionUid = $this->versionUidOf('pages', 2);
        self::assertGreaterThan(0, $versionUid);

        // Send it to a review stage first, the situation an editor comes back to.
        $this->getConnectionPool()->getConnectionForTable('tx_contentflow_task')->update(
            'tx_contentflow_task',
            ['state' => TaskState::REVIEW->value, 'workspace_uid' => 1, 'stage_uid' => 1],
            ['uid' => $taskUid],
        );
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], ['pages' => [$versionUid => ['version' => [
            'action' => 'setStage',
            'stageId' => 1,
            'comment' => 'Please review.',
            'notificationAlternativeRecipients' => [],
        ]]]]);
        $dataHandler->process_cmdmap();
        self::assertSame(1, $this->stageOf('pages', $versionUid));

        $payload = $this->decode(
            $this->subject()->setActiveTaskForPageAction($this->postRequest(['pageUid' => 2, 'taskUid' => $taskUid])),
        );

        self::assertTrue($payload['transitioned']);
        self::assertNotSame('', $payload['comment'], 'the regression must explain itself');
        self::assertSame(StagesService::STAGE_EDIT_ID, $this->stageOf('pages', $versionUid));
    }

    /**
     * A declaration outlives the moment it was made. The transition
     * setActiveTaskForPageAction() runs is a one-shot, so a task sent to review
     * by anyone while the editor still holds it used to keep collecting that
     * editor's edits and stay in review the whole time - TaskAutoCreationService
     * ::captureEdit() claimed the record onto the active task and returned
     * before the rules that would have dragged it back to Editing ever ran.
     */
    #[Test]
    public function anEditKeepsPullingTheActiveTaskBackToEditingLaterOn(): void
    {
        $taskUid = $this->createTask();
        $this->addMember($taskUid, 'pages', 2);
        $this->editInWorkspace('pages', 2, 'title', 'About us (revised)');
        $versionUid = $this->versionUidOf('pages', 2);
        self::assertGreaterThan(0, $versionUid);

        $this->subject()->setActiveTaskForPageAction($this->postRequest(['pageUid' => 2, 'taskUid' => $taskUid]));

        // Somebody else moves it on while the editor still holds the choice.
        $this->getConnectionPool()->getConnectionForTable('tx_contentflow_task')->update(
            'tx_contentflow_task',
            ['state' => TaskState::REVIEW->value, 'workspace_uid' => 1, 'stage_uid' => 1],
            ['uid' => $taskUid],
        );
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], ['pages' => [$versionUid => ['version' => [
            'action' => 'setStage',
            'stageId' => 1,
            'comment' => 'Please review.',
            'notificationAlternativeRecipients' => [],
        ]]]]);
        $dataHandler->process_cmdmap();
        self::assertSame(1, $this->stageOf('pages', $versionUid));

        // The editor, who never re-picked anything, keeps typing.
        $this->editInWorkspace('pages', 2, 'title', 'About us (revised again)');

        // The task's own state is what pins this down. The record's stage
        // returns to Editing either way - DataHandler resets it whenever a
        // versioned record is written - so it is the board that would have gone
        // on showing "in review" for something visibly being typed into.
        self::assertSame(
            TaskState::IN_PROGRESS->value,
            $this->get(TaskRepository::class)->findByUid($taskUid)['state'],
            'an edit under a live declaration must still reopen the task',
        );
        self::assertSame(
            StagesService::STAGE_EDIT_ID,
            $this->stageOf('pages', $versionUid),
            'and the task must not disagree with the record core actually wrote',
        );
    }

    #[Test]
    public function askingForNoTaskDropsTheChoiceWithoutMovingAnything(): void
    {
        $taskUid = $this->createTask();
        $this->subject()->setActiveTaskForPageAction($this->postRequest(['pageUid' => 2, 'taskUid' => $taskUid]));
        $stateBefore = $this->get(TaskRepository::class)->findByUid($taskUid)['state'];

        $payload = $this->decode(
            $this->subject()->setActiveTaskForPageAction($this->postRequest(['pageUid' => 2, 'taskUid' => 0])),
        );

        self::assertTrue($payload['success']);
        self::assertFalse($payload['transitioned']);
        self::assertNull($this->get(ActiveTaskSession::class)->resolve($GLOBALS['BE_USER'], 2));
        self::assertSame(
            $stateBefore,
            $this->get(TaskRepository::class)->findByUid($taskUid)['state'],
            'dropping the choice must not move the task',
        );
    }

    /**
     * The regression that kept the markers invisible even once they looked in
     * the right document: EXT:visual_editor writes the VERSION uid onto a
     * content element in a workspace, while a membership row holds the LIVE
     * uid, so a single-uid answer never matches while a task is being worked on.
     */
    #[Test]
    public function markersNameAMemberByBothItsLiveAndItsVersionUid(): void
    {
        $taskUid = $this->createTask();
        $this->addMember($taskUid, 'tt_content', 10);
        $this->editInWorkspace('tt_content', 10, 'header', 'Intro text (revised)');
        $versionUid = $this->versionUidOf('tt_content', 10);
        self::assertGreaterThan(0, $versionUid);

        $payload = $this->decode(
            $this->subject()->listMemberTaskMarkersForPageAction($this->getRequest(['pageUid' => 2])),
        );

        $member = null;
        foreach ($payload['members'] as $candidate) {
            if ($candidate['table'] === 'tt_content' && (int)$candidate['uid'] === 10) {
                $member = $candidate;
            }
        }

        self::assertNotNull($member, 'the claimed element must be reported');
        self::assertContains('tt_content:10', $member['identifiers'], 'the live uid');
        self::assertContains('tt_content:' . $versionUid, $member['identifiers'], 'the uid the frontend renders');
    }

    #[Test]
    public function markersIgnoreTasksThatAreFinished(): void
    {
        $doneTask = $this->createTask(['state' => TaskState::DONE->value]);
        $this->addMember($doneTask, 'tt_content', 10);

        $payload = $this->decode(
            $this->subject()->listMemberTaskMarkersForPageAction($this->getRequest(['pageUid' => 2])),
        );

        self::assertSame([], $payload['tasks']);
        self::assertSame([], $payload['members']);
    }

    /**
     * The markers' tooltip and the toolbar legend both name a task in full, so
     * neither has to fetch a second endpoint to label a coloured dot.
     */
    #[Test]
    public function markersNameEachTaskWithItsStageAndAssignee(): void
    {
        $taskUid = $this->createTask(['title' => 'Rewrite the intro', 'assignee' => 1]);
        $this->addMember($taskUid, 'tt_content', 10);

        $payload = $this->decode(
            $this->subject()->listMemberTaskMarkersForPageAction($this->getRequest(['pageUid' => 2])),
        );

        self::assertSame('Rewrite the intro', $payload['tasks'][0]['title']);
        self::assertSame('Backlog', $payload['tasks'][0]['stageLabel']);
        self::assertSame('admin', $payload['tasks'][0]['assigneeName']);
    }

    #[Test]
    public function anUnassignedTaskIsReportedWithoutAName(): void
    {
        $taskUid = $this->createTask(['assignee' => 0]);
        $this->addMember($taskUid, 'tt_content', 10);

        $payload = $this->decode(
            $this->subject()->listMemberTaskMarkersForPageAction($this->getRequest(['pageUid' => 2])),
        );

        self::assertSame('', $payload['tasks'][0]['assigneeName']);
    }

    #[Test]
    public function aRecordContextCanActivateOnlyThatRecord(): void
    {
        $taskUid = $this->createTask([
            'title' => 'Rewrite the intro',
            'subject_table' => 'tt_content',
            'subject_uid' => 10,
            'subject_pid' => 2,
            'state' => TaskState::IN_PROGRESS->value,
            'workspace_uid' => 1,
        ]);
        $this->addMember($taskUid, 'tt_content', 10);

        $context = $this->decode($this->subject()->activeTaskContextAction($this->getRequest([
            'table' => 'tt_content',
            'uid' => 10,
        ])));
        self::assertSame($taskUid, $context['tasks'][0]['uid']);

        $selected = $this->decode($this->subject()->setActiveTaskForContextAction($this->postRequest([
            'table' => 'tt_content',
            'uid' => 10,
            'taskUid' => $taskUid,
        ])));
        self::assertTrue($selected['success']);
        self::assertSame('tt_content', $selected['activeTask']['contextTable']);
        self::assertSame(10, $selected['activeTask']['contextUid']);

        $session = $this->get(ActiveTaskSession::class);
        self::assertSame($taskUid, $session->resolveForEdit($GLOBALS['BE_USER'], 'tt_content', 10, 2));
        self::assertNull($session->resolveForEdit($GLOBALS['BE_USER'], 'tt_content', 11, 2));
        self::assertNull($session->resolve($GLOBALS['BE_USER'], 2));
    }

    #[Test]
    public function aRecordContextDoesNotOfferOrAcceptASiblingRecordsTask(): void
    {
        $recordTaskUid = $this->createTask([
            'title' => 'Rewrite the intro',
            'subject_table' => 'tt_content',
            'subject_uid' => 10,
            'subject_pid' => 2,
            'state' => TaskState::IN_PROGRESS->value,
            'workspace_uid' => 1,
        ]);
        $this->addMember($recordTaskUid, 'tt_content', 10);
        $siblingTaskUid = $this->createTask([
            'title' => 'Rewrite the second element',
            'subject_table' => 'tt_content',
            'subject_uid' => 11,
            'subject_pid' => 2,
            'state' => TaskState::IN_PROGRESS->value,
            'workspace_uid' => 1,
        ]);

        $context = $this->decode($this->subject()->activeTaskContextAction($this->getRequest([
            'table' => 'tt_content',
            'uid' => 10,
        ])));
        self::assertSame([$recordTaskUid], array_column($context['tasks'], 'uid'));

        $response = $this->subject()->setActiveTaskForContextAction($this->postRequest([
            'table' => 'tt_content',
            'uid' => 10,
            'taskUid' => $siblingTaskUid,
        ]));
        $selected = $this->decode($response);
        self::assertSame(400, $response->getStatusCode());
        self::assertFalse($selected['success']);
        self::assertSame('task-not-in-context', $selected['code']);
        self::assertNull($this->get(ActiveTaskSession::class)->current($GLOBALS['BE_USER']));
    }

    /*
     * The page uid on all three endpoints is the client's claim, not a fact.
     * Ungated, the two listing actions handed out the task titles and the
     * claimed records of any page in the installation for the asking, and
     * setActiveTaskForPageAction() - which writes - would run a stage
     * transition on a task the caller has no business touching.
     *
     * The editor fixture is a plain non-admin with no group: every page in
     * pages.csv leaves its perms_* columns at the database default, so nothing
     * is readable for them, which is exactly the situation to reject.
     */
    private function switchToEditorWithoutPageAccess(): void
    {
        $this->setUpBackendUser(2);
        $GLOBALS['LANG'] = $this->get(\TYPO3\CMS\Core\Localization\LanguageServiceFactory::class)
            ->createFromUserPreferences($GLOBALS['BE_USER']);
    }

    #[Test]
    public function theListRefusesAPageTheUserMayNotSee(): void
    {
        $this->createTask(['title' => 'Rewrite the intro']);
        $this->switchToEditorWithoutPageAccess();

        $response = $this->subject()->listOpenTasksForPageAction($this->getRequest(['pageUid' => 2]));
        $payload = $this->decode($response);

        self::assertSame(400, $response->getStatusCode());
        self::assertFalse($payload['success']);
        self::assertSame('no-page-show-permission', $payload['code']);
        self::assertArrayNotHasKey('tasks', $payload, 'a rejected request must not leak the list anyway');
    }

    #[Test]
    public function theMarkersRefuseAPageTheUserMayNotSee(): void
    {
        $taskUid = $this->createTask();
        $this->addMember($taskUid, 'tt_content', 10);
        $this->switchToEditorWithoutPageAccess();

        $payload = $this->decode(
            $this->subject()->listMemberTaskMarkersForPageAction($this->getRequest(['pageUid' => 2])),
        );

        self::assertFalse($payload['success']);
        self::assertSame('no-page-show-permission', $payload['code']);
        self::assertArrayNotHasKey('members', $payload);
    }

    #[Test]
    public function pickingATaskIsRefusedForAPageTheUserMayNotEdit(): void
    {
        $taskUid = $this->createTask(['state' => TaskState::BACKLOG->value, 'workspace_uid' => 0]);
        $this->switchToEditorWithoutPageAccess();

        $payload = $this->decode(
            $this->subject()->setActiveTaskForPageAction($this->postRequest(['pageUid' => 2, 'taskUid' => $taskUid])),
        );

        self::assertFalse($payload['success']);
        self::assertSame('no-page-edit-permission', $payload['code']);

        // The write that must not have happened: an ungated call moved the task
        // into the workspace and started the clock on it.
        $task = $this->get(TaskRepository::class)->findByUid($taskUid);
        self::assertSame(TaskState::BACKLOG->value, $task['state']);
        self::assertSame(0, (int)$task['workspace_uid']);
        self::assertNull($this->get(ActiveTaskSession::class)->resolve($GLOBALS['BE_USER'], 2));
    }

    #[Test]
    public function aPageThatDoesNotExistIsRefusedRatherThanAnsweredEmpty(): void
    {
        $payload = $this->decode(
            $this->subject()->listOpenTasksForPageAction($this->getRequest(['pageUid' => 999])),
        );

        self::assertFalse($payload['success']);
        self::assertSame('page-not-found', $payload['code']);
    }

    #[Test]
    public function aMissingPageUidIsRefusedOnEveryEndpoint(): void
    {
        $list = $this->decode($this->subject()->listOpenTasksForPageAction($this->getRequest([])));
        $markers = $this->decode($this->subject()->listMemberTaskMarkersForPageAction($this->getRequest([])));
        $setActive = $this->decode($this->subject()->setActiveTaskForPageAction($this->postRequest(['taskUid' => 1])));

        self::assertSame('missing-page-uid', $list['code']);
        self::assertSame('missing-page-uid', $markers['code']);
        self::assertSame('missing-page-uid', $setActive['code']);
    }
}
