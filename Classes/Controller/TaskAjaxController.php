<?php

declare(strict_types=1);

namespace GbWeb\ContentFlow\Controller;

use GbWeb\ContentFlow\Domain\Model\TaskPriority;
use GbWeb\ContentFlow\Domain\Model\TaskState;
use GbWeb\ContentFlow\Domain\Repository\CommentRepository;
use GbWeb\ContentFlow\Domain\Repository\TaskRepository;
use GbWeb\ContentFlow\Service\ActivityLogger;
use GbWeb\ContentFlow\Service\ReferenceInspector;
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
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\View\ViewFactoryData;
use TYPO3\CMS\Core\View\ViewFactoryInterface;

/**
 * Write and detail endpoints for the board and workspace popups.
 */
final class TaskAjaxController
{
    public function __construct(
        private readonly TaskRepository $taskRepository,
        private readonly CommentRepository $commentRepository,
        private readonly TaskSubjectRegistry $subjectRegistry,
        private readonly TaskMemberSynchronizer $memberSynchronizer,
        private readonly ReferenceInspector $referenceInspector,
        private readonly ActivityLogger $activityLogger,
        private readonly WorkspaceIntegrationService $workspaceService,
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

        $task = $this->taskRepository->findOrCreateOpenForSubject($table, $uid, [
            'title' => $title !== '' ? $title : $this->deriveTitle($table, $uid),
            'description' => $description,
            'subject_pid' => $table === 'pages' ? $uid : (int)(BackendUtility::getRecord($table, $uid, 'pid')['pid'] ?? 0),
            'state' => TaskState::BACKLOG->value,
            'priority' => $priority->value,
            'assignee' => $assignee,
            // Planned by a human, so no auto_created flag and no wizard nagging.
            'auto_created' => 0,
        ]);
        $taskUid = (int)$task['uid'];

        $claimed = 0;
        if ($table === 'pages') {
            $claimed = $this->memberSynchronizer->syncPageMembers($taskUid, $uid);
        }

        return new JsonResponse([
            'success' => true,
            'task' => $taskUid,
            'claimed' => $claimed,
        ]);
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

        $error = $this->assertMayEdit((string)$task['subject_table'], (int)$task['subject_uid']);
        if ($error !== null) {
            return $this->error($error);
        }

        // A drop onto a core stage column is a workspace stage transition and must
        // go through core - permissions, sys_history and stage notifications all
        // live there. Only Content Flow's own columns (Backlog / Planned), which
        // exist precisely because core has no state for "not versioned yet", are
        // written directly.
        $state = TaskState::tryFrom($targetState);
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

        $refusal = $this->askCoreToSetStage($versionsByTable, $targetStageUid, $comment, $recipients);
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

        $this->recordStageChange($task, $targetStageUid, $comment, $recipients, $versionsByTable);

        return new JsonResponse(['success' => true, 'stageUid' => $targetStageUid]);
    }

