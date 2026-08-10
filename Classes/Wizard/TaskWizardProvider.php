<?php

declare(strict_types=1);

namespace GbWeb\ContentFlow\Wizard;

use GbWeb\ContentFlow\Domain\Model\TaskPriority;
use GbWeb\ContentFlow\Domain\Model\TaskState;
use GbWeb\ContentFlow\Domain\Repository\CommentRepository;
use GbWeb\ContentFlow\Domain\Repository\TaskRepository;
use GbWeb\ContentFlow\Notification\AssignmentNotificationService;
use GbWeb\ContentFlow\Service\ActivityLogger;
use GbWeb\ContentFlow\Service\TaskSubjectRegistry;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Backend\Wizard\DTO\Configuration;
use TYPO3\CMS\Backend\Wizard\DTO\Finisher;
use TYPO3\CMS\Backend\Wizard\DTO\Step;
use TYPO3\CMS\Backend\Wizard\DTO\SubmissionResult;
use TYPO3\CMS\Backend\Wizard\WizardProviderInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * Drives the post-save routing/configure wizard and the "+" flow's task wizard
 * through TYPO3 v14's native WizardProviderInterface framework
 * (@typo3/backend/wizard/wizard.js) instead of a hand-built Modal.advanced()
 * form. See PageWizardProvider (typo3/cms-backend) for the reference this is
 * modelled on - same DI-tagged provider pattern, same generic
 * wizard_config/wizard_submit AJAX routes, just a different `mode` identifier.
 *
 * `pending.mode` values (most set by TaskAutoCreationService::storePendingWizard(),
 * 'create_from_picker' and 'create_pending_page' constructed client-side in
 * create-wizard.js, 'regression_comment' by TaskAutoCreationService::
 * maybeRegressPastEditing()):
 *  - configure_auto_task: a single task-details step.
 *  - route_member: a destination choice step first: dynamically followed by
 *    task-details + stage-choice steps (create_new_task) or no further steps at
 *    all (attach_to_page_task), loaded on the wizard-before-next-step event -
 *    see wizard/task-wizard.js.
 *  - create_from_picker: task-details step with priority/date fields shown too.
 *  - create_pending_page: "Neue Seite erstellen" - same task-details step (with
 *    priority/dates), but persists a task with no real subject yet (subject_uid
 *    0) instead of one tied to an existing record. See TaskRepository::
 *    createPendingPageTask() and TaskAjaxController::materializePendingPage()
 *    for how the page itself gets created later.
 *  - regression_comment: a single comment-textarea step, letting the editor
 *    refine the auto-generated "reopened for editing" comment B5's regression
 *    already wrote - the transition itself already happened.
 */
