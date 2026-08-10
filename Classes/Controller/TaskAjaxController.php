<?php

declare(strict_types=1);

namespace GbWeb\ContentFlow\Controller;

use GbWeb\ContentFlow\Domain\Model\TaskPriority;
use GbWeb\ContentFlow\Domain\Model\TaskState;
use GbWeb\ContentFlow\Domain\Repository\CommentRepository;
use GbWeb\ContentFlow\Domain\Repository\TaskChecklistRepository;
use GbWeb\ContentFlow\Domain\Repository\TaskRepository;
use GbWeb\ContentFlow\Notification\AssignmentNotificationService;
use GbWeb\ContentFlow\Service\ActiveTaskSession;
use GbWeb\ContentFlow\Service\ActivityLogger;
use GbWeb\ContentFlow\Service\ReferenceInspector;
use GbWeb\ContentFlow\Service\StageTransitionService;
use GbWeb\ContentFlow\Service\TaskMemberSynchronizer;
use GbWeb\ContentFlow\Service\TaskSubjectRegistry;
use GbWeb\ContentFlow\Service\WorkspaceIntegrationService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\StringUtility;
use TYPO3\CMS\Core\View\ViewFactoryData;
use TYPO3\CMS\Core\View\ViewFactoryInterface;
use TYPO3\CMS\Workspaces\Authorization\WorkspacePublishGate;
use TYPO3\CMS\Workspaces\Preview\PreviewUriBuilder;
use TYPO3\CMS\Workspaces\Service\StagesService;

/**
 * Write and detail endpoints for the board and workspace popups.
 */
final class TaskAjaxController
{
    public function __construct(
        private readonly TaskRepository $taskRepository,
        private readonly CommentRepository $commentRepository,
        private readonly TaskChecklistRepository $checklistRepository,
        private readonly TaskSubjectRegistry $subjectRegistry,
        private readonly TaskMemberSynchronizer $memberSynchronizer,
        private readonly ReferenceInspector $referenceInspector,
        private readonly ActivityLogger $activityLogger,
        private readonly ActiveTaskSession $activeTaskSession,
        private readonly AssignmentNotificationService $assignmentNotificationService,
        private readonly WorkspaceIntegrationService $workspaceService,
        private readonly WorkspacePublishGate $workspacePublishGate,
        private readonly StageTransitionService $stageTransitionService,
        private readonly StagesService $stagesService,
        private readonly UriBuilder $uriBuilder,
        private readonly ViewFactoryInterface $viewFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Create a task for a record explicitly picked in the wizard.
     *
     * The "+" flow is intentional user input: if an editor selects a content
     * element or another trackable record here, it should get its own task even
     * though the same record would usually auto-join its page's task.
     */
    public function createAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->getBody($request);
        $table = (string)($body['table'] ?? 'pages');
        $uid = (int)($body['uid'] ?? 0);

        $error = $this->assertMayEdit($table, $uid);
        if ($error !== null) {
            return $this->error($error);
        }

        // Values the wizard collected. Ignoring them would make the wizard a
        // decorative form whose inputs do nothing.
        $title = trim((string)($body['title'] ?? ''));
        $description = trim((string)($body['description'] ?? ''));
        $priority = TaskPriority::fromRequest($body['priority'] ?? null);
        // 'open' means deliberately unassigned so someone can take it - a real
        // planning state, not a missing value.
        $assignee = $this->resolveRequestedAssignee($body['assignee'] ?? 'me');
        $startDate = $this->parseDate($body['startDate'] ?? null);
        $dueDate = $this->parseDate($body['dueDate'] ?? null);

        $task = $this->taskRepository->findOrCreateOpenForSubject($table, $uid, [
            'title' => $title !== '' ? $title : $this->deriveTitle($table, $uid),
            'description' => $description,
            'subject_pid' => $table === 'pages' ? $uid : (int)(BackendUtility::getRecord($table, $uid, 'pid')['pid'] ?? 0),
            // A start date is the editorial commitment "this is being worked
            // on" - the board should already show it as Planned rather than
            // waiting for someone to notice a bare Backlog card and drag it.
            'state' => $startDate > 0 ? TaskState::PLANNED->value : TaskState::BACKLOG->value,
            'priority' => $priority->value,
            'assignee' => $assignee,
            'start_date' => $startDate,
            'due_date' => $dueDate,
            // Planned by a human, so no auto_created flag and no wizard nagging.
            'auto_created' => 0,
        ]);
        $taskUid = (int)$task['uid'];

        $claimed = 0;
        if ($table === 'pages') {
            $claimed = $this->memberSynchronizer->syncPageMembers($taskUid, $uid);
        }

        // Guards the idempotent-existing-task path: findOrCreateOpenForSubject()
        // ignores $values entirely when the task already existed, so the
        // persisted assignee only matches what was requested here when it was
        // genuinely just applied.
        if ((int)$task['assignee'] === $assignee) {
            $this->notifyAssignment($taskUid, (string)$task['title'], $table, $uid, $assignee);
        }

        return new JsonResponse([
            'success' => true,
            'task' => $taskUid,
            'claimed' => $claimed,
        ]);
    }

    /**
     * "Neue Seite erstellen" on the "+ New task" wizard: plans a page that does
     * not exist yet, rather than opening core's page-creation wizard immediately.
     * The page itself is only created once an editor drags this ticket into
     * Editing, where a workspace version starts to exist - see
     * moveStageAction()'s materializePendingPage(). Until then it is a
     * title-only ticket moving freely between Backlog and Planned like any
     * other, just without a real subject record behind it yet.
     */
    public function createPendingPageAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->getBody($request);
        $parentPid = (int)($body['parentPid'] ?? 0);
        if ($parentPid < 1) {
            return $this->reject(
                'missing-parent-page',
                'No parent page was specified to create the new page under.',
                [],
            );
        }
        if (!$this->mayCreatePageUnder($parentPid)) {
            return $this->reject(
                'no-permission',
                'You are not allowed to create a new page here.',
                ['parentPid' => $parentPid],
            );
        }

        $title = trim((string)($body['title'] ?? ''));
        if ($title === '') {
            return $this->reject('task-title-required', 'A title is required.', []);
        }
        $description = trim((string)($body['description'] ?? ''));
        $priority = TaskPriority::fromRequest($body['priority'] ?? null);
        $assignee = $this->resolveRequestedAssignee($body['assignee'] ?? 'me');
        $startDate = $this->parseDate($body['startDate'] ?? null);
        $dueDate = $this->parseDate($body['dueDate'] ?? null);

        $task = $this->taskRepository->createPendingPageTask($parentPid, [
            'title' => $title,
            'description' => $description,
            'state' => $startDate > 0 ? TaskState::PLANNED->value : TaskState::BACKLOG->value,
            'priority' => $priority->value,
            'assignee' => $assignee,
            'start_date' => $startDate,
            'due_date' => $dueDate,
            'auto_created' => 0,
        ]);
        $taskUid = (int)$task['uid'];

        $this->notifyAssignment($taskUid, $title, 'pages', 0, $assignee);