    /**
     * Hand the move to TYPO3 and report back whether it was refused.
     *
     * EXT:workspaces' version_setStage() is what checks
     * workspaceCannotEditOfflineVersion(), hasPermissionToUpdate() and
     * workspaceCheckStageForCurrent(), writes t3ver_stage, records the transition
     * in sys_history and queues the stage notification mails. Writing our own table
     * directly - which this action used to do - skipped every one of those.
     *
     * @param array<string, list<int>> $versionsByTable
     * @param list<mixed> $recipients
     * @return string|null the refusal reason, or null when core accepted
     */
    private function askCoreToSetStage(array $versionsByTable, int $stageUid, string $comment, array $recipients): ?string
    {
        $cmd = [];
        foreach ($versionsByTable as $table => $versionUids) {
            foreach ($versionUids as $versionUid) {
                $cmd[$table][$versionUid]['version'] = [
                    'action' => 'setStage',
                    'stageId' => $stageUid,
                    'comment' => $comment,
                    'notificationAlternativeRecipients' => $recipients,
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
     * Mirror what core just decided.
     *
     * The task row is a read cache for the board; the activity entry is the durable
     * record, because sys_history - where core wrote the same transition - is
     * garbage-collected after 30 days.
     *
     * @param array<string, mixed> $task
     * @param list<mixed> $recipients
     * @param array<string, list<int>> $versionsByTable
     */
    private function recordStageChange(
        array $task,
        int $targetStageUid,
        string $comment,
        array $recipients,
        array $versionsByTable,
    ): void {
        $taskUid = (int)$task['uid'];
        $beUserId = (int)($this->getBackendUser()->user['uid'] ?? 0);
        $targetState = TaskState::fromStageId($targetStageUid);

        $this->taskRepository->moveToColumn($taskUid, $targetState->value, $targetStageUid);

        $activityUid = $this->activityLogger->log($taskUid, ActivityLogger::EVENT_STAGE_CHANGED, $beUserId, [
            'from_state' => $task['state'],
            'from_stage' => (int)$task['stage_uid'],
            'to_state' => $targetState->value,
            'to_stage' => $targetStageUid,
            'recipients' => $recipients,
        ], $this->findLatestStageHistoryUid($versionsByTable));

        if ($comment !== '') {
            $this->commentRepository->add($taskUid, $comment, $beUserId, $activityUid);
        }
    }

    /**
     * The sys_history row core just wrote for this transition, so the activity
     * entry can point at core's full detail for as long as it exists.
     *
     * @param array<string, list<int>> $versionsByTable
     */
    private function findLatestStageHistoryUid(array $versionsByTable): int
    {
        foreach ($versionsByTable as $table => $versionUids) {
            foreach ($versionUids as $versionUid) {
                $changes = $this->activityLogger->findStageChanges($table, $versionUid);
                if ($changes !== []) {
                    return (int)$changes[0]['uid'];
                }
            }
        }

        return 0;
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
     * 'open' means deliberately unassigned so someone can take the task later;
     * everything else collapses to the current editor because the wizard does not
     * offer arbitrary user picking yet.
     */
    private function resolveRequestedAssignee(mixed $rawAssignee): int
    {
        return (string)$rawAssignee === 'open'
            ? 0
            : (int)($this->getBackendUser()->user['uid'] ?? 0);
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
     * Submit choice from the Post-Save Task Routing Wizard.
     */
    public function wizardSubmitAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->getBody($request);
        $actionType = (string)($body['actionType'] ?? 'configure_auto_task');
        $table = (string)($body['table'] ?? '');
        $uid = (int)($body['uid'] ?? 0);

        $error = $this->assertMayEdit($table, $uid);
        if ($error !== null) {
            return $this->error($error);
        }

        $title = trim((string)($body['title'] ?? ''));
        $description = trim((string)($body['description'] ?? ''));
        $assignee = $this->resolveRequestedAssignee($body['assignee'] ?? 'me');
        if ($actionType === 'attach_to_page_task') {
            $pageTaskUid = (int)($body['pageTaskUid'] ?? 0);
            if ($pageTaskUid < 1) {
                return $this->reject(
                    'missing-page-task-id',
                    'No page task was specified to attach this element to.',
                    ['table' => $table, 'uid' => $uid],
                );
            }
            $this->taskRepository->moveMemberToTask($table, $uid, $pageTaskUid);
            return new JsonResponse(['success' => true, 'action' => 'attached']);
        }

        if ($actionType === 'configure_auto_task') {
            if ($title === '') {
                return $this->reject(
                    'task-title-required',
                    'A title is required to keep this task.',
                    ['table' => $table, 'uid' => $uid],
                );
            }
            $taskUid = (int)($body['taskUid'] ?? 0);
            $task = $this->findOpenTaskOrError($taskUid, 'update it');
            if ($task instanceof ResponseInterface) {
                return $task;
            }

            $this->taskRepository->updateDetails($taskUid, $title, $description, $assignee);

            return new JsonResponse(['success' => true, 'task' => $taskUid, 'action' => 'configured']);
        }

        if ($actionType !== 'create_new_task') {
            return $this->reject(
                'unknown-wizard-action',
                sprintf('The wizard action "%s" is not supported.', $actionType),
                ['actionType' => $actionType, 'table' => $table, 'uid' => $uid],
            );
        }

        if ($title === '') {
            return $this->reject(
                'task-title-required',
                'A title is required to create a new task.',
                ['table' => $table, 'uid' => $uid],
            );
        }

        $stageChoice = (string)($body['stageChoice'] ?? 'in_progress');
        $beUserId = (int)($this->getBackendUser()->user['uid'] ?? 0);
        $workspaceUid = (int)($this->getBackendUser()->workspace);

        $targetState = $stageChoice === 'review' ? TaskState::REVIEW->value : TaskState::IN_PROGRESS->value;

        $task = $this->taskRepository->detachIntoOwnTask($table, $uid, [
            'title' => $title,
            'description' => $description,
            'subject_pid' => $this->derivePid($table, $uid),
            'state' => $targetState,
            'workspace_uid' => $workspaceUid,
            'assignee' => $assignee,
            'auto_created' => 0,
        ]);

        $this->activityLogger->log((int)$task['uid'], ActivityLogger::EVENT_TASK_CREATED, $beUserId, [
            'subjectTable' => $table,
            'subjectUid' => $uid,
            'stageChoice' => $stageChoice,
            'wizard' => true,
        ]);

        return new JsonResponse(['success' => true, 'task' => (int)$task['uid'], 'action' => 'created']);
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
}