#[AsTaggedItem(index: 'contentflow_task_wizard')]
final readonly class TaskWizardProvider implements WizardProviderInterface
{
    public function __construct(
        private TaskRepository $taskRepository,
        private TaskSubjectRegistry $subjectRegistry,
        private ActivityLogger $activityLogger,
        private AssignmentNotificationService $assignmentNotificationService,
        private CommentRepository $commentRepository,
        private UriBuilder $uriBuilder,
        private LoggerInterface $logger,
    ) {
    }

    public function getConfiguration(ServerRequestInterface $serverRequest): Configuration
    {
        $data = $serverRequest->getQueryParams()['data'] ?? [];
        $pending = is_array($data['pending'] ?? null) ? $data['pending'] : [];
        $mode = (string)($pending['mode'] ?? '');

        if ($mode === 'configure_auto_task') {
            return Configuration::create([
                $this->taskDetailsStep(
                    (string)($pending['defaultTitle'] ?? $pending['subjectTitle'] ?? $pending['editedTitle'] ?? ''),
                ),
            ]);
        }

        if ($mode === 'create_from_picker' || $mode === 'create_pending_page') {
            return Configuration::create([
                $this->taskDetailsStep((string)($pending['recordTitle'] ?? ''), showExtraFields: true),
            ]);
        }

        if ($mode === 'regression_comment') {
            return Configuration::create([
                Step::create('@gb-web/content-flow/wizard/steps/comment-step.js')
                    ->withConfigurationData(['defaultComment' => (string)($pending['defaultComment'] ?? '')]),
            ]);
        }

        if ($mode === 'route_member') {
            $destination = $data['destination'] ?? null;

            if ($destination === null) {
                return Configuration::create([
                    Step::create('@gb-web/content-flow/wizard/steps/route-choice-step.js')
                        ->withConfigurationData([
                            'pageTaskTitle' => (string)($pending['pageTaskTitle'] ?? 'Untitled task'),
                        ]),
                ]);
            }

            if ($destination === 'create_new_task') {
                return Configuration::create([
                    $this->taskDetailsStep((string)($pending['defaultTitle'] ?? $pending['recordTitle'] ?? '')),
                    Step::create('@gb-web/content-flow/wizard/steps/stage-choice-step.js'),
                ]);
            }

            // attach_to_page_task: nothing further to collect.
            return Configuration::create([]);
        }

        return Configuration::create([
            Step::create('@gb-web/content-flow/wizard/steps/error-step.js')
                ->withConfigurationData(['message' => 'Invalid wizard submission!']),
        ]);
    }

    public function handleSubmit(ServerRequestInterface $serverRequest): SubmissionResult
    {
        $body = $serverRequest->getParsedBody();
        $body = is_array($body) ? $body : [];
        $mode = (string)($body['mode'] ?? '');

        return match ($mode) {
            'configure_auto_task' => $this->submitConfigureAutoTask($body),
            'route_member' => $this->submitRouteMember($body),
            'create_from_picker' => $this->submitCreateFromPicker($body),
            'create_pending_page' => $this->submitCreatePendingPage($body),
            'regression_comment' => $this->submitUpdateRegressionComment($body),
            default => SubmissionResult::createErrorResult(['Unknown wizard mode.']),
        };
    }

    private function taskDetailsStep(string $defaultTitle, bool $showExtraFields = false): Step
    {
        return Step::create('@gb-web/content-flow/wizard/steps/task-details-step.js')
            ->withConfigurationData([
                'defaultTitle' => $defaultTitle,
                'showExtraFields' => $showExtraFields,
            ]);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function submitConfigureAutoTask(array $body): SubmissionResult
    {
        $table = (string)($body['table'] ?? '');
        $uid = (int)($body['uid'] ?? 0);
        $error = $this->assertMayEdit($table, $uid);
        if ($error !== null) {
            return $this->reject($error);
        }

        $title = trim((string)($body['title'] ?? ''));
        if ($title === '') {
            return $this->reject('A title is required to keep this task.', ['table' => $table, 'uid' => $uid]);
        }

        $taskUid = (int)($body['taskUid'] ?? 0);
        $task = $this->taskRepository->findByUid($taskUid);
        if ($task === null) {
            return $this->reject('This task no longer exists.', ['taskUid' => $taskUid]);
        }
        if ((int)$task['closed'] === 1) {
            return $this->reject('This task is closed - you cannot update it.', ['taskUid' => $taskUid]);
        }

        $description = trim((string)($body['description'] ?? ''));
        $assignee = $this->resolveRequestedAssignee($body['assignee'] ?? 'me');
        $previousAssignee = (int)$task['assignee'];

        $this->taskRepository->updateDetails($taskUid, $title, $description, $assignee);

        if ($assignee !== $previousAssignee) {
            $this->notifyAssignment($taskUid, $title, (string)$task['subject_table'], (int)$task['subject_uid'], $assignee);
        }

        return $this->success('Task details saved.', $taskUid);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function submitRouteMember(array $body): SubmissionResult
    {
        $table = (string)($body['table'] ?? '');
        $uid = (int)($body['uid'] ?? 0);
        $error = $this->assertMayEdit($table, $uid);
        if ($error !== null) {
            return $this->reject($error);
        }

        $destination = (string)($body['destination'] ?? '');

        if ($destination === 'attach_to_page_task') {
            $pageTaskUid = (int)($body['pageTaskUid'] ?? 0);
            if ($pageTaskUid < 1) {
                return $this->reject('No page task was specified to attach this element to.', ['table' => $table, 'uid' => $uid]);
            }
            $this->taskRepository->moveMemberToTask($table, $uid, $pageTaskUid);

            return $this->success('Edit added to the existing task.', $pageTaskUid);
        }

        if ($destination !== 'create_new_task') {
            return $this->reject(sprintf('The wizard destination "%s" is not supported.', $destination), ['table' => $table, 'uid' => $uid]);
        }

        $title = trim((string)($body['title'] ?? ''));
        if ($title === '') {
            return $this->reject('A title is required to create a new task.', ['table' => $table, 'uid' => $uid]);
        }

        if ($this->taskRepository->findOpenTaskByMember($table, $uid) === null) {
            return $this->reject(
                'This record does not belong to an open task, so there is nothing to split into a new one.',
                ['table' => $table, 'uid' => $uid],
            );
        }

        $description = trim((string)($body['description'] ?? ''));
        $assignee = $this->resolveRequestedAssignee($body['assignee'] ?? 'me');
        $stageChoice = (string)($body['stageChoice'] ?? 'in_progress');
        $beUserId = (int)($this->getBackendUser()->user['uid'] ?? 0);
        $workspaceUid = (int)$this->getBackendUser()->workspace;
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
        $this->notifyAssignment((int)$task['uid'], $title, $table, $uid, $assignee);

        return $this->success('A separate task was created.', (int)$task['uid']);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function submitCreateFromPicker(array $body): SubmissionResult
    {
        $table = (string)($body['table'] ?? 'pages');
        $uid = (int)($body['uid'] ?? 0);
        $error = $this->assertMayEdit($table, $uid);
        if ($error !== null) {
            return $this->reject($error);
        }

        $title = trim((string)($body['title'] ?? ''));
        if ($title === '') {
            return $this->reject('A title is required to create the task.', ['table' => $table, 'uid' => $uid]);
        }

        $description = trim((string)($body['description'] ?? ''));
        $priority = TaskPriority::fromRequest($body['priority'] ?? null);
        $assignee = $this->resolveRequestedAssignee($body['assignee'] ?? 'me');
        $startDate = $this->parseDate($body['startDate'] ?? null);
        $dueDate = $this->parseDate($body['dueDate'] ?? null);

        $task = $this->taskRepository->findOrCreateOpenForSubject($table, $uid, [
            'title' => $title,
            'description' => $description,
            'subject_pid' => $this->derivePid($table, $uid),
            'state' => $startDate > 0 ? TaskState::PLANNED->value : TaskState::BACKLOG->value,
            'priority' => $priority->value,
            'assignee' => $assignee,
            'start_date' => $startDate,
            'due_date' => $dueDate,
            'auto_created' => 0,
        ]);
        $taskUid = (int)$task['uid'];

        if ((int)$task['assignee'] === $assignee) {
            $this->notifyAssignment($taskUid, (string)$task['title'], $table, $uid, $assignee);
        }

        return $this->success('Task created.', $taskUid);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function submitCreatePendingPage(array $body): SubmissionResult
    {
        $parentPid = (int)($body['parentPid'] ?? 0);
        if ($parentPid < 1) {
            return $this->reject('No parent page was specified to create the new page under.');
        }
        if (!$this->mayCreatePageUnder($parentPid)) {
            return $this->reject('You are not allowed to create a new page here.', ['parentPid' => $parentPid]);
        }

        $title = trim((string)($body['title'] ?? ''));
        if ($title === '') {
            return $this->reject('A title is required.');
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

        return $this->success('Task created.', $taskUid);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function submitUpdateRegressionComment(array $body): SubmissionResult
    {
        $taskUid = (int)($body['taskUid'] ?? 0);
        $commentUid = (int)($body['commentUid'] ?? 0);
        $content = trim((string)($body['content'] ?? ''));

        $task = $this->taskRepository->findByUid($taskUid);
        if ($task === null) {
            return $this->reject('This task no longer exists.', ['taskUid' => $taskUid]);
        }
        if ((int)$task['closed'] === 1) {
            return $this->reject('This task is closed - you cannot update its comment.', ['taskUid' => $taskUid]);
        }
        if ($commentUid < 1) {
            return $this->reject('No comment was specified to update.', ['taskUid' => $taskUid]);
        }
        if ($content === '') {
            return $this->reject('A comment cannot be empty.', ['taskUid' => $taskUid]);
        }

        $this->commentRepository->updateContent($commentUid, $taskUid, $content);

        return $this->success('Comment updated.', $taskUid);
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

    private function success(string $message, int $taskUid): SubmissionResult
    {
        return SubmissionResult::createSuccessResult(
            Finisher::createCustomFinisher(
                'reload',
                '@typo3/backend/wizard/finisher/reload-finisher.js',
                'Content Flow',
                $message,
                ['task' => $taskUid],
            ),
        );
    }

    /**
     * @param array<string, mixed> $context
     */
    private function reject(string $message, array $context = []): SubmissionResult
    {
        $this->logger->notice($message, $context + ['beUser' => (int)($this->getBackendUser()->user['uid'] ?? 0)]);

        return SubmissionResult::createErrorResult([$message]);
    }

    /**
     * Mirrors TaskAjaxController::assertMayEdit() - see its docblock for why
     * checkRecordEditAccess() is used over the deprecated recordEditAccessInternals().
     */
    private function assertMayEdit(string $table, int $uid): ?string
    {
        if ($uid < 1) {
            return 'No record was specified.';
        }
        if (!$this->subjectRegistry->isTrackable($table)) {
            return sprintf('"%s" records cannot be tracked here - they support no workspace versioning.', $table);
        }

        $record = BackendUtility::getRecord($table, $uid);
        if ($record === null) {
            return sprintf('%s:%d no longer exists.', $table, $uid);
        }

        $backendUser = $this->getBackendUser();
        if ($table === 'pages') {
            if (!$backendUser->doesUserHaveAccess($record, Permission::PAGE_EDIT)) {
                return 'You do not have edit permission on this page.';
            }
        } else {
            $page = BackendUtility::getRecord('pages', (int)($record['pid'] ?? 0));
            if ($page === null || !$backendUser->doesUserHaveAccess($page, Permission::CONTENT_EDIT)) {
                return 'You do not have edit permission on the page this record is on.';
            }
        }

        $accessResult = $backendUser->checkRecordEditAccess($table, $record);
        if (!$accessResult->isAllowed) {
            return $accessResult->errorMessage !== ''
                ? $accessResult->errorMessage
                : sprintf('%s:%d cannot be edited right now.', $table, $uid);
        }

        if (!$backendUser->workspaceAllowsLiveEditingInTable($table) && $backendUser->workspace === 0) {
            return sprintf('"%s" records cannot be edited directly on the Live workspace.', $table);
        }

        return null;
    }

    /**
     * See TaskAjaxController::resolveRequestedAssignee() for the same contract.
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

    private function derivePid(string $table, int $uid): int
    {
        if ($table === 'pages') {
            return $uid;
        }

        return (int)(BackendUtility::getRecord($table, $uid, 'pid')['pid'] ?? 0);
    }

    private function parseDate(mixed $rawDate): int
    {
        $value = trim((string)($rawDate ?? ''));
        if ($value === '') {
            return 0;
        }
        $timestamp = strtotime($value . ' 00:00:00');

        return $timestamp !== false ? $timestamp : 0;
    }

    private function getBackendUser(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }
}