        return new JsonResponse(['success' => true, 'task' => $taskUid]);
    }

    /**
     * "Select to task": move the selected records onto a task.
     *
     * Records already belonging to another open task are moved, not duplicated -
     * a record lives in exactly one open task, and that invariant is what the whole
     * detach mechanism rests on.
     */
    public function attachAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->getBody($request);
        $taskUid = (int)($body['task'] ?? 0);
        $records = is_array($body['records'] ?? null) ? $body['records'] : [];

        $task = $this->findOpenTaskOrError($taskUid, 'attach records to it');
        if ($task instanceof ResponseInterface) {
            return $task;
        }

        $moved = [];
        $refused = [];
        foreach ($records as $record) {
            $table = (string)($record['table'] ?? '');
            $uid = (int)($record['uid'] ?? 0);

            $error = $this->assertMayEdit($table, $uid);
            if ($error !== null) {
                $refused[] = ['table' => $table, 'uid' => $uid] + $this->logAndExposeError($error);
                continue;
            }

            $homePid = $this->derivePid($table, $uid);
            if ($this->taskRepository->findOpenTaskByMember($table, $uid) !== null) {
                $this->taskRepository->moveMemberToTask($table, $uid, $taskUid);
            } else {
                $this->taskRepository->addMemberIfUnclaimed(
                    $taskUid,
                    $table,
                    $uid,
                    TaskRepository::ORIGIN_MANUAL,
                    $homePid,
                    $this->referenceInspector->isSharedAcrossPages($table, $uid, $homePid),
                );
            }
            $moved[] = ['table' => $table, 'uid' => $uid];
        }

        return new JsonResponse([
            'success' => $refused === [],
            'moved' => $moved,
            'refused' => $refused,
        ]);
    }

    /**
     * "Split from task": pull a record out into a task of its own.
     *
     * The escape hatch from page aggregation - one banner really being its own
     * piece of work. The board can route any trackable record back to its edit
     * form, so an editor may promote even page-bound content into its own task.
     */
    public function detachAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->getBody($request);
        $table = (string)($body['table'] ?? '');
        $uid = (int)($body['uid'] ?? 0);

        $error = $this->assertMayEdit($table, $uid);
        if ($error !== null) {
            return $this->error($error);
        }

        $current = $this->taskRepository->findOpenTaskByMember($table, $uid);
        if ($current === null) {
            return $this->reject(
                'record-not-in-open-task',
                'This record does not belong to an open task, so there is nothing to split.',
                ['table' => $table, 'uid' => $uid],
            );
        }
        if ((string)$current['subject_table'] === $table && (int)$current['subject_uid'] === $uid) {
            return $this->reject(
                'cannot-split-task-from-itself',
                'This is already the task\'s own subject - it cannot be split from itself.',
                ['table' => $table, 'uid' => $uid, 'taskUid' => (int)$current['uid']],
            );
        }

        $task = $this->taskRepository->detachIntoOwnTask($table, $uid, [
            'title' => $this->deriveTitle($table, $uid),
            'subject_pid' => $this->derivePid($table, $uid),
            'state' => (string)$current['state'],
            'stage_uid' => (int)$current['stage_uid'],
            'workspace_uid' => (int)$current['workspace_uid'],
            'assignee' => (int)($this->getBackendUser()->user['uid'] ?? 0),
            'auto_created' => 0,
        ]);

        return new JsonResponse([
            'success' => true,
            'task' => (int)$task['uid'],
            'from' => (int)$current['uid'],
        ]);
    }

    /**
     * A shareable link to preview one member's pending version - the workspace
     * module's own "view" action (WorkspacesAjaxController::viewSingleRecord()),
     * scoped to a single record instead of the whole page.
     */
    public function previewMemberAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->getBody($request);
        $table = (string)($body['table'] ?? '');
        $uid = (int)($body['uid'] ?? 0);

        $error = $this->assertMayEdit($table, $uid);
        if ($error !== null) {
            return $this->error($error);
        }

        $task = $this->taskRepository->findOpenTaskByMember($table, $uid);
        if ($task === null) {
            return $this->reject(
                'record-not-in-open-task',
                'This record does not belong to an open task.',
                ['table' => $table, 'uid' => $uid],
            );
        }

        $workspaceUid = (int)$task['workspace_uid'];
        // buildUriForElement() wants the version(!) uid - passing the live uid
        // straight through would preview the already-live content, defeating
        // the point of a workspace preview.
        $versionRecord = $workspaceUid > 0
            ? BackendUtility::getWorkspaceVersionOfRecord($workspaceUid, $table, $uid, 'uid')
            : false;
        $versionUid = $versionRecord !== false ? (int)$versionRecord['uid'] : $uid;

        $url = GeneralUtility::makeInstance(PreviewUriBuilder::class)->buildUriForElement($table, $versionUid);
        if ($url === '') {
            return $this->reject(
                'preview-unavailable',
                'Could not build a preview link for that record.',
                ['table' => $table, 'uid' => $uid],
            );
        }

        return new JsonResponse(['success' => true, 'url' => $url]);
    }

    /**
     * Throw away one member's pending version, without touching its task
     * membership - the live record stays claimed for whenever it is next
     * edited. `discard()` accepts either uid and resolves the live one to its
     * version itself (TYPO3\CMS\Core\DataHandling\DataHandler::discard()), so
     * no separate version lookup is needed here the way publish/setStage need one.
     */
    public function discardMemberAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->getBody($request);
        $table = (string)($body['table'] ?? '');
        $uid = (int)($body['uid'] ?? 0);

        $error = $this->assertMayEdit($table, $uid);
        if ($error !== null) {
            return $this->error($error);
        }

        $task = $this->taskRepository->findOpenTaskByMember($table, $uid);
        if ($task === null) {
            return $this->reject(
                'record-not-in-open-task',
                'This record does not belong to an open task.',
                ['table' => $table, 'uid' => $uid],
            );
        }

        $workspaceUid = (int)$task['workspace_uid'];
        if ($workspaceUid < 1) {
            return $this->reject(
                'no-pending-versions',
                'This record has no pending version to discard.',
                ['table' => $table, 'uid' => $uid],
            );
        }

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], [$table => [$uid => ['discard' => true]]]);
        $dataHandler->process_cmdmap();

        if ($dataHandler->errorLog !== []) {
            $this->logger->warning('core-refused-discard', [
                'table' => $table,
                'uid' => $uid,
                'taskUid' => (int)$task['uid'],
                'errors' => $dataHandler->errorLog,
            ]);
            return new JsonResponse([
                'success' => false,
                'code' => 'core-refused-discard',
                'message' => implode(' ', array_map('strval', $dataHandler->errorLog)),
            ], 400);
        }

        $this->activityLogger->log((int)$task['uid'], ActivityLogger::EVENT_DISCARDED, (int)($this->getBackendUser()->user['uid'] ?? 0), [
            'table' => $table,
            'liveUid' => $uid,
            'workspaceUid' => $workspaceUid,
        ]);

        return new JsonResponse(['success' => true]);
    }

    /**
     * Move a task to a different stage or state (column drop).
     */
    public function moveStageAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->getBody($request);
        $taskUid = (int)($body['task'] ?? 0);
        $targetState = (string)($body['state'] ?? 'backlog');
        $targetStageUid = (int)($body['stageUid'] ?? 0);

        $task = $this->findOpenTaskOrError($taskUid, 'move it');
        if ($task instanceof ResponseInterface) {
            return $task;
        }

        $state = TaskState::tryFrom($targetState);

        // A pending page ("Neue Seite erstellen" - see createPendingPageAction())
        // has no real subject yet, so assertMayEdit() below would reject it on
        // subject_uid 0 alone. The page is created the moment the ticket reaches
        // a stage that needs one - the first of those is Editing, where a
        // workspace version starts to exist.
        //
        // Backlog and Planned are *not* such a stage: moving between them is
        // planning, writes nothing but this extension's own column, and needs no
        // page. Rejecting that move used to tell an editor their entirely
        // correct drag was wrong ("move it to a review stage to create it"),
        // which is what this distinction is for.
        $isPendingPage = (string)$task['subject_table'] === 'pages' && (int)$task['subject_uid'] === 0;

        if ($isPendingPage) {
            if ($state === null) {
                return $this->reject(
                    'unknown-target-column',
                    'This ticket cannot be moved to that column.',
                    ['taskUid' => $taskUid, 'targetState' => $targetState],
                );
            }
            if ($state === TaskState::DONE) {
                return $this->reject(
                    'pending-page-cannot-be-done',
                    'This ticket never became a page, so there is nothing to finish. Start work on it first.',
                    ['taskUid' => $taskUid],
                );
            }
            if ($state->hasVersion()) {
                $materialized = $this->materializePendingPage($task);
                if ($materialized instanceof ResponseInterface) {
                    return $materialized;
                }
                $task = $materialized;
                $isPendingPage = false;
            }
        }

        // Skipped for a ticket that still has no page: there is no record to
        // hold a permission on, and the move about to happen writes none.
        if (!$isPendingPage) {
            $error = $this->assertMayEdit((string)$task['subject_table'], (int)$task['subject_uid']);
            if ($error !== null) {
                return $this->error($error);
            }
        }

        // A drop onto a core stage column is a workspace stage transition and must
        // go through core - permissions, sys_history and stage notifications all
        // live there. Only Content Flow's own columns (Backlog / Planned), which
        // exist precisely because core has no state for "not versioned yet", are
        // written directly.
        if ($state !== null && $state->hasVersion()) {
            return $this->executeStageAction($request);
        }

        if ((int)$task['workspace_uid'] > 0) {
            return $this->reject(
                'cannot-return-versioned-task-to-planning',
                'This task already has a workspace version, so it cannot be moved back to a planning column.',
                ['taskUid' => $taskUid, 'workspaceUid' => (int)$task['workspace_uid']],
            );
        }

        $this->taskRepository->moveToColumn($taskUid, $targetState, $targetStageUid);

        $beUserId = (int)($this->getBackendUser()->user['uid'] ?? 0);
        $this->activityLogger->log($taskUid, ActivityLogger::EVENT_STAGE_CHANGED, $beUserId, [
            'from_state' => $task['state'],
            'from_stage' => (int)$task['stage_uid'],
            'to_state' => $targetState,
            'to_stage' => $targetStageUid,
        ]);

        return new JsonResponse(['success' => true]);
    }

    /**
     * Assign the task to the current logged in backend user.
     */
    public function assignMeAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->getBody($request);
        $taskUid = (int)($body['task'] ?? 0);
        $beUserId = (int)($this->getBackendUser()->user['uid'] ?? 0);

        $task = $this->findOpenTaskOrError($taskUid, 'assign it');
        if ($task instanceof ResponseInterface) {
            return $task;
        }

        $this->taskRepository->assignTo($taskUid, $beUserId);

        $this->activityLogger->log($taskUid, ActivityLogger::EVENT_ASSIGNED, $beUserId, [
            'assignee' => $beUserId,
        ]);

        return new JsonResponse(['success' => true]);
    }

    /**
     * Fetch complete task inspector details (diffs, comments, activities, editUrl, recipients).
     */
    public function detailsAction(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $taskUid = (int)($params['task'] ?? 0);
        if ($taskUid < 1) {
            $body = $this->getBody($request);
            $taskUid = (int)($body['task'] ?? 0);
        }

        $details = $this->workspaceService->getTaskDetails($taskUid);
        if ($details === null) {
            return $this->reject('task-not-found', 'This task no longer exists.', ['taskUid' => $taskUid]);
        }

        $subjectTable = (string)$details['subject']['table'];
        $subjectUid = (int)$details['subject']['uid'];

        $editUrl = (string)$this->uriBuilder->buildUriFromRoute('record_edit', [
            'edit' => [$subjectTable => [$subjectUid => 'edit']],
            'returnUrl' => '',
        ]);

        $recipients = $this->workspaceService->getStageRecipients();

        return new JsonResponse([
            'success' => true,
            'details' => $details,
            'editUrl' => $editUrl,
            'recipients' => $recipients,
        ]);
    }

    /**
     * Every open task touching a page, across every stage from Backlog
     * through the stage just before Done - feeds the Visual Editor's
     * persistent "Task" select (B4). Picking one there is a proactive,
     * before-the-fact declaration of where an edit should land, which is
     * what setActiveTaskForPageAction() records - this action only lists the
     * choices. See TaskRepository::findAllOpenForPage().
     */
    public function listOpenTasksForPageAction(ServerRequestInterface $request): ResponseInterface
    {
        $pageUid = (int)($request->getQueryParams()['pageUid'] ?? 0);

        $tasks = array_map(
            fn (array $task): array => [
                'uid' => (int)$task['uid'],
                'title' => (string)$task['title'],
                'state' => (string)$task['state'],
                'stageLabel' => $this->stageLabelFor($task),
            ],
            $this->taskRepository->findAllOpenForPage($pageUid),
        );

        // The choice an editor made earlier still routes every save on this page
        // (TaskAutoCreationService::captureEdit()), so the select has to be able
        // to show it after a reload. Without this the select came back on its
        // placeholder while the server kept routing to a task nobody could see -
        // and the markers below, which key off "which task is active", treated
        // the active task's own records as foreign.
        $activeTaskUid = $this->activeTaskSession->resolve($this->getBackendUser(), $pageUid);

        return new JsonResponse([
            'success' => true,
            'tasks' => $tasks,
            'activeTaskUid' => $activeTaskUid ?? 0,
        ]);
    }

    /**
     * A human-readable name for wherever a task currently sits - a core stage
     * title for anything with a workspace version (StagesService::
     * getStageTitle() already handles Editing/Ready to publish/custom stages
     * uniformly, by stage uid alone), or this extension's own column label
     * for Backlog/Planned, which core has no notion of at all.
     *
     * @param array<string, mixed> $task
     */
    private function stageLabelFor(array $task): string
    {
        $state = TaskState::tryFrom((string)$task['state']);
        if ($state !== null && $state->hasVersion()) {
            return $this->stagesService->getStageTitle((int)$task['stage_uid']);
        }

        return match ($state) {
            TaskState::BACKLOG => $this->getLanguageService()->sL(
                'LLL:EXT:content_flow/Resources/Private/Language/locallang.xlf:column.backlog'
            ) ?: 'Backlog',
            TaskState::PLANNED => $this->getLanguageService()->sL(
                'LLL:EXT:content_flow/Resources/Private/Language/locallang.xlf:column.planned'
            ) ?: 'Planned',
            default => ucfirst((string)$task['state']),
        };
    }

    /**
     * B4: declare which open task an editor is about to work on, for a given
     * page - the Visual Editor's persistent "Task" select, chosen before any
     * edit happens rather than routed after the fact. Two things follow from
     * that:
     *
     * - Backlog/Planned -> Editing, and Review/Ready regressed back to
     *   Editing with an auto-generated comment - the same transitions
     *   TaskAutoCreationService::maybeRegressPastEditing() makes reactively
     *   on an edit, just made explicit by picking the task instead of
     *   waiting for the first keystroke to imply it.
     * - The choice is remembered in session (`content_flow_active_task`) so
     *   TaskAutoCreationService::captureEdit() can claim whatever gets
     *   edited next straight onto it, for any surface (Visual Editor,
     *   Layout, Records) - not only the module this request came from.
     *
     * Never itself creates a task - "+ Create new task" in the select reuses
     * createAction() (table=pages) first and calls this action with the
     * resulting uid, the same as any other existing-task choice.
     *
     * `taskUid = 0` is the way back out: it drops the declaration and moves
     * nothing. A choice that could be made but never unmade would be a trap,
     * because it keeps routing saves on this page long after the editor has
     * stopped thinking about it.
     */
    public function setActiveTaskForPageAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->getBody($request);
        $pageUid = (int)($body['pageUid'] ?? 0);
        $taskUid = (int)($body['taskUid'] ?? 0);

        if ($pageUid < 1) {
            return $this->reject('missing-page-uid', $this->veLabel('ve.error.noPageGiven'), ['taskUid' => $taskUid]);
        }

        if ($taskUid === 0) {
            $this->activeTaskSession->forget($this->getBackendUser());

            return new JsonResponse([
                'success' => true,
                'taskUid' => 0,
                'state' => '',
                'stageLabel' => '',
                'transitioned' => false,
                'comment' => '',
                'commentUid' => 0,
            ]);
        }

        $task = $this->findOpenTaskOrError($taskUid, 'make it the active task');
        if ($task instanceof ResponseInterface) {
            return $task;
        }

        $beUser = $this->getBackendUser();
        $beUserId = (int)($beUser->user['uid'] ?? 0);
        $workspaceUid = (int)$beUser->workspace;

        $transitioned = false;
        $comment = '';
        $commentUid = 0;

        if ((int)$task['workspace_uid'] === 0) {
            if ($workspaceUid < 1) {
                return $this->reject(
                    'no-workspace-selected',
                    $this->veLabel('ve.error.noWorkspaceSelected'),
                    ['taskUid' => $taskUid],
                );
            }
            // Backlog/Planned -> Editing, mirroring what a first captured
            // edit already does today (TaskAutoCreationService::
            // captureEdit()) - just triggered by intent instead of by the
            // edit itself.
            $this->taskRepository->attachWorkspace($taskUid, $workspaceUid, StagesService::STAGE_EDIT_ID);
            $this->activityLogger->log($taskUid, ActivityLogger::EVENT_WORK_STARTED, $beUserId, [
                'pageUid' => $pageUid,
                'selectedInVisualEditor' => true,
            ]);
            $transitioned = true;
            $task = $this->taskRepository->findByUid($taskUid) ?? $task;
        } else {
            $state = TaskState::tryFrom((string)$task['state']);
            if ($state === TaskState::REVIEW || $state === TaskState::READY) {
                $versionsByTable = $this->memberSynchronizer->findPendingVersionsByTable($taskUid, (int)$task['workspace_uid']);
                if ($versionsByTable !== []) {
                    $comment = $this->veLabel('ve.comment.reopened');
                    $refusal = $this->stageTransitionService->transition(
                        $task,
                        $versionsByTable,
                        StagesService::STAGE_EDIT_ID,
                        $beUserId,
                        $comment,
                    );
                    if ($refusal === null) {
                        $transitioned = true;
                        $task = $this->taskRepository->findByUid($taskUid) ?? $task;
                        $comments = $this->commentRepository->findByTask($taskUid);
                        $lastComment = end($comments);
                        $commentUid = $lastComment !== false ? (int)$lastComment['uid'] : 0;
                    } else {
                        $comment = '';
                    }
                }
            }
        }

        $this->activeTaskSession->remember($beUser, $pageUid, $taskUid);

        return new JsonResponse([
            'success' => true,
            'taskUid' => $taskUid,
            'state' => (string)$task['state'],
            'stageLabel' => $this->stageLabelFor($task),
            'transitioned' => $transitioned,
            'comment' => $comment,
            'commentUid' => $commentUid,
        ]);
    }

    /**
     * B4's markers: for every open task touching a page, which records it
     * already claims - so the Visual Editor can mark a content element that
     * belongs to a *different* task than the one currently active, before an
     * editor accidentally edits into it. Excludes closed/Done tasks for the
     * same reason findAllOpenForPage() does: nothing to warn about once work
     * is finished.
     *
     * Each member carries a list of `table:uid` identifiers rather than one
     * uid, because the two sides do not agree on which uid names a record.
     * Membership rows hold the LIVE uid; the Visual Editor renders the
     * frontend page workspace-overlaid, and EXT:visual_editor's
     * ContentElementWrapperService writes `uid = localizedUid ?: versionedUid
     * ?: uid` onto every `ve-content-element` - and `versionedUid` is
     * `_ORIG_uid`, which PageRepository::versionOL() sets to the VERSION while
     * leaving `uid` live. So exactly the interesting case, a task being worked
     * on, would never match on a single uid. Sending both, and letting the
     * client match either, is what makes the markers appear at all.
     */
    public function listMemberTaskMarkersForPageAction(ServerRequestInterface $request): ResponseInterface
    {
        $pageUid = (int)($request->getQueryParams()['pageUid'] ?? 0);
        $workspaceUid = (int)$this->getBackendUser()->workspace;

        $tasks = $this->taskRepository->findAllOpenForPage($pageUid);

        $taskList = [];
        $members = [];
        foreach ($tasks as $task) {
            $taskUid = (int)$task['uid'];
            $taskList[] = ['uid' => $taskUid, 'title' => (string)$task['title']];
            foreach ($this->taskRepository->findMembers($taskUid) as $member) {
                $table = (string)$member['record_table'];
                $liveUid = (int)$member['record_uid'];
                $members[] = [
                    'table' => $table,
                    'uid' => $liveUid,
                    'taskUid' => $taskUid,
                    'identifiers' => $this->memberIdentifiers($table, $liveUid, $workspaceUid),
                ];
            }
        }

        return new JsonResponse(['success' => true, 'tasks' => $taskList, 'members' => $members]);
    }

    /**
     * Every `table:uid` spelling one member can appear under in the rendered
     * frontend - the live record, plus its pending workspace version when one
     * exists. Resolution is TaskMemberSynchronizer's, not a second copy of it,
     * so a record created directly inside the workspace (no live counterpart)
     * is handled the same way here as everywhere else.
     *
     * @return list<string>
     */
    private function memberIdentifiers(string $table, int $liveUid, int $workspaceUid): array
    {
        $identifiers = [$table . ':' . $liveUid];

        $versionUid = $this->memberSynchronizer->findVersionUid($table, $liveUid, $workspaceUid);
        if ($versionUid > 0 && $versionUid !== $liveUid) {
            $identifiers[] = $table . ':' . $versionUid;
        }

        return $identifiers;
    }

    /**
     * Execute stage change with stage comments and recipients.
     */
    public function executeStageAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->getBody($request);
        $taskUid = (int)($body['task'] ?? 0);
        $targetStageUid = (int)($body['stageUid'] ?? 0);
        $comment = trim((string)($body['comment'] ?? ''));
        $additionalRecipients = trim((string)($body['additional'] ?? ''));
        $selectedRecipientUids = [];
        if (is_array($body['recipients'] ?? null)) {
            foreach ($body['recipients'] as $recipientUid) {
                $recipientUid = (int)$recipientUid;
                if ($recipientUid > 0) {
                    $selectedRecipientUids[$recipientUid] = $recipientUid;
                }
            }
        }

        $task = $this->findOpenTaskOrError($taskUid, 'change its stage');
        if ($task instanceof ResponseInterface) {
            return $task;
        }

        $workspaceUid = (int)$task['workspace_uid'];
        if ($workspaceUid < 1) {
            return $this->reject(
                'no-workspace-version',
                'This task has no workspace version yet, so it cannot change stage.',
                ['taskUid' => $taskUid],
            );
        }

        $versionsByTable = $this->memberSynchronizer->findPendingVersionsByTable($taskUid, $workspaceUid);
        if ($versionsByTable === []) {
            return $this->reject(
                'no-pending-versions',
                'There is nothing pending on this task to move to another stage.',
                ['taskUid' => $taskUid, 'workspaceUid' => $workspaceUid],
            );
        }

        $recipients = $this->workspaceService->buildNotificationRecipients(
            $workspaceUid,
            $targetStageUid,
            array_values($selectedRecipientUids),
            $additionalRecipients,
        );

        // Read before the transition moves the task on: a soft warning about
        // the stage being LEFT, not the one being entered - see
        // TaskChecklistRepository::countIncomplete(). Never blocks the move;
        // core is already the one true gate for whether this transition is
        // allowed at all.
        $incompleteChecklistItems = $this->checklistRepository->countIncomplete($taskUid, $workspaceUid, (int)$task['stage_uid']);

        $refusal = $this->stageTransitionService->transition(
            $task,
            $versionsByTable,
            $targetStageUid,
            (int)($this->getBackendUser()->user['uid'] ?? 0),
            $comment,
            $recipients,
        );
        if ($refusal !== null) {
            // Core refused. Our own state must not drift away from what core did,
            // so nothing is written on this path. Logged at warning, not notice -
            // a core-level refusal usually means a permission or stage-workflow
            // misconfiguration worth a developer's attention, not routine input.
            $this->logger->warning('core-refused-stage-change', [
                'taskUid' => $taskUid,
                'targetStageUid' => $targetStageUid,
                'reason' => $refusal,
                'beUser' => (int)($this->getBackendUser()->user['uid'] ?? 0),
            ]);
            return new JsonResponse([
                'success' => false,
                'code' => 'core-refused-stage-change',
                'message' => $refusal,
            ], 400);
        }

        return new JsonResponse([
            'success' => true,
            'stageUid' => $targetStageUid,
            'incompleteChecklistItems' => $incompleteChecklistItems,
        ]);
    }

    /**
     * Publish everything a task still has pending, straight to live.
     *
     * Deliberately not a drop target - going live is irreversible, so the board
     * makes it an explicit, confirmed action instead of something a slightly
     * off-target drop could trigger (ARCHITECTURE.md).
     *
     * Gated by WorkspacePublishGate, the same check core's own
     * WorkspacesAjaxController::publishSingleRecord() uses: owner/admin only,
     * independent of stage `responsible_persons` - reaching the final stage
     * never implies permission to actually publish.
     *
     * Closing the task once everything is live is not done here:
     * CloseTaskAfterPublishListener does it off core's own
     * AfterRecordPublishedEvent, one per published record, so a task that covers
     * several records only closes once none of them are pending any more.
     */
    public function publishTaskAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->getBody($request);
        $taskUid = (int)($body['task'] ?? 0);

        $task = $this->findOpenTaskOrError($taskUid, 'publish it');
        if ($task instanceof ResponseInterface) {
            return $task;
        }

        $workspaceUid = (int)$task['workspace_uid'];
        if ($workspaceUid < 1) {
            return $this->reject(
                'no-workspace-version',
                'This task has no workspace version yet, so there is nothing to publish.',
                ['taskUid' => $taskUid],
            );
        }

        if (!$this->workspacePublishGate->isGranted($this->getBackendUser(), $workspaceUid)) {
            return $this->reject(
                'publish-not-permitted',
                'You are not allowed to publish in this workspace.',
                ['taskUid' => $taskUid, 'workspaceUid' => $workspaceUid],
            );
        }

        $pairsByTable = $this->memberSynchronizer->findPendingVersionPairsByTable($taskUid, $workspaceUid);
        if ($pairsByTable === []) {
            return $this->reject(
                'no-pending-versions',
                'There is nothing pending on this task to publish.',
                ['taskUid' => $taskUid, 'workspaceUid' => $workspaceUid],
            );
        }

        $refusal = $this->askCoreToPublish($pairsByTable);
        if ($refusal !== null) {
            $this->logger->warning('core-refused-publish', [
                'taskUid' => $taskUid,
                'workspaceUid' => $workspaceUid,
                'reason' => $refusal,
                'beUser' => (int)($this->getBackendUser()->user['uid'] ?? 0),
            ]);
            return new JsonResponse([
                'success' => false,
                'code' => 'core-refused-publish',
                'message' => $refusal,
            ], 400);
        }

        // CloseTaskAfterPublishListener already closed the task, if everything it
        // covers is now live - re-read rather than assume, since a task with
        // members outside this workspace's pending set stays open.
        $reloaded = $this->taskRepository->findByUid($taskUid);

        return new JsonResponse([
            'success' => true,
            'closed' => $reloaded === null || (bool)$reloaded['closed'],
        ]);
    }

    /**
     * Hand the publish to TYPO3 and report back whether it was refused.
     *
     * Keyed by the LIVE uid with the version as `swapWith` - the opposite order
     * from setStage/discard, which are keyed by the version uid. Verified
     * directly against TYPO3\CMS\Workspaces\Hook\DataHandlerHook::version_swap():
     * $id (the cmdmap key) must be the live record, $swapWith must be the
     * version whose own t3ver_oid points back at $id. Getting this backwards
     * fails with "In offline record, either t3ver_oid was not set or the
     * t3ver_oid didn't match the id of the online version as it must" - see
     * WORKSPACE-STAGES.md.
     *
     * @param array<string, list<array{live: int, version: int}>> $pairsByTable
     * @return string|null the refusal reason, or null when core accepted
     */
    private function askCoreToPublish(array $pairsByTable): ?string
    {
        $cmd = [];
        foreach ($pairsByTable as $table => $pairs) {
            foreach ($pairs as $pair) {
                $cmd[$table][$pair['live']]['version'] = [
                    'action' => 'publish',
                    'swapWith' => $pair['version'],
                ];
            }
        }

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], $cmd);
        $dataHandler->process_cmdmap();

        return $dataHandler->errorLog === []
            ? null
            : implode(' ', array_map('strval', $dataHandler->errorLog));
    }

    /**
     * Turns a pending page ("Neue Seite erstellen", see createPendingPageAction())
     * into a real one, in the current workspace - the ticket's own intended
     * parent (subject_pid) is where it lands, and its title becomes the page
     * title. Called only from moveStageAction(), once, right before the normal
     * stage-transition flow runs on the now-real subject.
     *
     * @param array<string, mixed> $task
     * @return array<string, mixed>|ResponseInterface the refreshed task row, or
     *         an error response
     */
    private function materializePendingPage(array $task): array|ResponseInterface
    {
        $taskUid = (int)$task['uid'];
        $parentPid = (int)$task['subject_pid'];
        if ($parentPid < 1) {
            return $this->reject(
                'pending-page-no-parent',
                'This ticket has no parent page to create the new page under.',
                ['taskUid' => $taskUid],
            );
        }
        if (!$this->mayCreatePageUnder($parentPid)) {
            return $this->reject(
                'no-permission',
                'You are not allowed to create a new page here.',
                ['taskUid' => $taskUid, 'parentPid' => $parentPid],
            );
        }

        $workspaceUid = (int)$this->getBackendUser()->workspace;
        if ($workspaceUid < 1) {
            return $this->reject(
                'no-workspace-selected',
                'Switch into a workspace before starting work on this ticket.',
                ['taskUid' => $taskUid],
            );
        }

        $placeholder = StringUtility::getUniqueId('NEW');
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([
            'pages' => [
                $placeholder => [
                    'pid' => $parentPid,
                    'title' => (string)$task['title'],
                ],
            ],
        ], []);
        $dataHandler->process_datamap();
        $newPageUid = (int)($dataHandler->substNEWwithIDs[$placeholder] ?? 0);
        if ($newPageUid < 1) {
            return $this->reject(
                'pending-page-create-failed',
                'Could not create the new page.',
                ['taskUid' => $taskUid, 'errors' => $dataHandler->errorLog],
            );
        }

        $this->taskRepository->attachCreatedSubject($taskUid, $newPageUid);
        // Editing (stage 0) is where a brand new workspace record already sits by
        // default - this just makes the task's own bookkeeping match reality, the
        // same way TaskAutoCreationService::captureEdit() does for an
        // auto-created task's first edit.
        $this->taskRepository->attachWorkspace($taskUid, $workspaceUid, 0);

        $this->activityLogger->log($taskUid, ActivityLogger::EVENT_WORK_STARTED, (int)($this->getBackendUser()->user['uid'] ?? 0), [
            'table' => 'pages',
            'recordUid' => $newPageUid,
            'parentPid' => $parentPid,
        ]);

        $refreshed = $this->taskRepository->findByUid($taskUid);
        return $refreshed ?? $task;
    }

    private function mayCreatePageUnder(int $parentPid): bool
    {
        $backendUser = $this->getBackendUser();
        if ($backendUser->isAdmin()) {
            return true;
        }
        $parentPage = BackendUtility::getRecord('pages', $parentPid);
        if ($parentPage === null) {
            return false;
        }
        return $backendUser->doesUserHaveAccess($parentPage, Permission::PAGE_NEW);
    }

    /**
     * May the current user edit this record at all?
     *
     * Every branch names *which* check failed, not just that one did - "no
     * permission" alone is not actionable for an editor filing a bug report, and
     * "edit-not-allowed" covers several genuinely different TYPO3 conditions
     * (deleted parent, disabled record, language mismatch...) that a developer
     * reading the log needs told apart from a plain permission gap.
     *
     * @return TaskActionError|null the failure, or null when allowed
     */
    private function assertMayEdit(string $table, int $uid): ?TaskActionError
    {
        if ($uid < 1) {
            return new TaskActionError('missing-record-uid', 'No record was specified.', ['table' => $table]);
        }
        if (!$this->subjectRegistry->isTrackable($table)) {
            return new TaskActionError(
                'table-not-trackable',
                sprintf('"%s" records cannot be tracked here - they support no workspace versioning.', $table),
                ['table' => $table],
            );
        }

        $record = BackendUtility::getRecord($table, $uid);
        if ($record === null) {
            return new TaskActionError(
                'record-not-found',
                sprintf('%s:%d no longer exists.', $table, $uid),
                ['table' => $table, 'uid' => $uid],
            );
        }

        $backendUser = $this->getBackendUser();
        if ($table === 'pages') {
            if (!$backendUser->doesUserHaveAccess($record, Permission::PAGE_EDIT)) {
                return new TaskActionError(
                    'no-page-edit-permission',
                    'You do not have edit permission on this page.',
                    ['table' => $table, 'uid' => $uid],
                );
            }
        } else {
            $page = BackendUtility::getRecord('pages', (int)($record['pid'] ?? 0));
            if ($page === null || !$backendUser->doesUserHaveAccess($page, Permission::CONTENT_EDIT)) {
                return new TaskActionError(
                    'no-content-edit-permission',
                    'You do not have edit permission on the page this record is on.',
                    ['table' => $table, 'uid' => $uid, 'pagePid' => $record['pid'] ?? null],
                );
            }
        }
        // checkRecordEditAccess() over the older recordEditAccessInternals(): the
        // latter is deprecated since v14 (removed in v15) and trips
        // failOnDeprecation in the test suite on every call. Both are marked
        // @internal ("should only be used from within TYPO3 Core") - there is
        // currently no public, non-deprecated API for this specific check. Given
        // this extension targets v14.3.x only (composer.json: ^14.3), the
        // non-deprecated internal method is the more honest trade: it will not
        // spam deprecation logs in production, and it is what core's own
        // controllers use for the same check today.
        $accessResult = $backendUser->checkRecordEditAccess($table, $record);
        if (!$accessResult->isAllowed) {
            return new TaskActionError(
                'record-edit-not-allowed',
                $accessResult->errorMessage !== ''
                    ? $accessResult->errorMessage
                    : sprintf('%s:%d cannot be edited right now.', $table, $uid),
                ['table' => $table, 'uid' => $uid],
            );
        }
        // The workspace is always the user's own - never taken from the request.
        if (!$backendUser->workspaceAllowsLiveEditingInTable($table) && $backendUser->workspace === 0) {
            return new TaskActionError(
                'live-editing-not-allowed',
                sprintf('"%s" records cannot be edited directly on the Live workspace.', $table),
                ['table' => $table, 'workspace' => $backendUser->workspace],
            );
        }

        return null;
    }

    /**
     * Parses an HTML `<input type="date">` value ("YYYY-MM-DD") into a unix
     * timestamp. 0 means "not set" - the wizard's fields are optional, and
     * `start_date`/`due_date` both default to 0 in ext_tables.sql for exactly
     * that reason, not to a sentinel that could collide with a real date.
     */
    private function parseDate(mixed $rawDate): int
    {
        $value = trim((string)($rawDate ?? ''));
        if ($value === '') {
            return 0;
        }
        $timestamp = strtotime($value . ' 00:00:00');

        return $timestamp !== false ? $timestamp : 0;
    }

    private function deriveTitle(string $table, int $uid): string
    {
        $record = BackendUtility::getRecord($table, $uid);
        if ($record === null) {
            return sprintf('%s:%d', $table, $uid);
        }
        $title = BackendUtility::getRecordTitle($table, $record);

        return $title !== '' ? $title : sprintf('%s:%d', $table, $uid);
    }

    private function derivePid(string $table, int $uid): int
    {
        if ($table === 'pages') {
            return $uid;
        }

        return (int)(BackendUtility::getRecord($table, $uid, 'pid')['pid'] ?? 0);
    }

    /**
     * 'open' means deliberately unassigned so someone can take the task later.
     * 'me' and anything else that is not a valid be_user uid collapse to the
     * current editor. A specific uid - offered by the assignee picker in
     * wizard/steps/task-details-step.js, backed by the same be_users list
     * LoadWizardModuleEventListener exposes - is honoured once verified to be
     * a real, non-deleted user: never trust a client-supplied uid without
     * looking it up.
     */
    private function resolveRequestedAssignee(mixed $rawAssignee): int
    {
        if ((string)$rawAssignee === 'open') {
            return 0;
        }
        $requestedUid = (int)$rawAssignee;
        if ($requestedUid > 0 && BackendUtility::getRecord('be_users', $requestedUid, 'uid') !== null) {
            return $requestedUid;
        }
        return (int)($this->getBackendUser()->user['uid'] ?? 0);
    }

    /**
     * Tell an assignee they were handed a task.
     *
     * Deliberately does not decide *whether* this is a real, new assignment -
     * that judgment differs by caller (a fresh task's assignee is always "new";
     * an existing task's is only new if it actually changed from before) and
     * belongs where that context already lives. AssignmentNotificationService
     * itself still skips silently when $assigneeBeUserId is the acting editor.
     */
    private function notifyAssignment(int $taskUid, string $taskTitle, string $subjectTable, int $subjectUid, int $assigneeBeUserId): void
    {
        if ($assigneeBeUserId < 1) {
            return;
        }

        $subjectRecord = BackendUtility::getRecord($subjectTable, $subjectUid);
        $subjectTitle = $subjectRecord !== null
            ? BackendUtility::getRecordTitle($subjectTable, $subjectRecord)
            : sprintf('%s:%d', $subjectTable, $subjectUid);
        $pageUid = $subjectTable === 'pages' ? $subjectUid : (int)($subjectRecord['pid'] ?? 0);

        $this->assignmentNotificationService->notifyAssignee(
            $assigneeBeUserId,
            (int)($this->getBackendUser()->user['uid'] ?? 0),
            $taskUid,
            $taskTitle,
            $subjectTitle,
            (string)$this->uriBuilder->buildUriFromRoute('record_edit', [
                'edit' => [$subjectTable => [$subjectUid => 'edit']],
                'returnUrl' => (string)$this->uriBuilder->buildUriFromRoute('web_contentflow', ['id' => $pageUid]),
            ]),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function getBody(ServerRequestInterface $request): array
    {
        $parsed = $request->getParsedBody();
        if (is_array($parsed) && $parsed !== []) {
            return $parsed;
        }

        $raw = (string)$request->getBody();
        if ($raw !== '') {
            try {
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    return $decoded;
                }
            } catch (\Throwable) {
            }
        }

        return is_array($parsed) ? $parsed : [];
    }

    /**
     * Reject the request. Every rejection is logged server-side with its code and
     * context before the (deliberately terser) client response goes out - "for
     * developer logs" means the browser sees a clean message while `var/log`
     * keeps table/uid/be_user detail nobody wants surfaced to the editor.
     */
    private function error(TaskActionError $error): ResponseInterface
    {
        return new JsonResponse(['success' => false] + $this->logAndExposeError($error), 400);
    }

    /**
     * Log the full context for a developer, return only the safe subset (code +
     * message) for the client. Shared between the hard-rejection path (error())
     * and attachAction()'s per-record loop, where a failure does not abort the
     * whole request but must still be both logged and reported per record.
     *
     * @return array{code: string, message: string}
     */
    private function logAndExposeError(TaskActionError $error): array
    {
        $this->logger->notice($error->code, $error->context + [
            'message' => $error->message,
            'beUser' => (int)($this->getBackendUser()->user['uid'] ?? 0),
        ]);

        return ['code' => $error->code, 'message' => $error->message];
    }

    /**
     * Shorthand for the errors this controller raises itself, rather than ones
     * assertMayEdit() already built - same logging, same response shape, so
     * every failure path looks identical from the client's point of view.
     *
     * @param array<string, mixed> $context
     */
    private function reject(string $code, string $message, array $context = []): ResponseInterface
    {
        return $this->error(new TaskActionError($code, $message, $context));
    }

    /**
     * Look up an open (not closed) task, or produce the rejection response.
     *
     * Splits what used to be a single "not found or closed" check into two
     * distinctly-coded, distinctly-worded failures: a missing task usually means
     * a stale link or typo'd id; a closed task exists but is an archive record
     * an editor is trying to act on. A developer reading the log - or an editor
     * reading the message - needs those told apart, not folded into one
     * "not found or closed" sentence that hides which one actually happened.
     *
     * @param string $action named in the closed-task message, e.g. "change its stage"
     * @return array<string, mixed>|ResponseInterface the task row, or the rejection
     */
    private function findOpenTaskOrError(int $taskUid, string $action = 'change it'): array|ResponseInterface
    {
        $task = $this->taskRepository->findByUid($taskUid);
        if ($task === null) {
            return $this->reject('task-not-found', 'This task no longer exists.', ['taskUid' => $taskUid]);
        }
        if ((int)$task['closed'] === 1) {
            return $this->reject(
                'task-closed',
                sprintf('This task is closed - you cannot %s.', $action),
                ['taskUid' => $taskUid],
            );
        }

        return $task;
    }

    private function getBackendUser(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }

    private function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }

    /**
     * The Visual Editor actions' own editor-facing texts, through the same
     * `content_flow.messages` domain the wizard uses (TaskWizardProvider::
     * translate()). Only these actions are covered so far - the rest of this
     * controller still answers in English literals, which is a separate job.
     */
    private function veLabel(string $key): string
    {
        return $this->getLanguageService()->sL('content_flow.messages:' . $key);
    }

    /**
     * The ticket view: everything about one task in one place.
     *
     * Rendered server-side as HTML rather than assembled in JavaScript from the
     * JSON endpoint. Diffs arrive as already-rendered markup from core's
     * DiffUtility, and escaping decisions belong in Fluid, not in string
     * concatenation in the browser.
     */
    public function ticketAction(ServerRequestInterface $request): ResponseInterface
    {
        $taskUid = (int)($request->getQueryParams()['task'] ?? 0);
        $details = $this->workspaceService->getTaskDetails($taskUid);
        if ($details === null) {
            return new HtmlResponse('<div class="callout callout-danger"><div class="callout-body">Task not found.</div></div>', 404);
        }

        $subjectTable = (string)$details['subject']['table'];
        $subjectUid = (int)$details['subject']['uid'];

        $view = $this->viewFactory->create(new ViewFactoryData(
            templateRootPaths: ['EXT:content_flow/Resources/Private/Templates/'],
            partialRootPaths: ['EXT:content_flow/Resources/Private/Partials/'],
            layoutRootPaths: ['EXT:content_flow/Resources/Private/Layouts/'],
            request: $request,
        ));
        $view->assignMultiple($details + [
            'editUrl' => (string)$this->uriBuilder->buildUriFromRoute('record_edit', [
                'edit' => [$subjectTable => [$subjectUid => 'edit']],
                'returnUrl' => (string)$this->uriBuilder->buildUriFromRoute('web_contentflow', ['id' => (int)$details['task']['subject_pid']]),
            ]),
            // Lets Ticket.html tell "this task belongs to another workspace than
            // the one I'm currently in" apart from "not versioned yet" - Preview/
            // Discard/Comment only make sense once those two match.
            'activeWorkspaceUid' => (int)$this->getBackendUser()->workspace,
        ]);

        return new HtmlResponse($view->render('ContentFlow/Ticket'));
    }

    /**
     * Check if there is a pending post-save wizard payload stored in user session.
     */
    public function getPendingWizardAction(): ResponseInterface
    {
        $backendUser = $this->getBackendUser();
        $pending = $backendUser->getSessionData('content_flow_pending_wizard');
        if (is_array($pending)) {
            $backendUser->setAndSaveSessionData('content_flow_pending_wizard', null);
            return new JsonResponse(['success' => true, 'pending' => $pending]);
        }
        return new JsonResponse(['success' => true, 'pending' => null]);
    }

    /**
     * Post a comment on a task.
     *
     * Standalone remarks only - a comment explaining a stage move is written by
     * executeStageAction(), anchored to that transition. Here `activity` stays 0,
     * and the ticket timeline renders it as its own entry.
     */
    public function commentAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->getBody($request);
        $taskUid = (int)($body['task'] ?? 0);
        $content = trim((string)($body['content'] ?? ''));

        if ($content === '') {
            return $this->reject('comment-empty', 'A comment cannot be empty.', ['taskUid' => $taskUid]);
        }

        $task = $this->findOpenTaskOrError($taskUid, 'comment on it');
        if ($task instanceof ResponseInterface) {
            return $task;
        }

        // Commenting is a write on the subject, so it needs the same permission
        // as any other change to it - never merely "is logged in".
        $error = $this->assertMayEdit((string)$task['subject_table'], (int)$task['subject_uid']);
        if ($error !== null) {
            return $this->error($error);
        }

        $this->commentRepository->add(
            $taskUid,
            $content,
            (int)($this->getBackendUser()->user['uid'] ?? 0),
        );

        return new JsonResponse(['success' => true]);
    }

    /**
     * Check or uncheck one review checklist item for one task.
     *
     * Open to anyone who can act on the task, unlike add/remove: filling in a
     * stage's checklist is editorial work done while passing through it, not
     * workspace policy - that distinction is what canManageChecklist() gates
     * instead.
     */
    public function checklistToggleAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->getBody($request);
        $taskUid = (int)($body['task'] ?? 0);
        $itemUid = (int)($body['itemUid'] ?? 0);
        $completed = (bool)($body['completed'] ?? false);

        $task = $this->findOpenTaskOrError($taskUid, 'update its checklist');
        if ($task instanceof ResponseInterface) {
            return $task;
        }
        if ($itemUid < 1) {
            return $this->reject('missing-checklist-item', 'No checklist item was specified.', ['taskUid' => $taskUid]);
        }

        $this->checklistRepository->setCompletion(
            $taskUid,
            $itemUid,
            $completed,
            (int)($this->getBackendUser()->user['uid'] ?? 0),
        );

        return new JsonResponse(['success' => true]);
    }

    /**
     * Add a checklist item to a stage's review policy.
     */
    public function checklistAddAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->getBody($request);
        $workspaceUid = (int)($body['workspaceUid'] ?? 0);
        $stageUid = (int)($body['stageUid'] ?? 0);
        $title = trim((string)($body['title'] ?? ''));

        if (!$this->canManageChecklist($workspaceUid)) {
            return $this->reject(
                'checklist-not-permitted',
                'You are not allowed to manage this workspace\'s checklists.',
                ['workspaceUid' => $workspaceUid],
            );
        }
        if ($title === '') {
            return $this->reject(
                'checklist-title-required',
                'A title is required to add a checklist item.',
                ['workspaceUid' => $workspaceUid, 'stageUid' => $stageUid],
            );
        }

        // Appended, not reordered - an integrator who wants a different order
        // removes and re-adds; there is no drag-to-reorder in the manage modal.
        $sorting = count($this->checklistRepository->findItemsForStage($workspaceUid, $stageUid));
        $itemUid = $this->checklistRepository->addItem($workspaceUid, $stageUid, $title, $sorting);

        return new JsonResponse(['success' => true, 'item' => ['uid' => $itemUid, 'title' => $title]]);
    }

    /**
     * Remove a checklist item from a stage's review policy.
     *
     * Soft-deletes the definition only - existing tx_contentflow_task_checklist_state
     * rows for it are left alone, and simply become unreachable through
     * findItemsForStage()'s join. A task that already checked it off keeps no
     * visible trace, which is correct: the policy no longer asks for it.
     */
    public function checklistRemoveAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->getBody($request);
        $workspaceUid = (int)($body['workspaceUid'] ?? 0);
        $itemUid = (int)($body['itemUid'] ?? 0);

        if (!$this->canManageChecklist($workspaceUid)) {
            return $this->reject(
                'checklist-not-permitted',
                'You are not allowed to manage this workspace\'s checklists.',
                ['workspaceUid' => $workspaceUid],
            );
        }
        if ($itemUid < 1) {
            return $this->reject('missing-checklist-item', 'No checklist item was specified.', ['workspaceUid' => $workspaceUid]);
        }

        $this->checklistRepository->removeItem($itemUid);

        return new JsonResponse(['success' => true]);
    }

    /**
     * Same rule as GbWeb\ContentFlow\Service\BoardColumnRegistry::canManageChecklist()
     * - configuring a stage's checklist is workspace policy, not editorial
     * work, so it is restricted the same way publishing is: workspace owner or
     * admin. Kept as its own three lines rather than shared with that class,
     * which builds board columns and has no reason to depend on the ajax
     * controller or vice versa.
     */
    private function canManageChecklist(int $workspaceUid): bool
    {
        $backendUser = $this->getBackendUser();
        if ($backendUser->isAdmin()) {
            return true;
        }
        if ($workspaceUid < 1) {
            return false;
        }
        $access = $backendUser->checkWorkspace($workspaceUid);
        return is_array($access) && ($access['_ACCESS'] ?? '') === 'owner';
    }
}
