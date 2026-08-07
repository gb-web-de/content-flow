<?php

declare(strict_types=1);

namespace GbWeb\ContentFlow\Controller;

use GbWeb\ContentFlow\Domain\Model\TaskState;
use GbWeb\ContentFlow\Domain\Repository\TaskRepository;
use GbWeb\ContentFlow\Service\ReferenceInspector;
use GbWeb\ContentFlow\Service\TaskMemberSynchronizer;
use GbWeb\ContentFlow\Service\TaskSubjectRegistry;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * Write endpoints for the board.
 *
 * Every action re-derives what it is allowed to touch from the server side. The
 * client says *what* it wants, never *whether it may*:
 *
 * - the workspace comes from the backend user, never from the request. A
 *   client-supplied workspace id was an actual IDOR in the reviewed
 *   kanban-workspaces AssignAjaxController.
 * - the table must be trackable (workspace-aware, per TaskSubjectRegistry).
 * - edit permission on the concrete record is checked before anything is written.
 *
 * CSRF is handled by the backend routing layer, which requires a valid request
 * token for every ajax route.
 */
final class TaskAjaxController
{
    public function __construct(
        private readonly TaskRepository $taskRepository,
        private readonly TaskSubjectRegistry $subjectRegistry,
        private readonly TaskMemberSynchronizer $memberSynchronizer,
        private readonly ReferenceInspector $referenceInspector,
    ) {
    }

    /**
     * Create a task for a page (or any page-like record) picked in the wizard.
     *
     * This is the "+" button's endpoint: the page is chosen with core's page
     * browser, so search and tree navigation come for free.
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
        if (!$this->subjectRegistry->isSubject($table)) {
            return $this->error(sprintf('"%s" cannot carry a task of its own.', $table));
        }

        $task = $this->taskRepository->findOrCreateOpenForSubject($table, $uid, [
            'title' => $this->deriveTitle($table, $uid),
            'subject_pid' => $table === 'pages' ? $uid : (int)(BackendUtility::getRecord($table, $uid, 'pid')['pid'] ?? 0),
            'state' => TaskState::BACKLOG->value,
            'assignee' => (int)($this->getBackendUser()->user['uid'] ?? 0),
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

        $task = $this->taskRepository->findByUid($taskUid);
        if ($task === null || (int)$task['closed'] === 1) {
            return $this->error('Task not found or already closed.');
        }

        $moved = [];
        $refused = [];
        foreach ($records as $record) {
            $table = (string)($record['table'] ?? '');
            $uid = (int)($record['uid'] ?? 0);

            $error = $this->assertMayEdit($table, $uid);
            if ($error !== null) {
                $refused[] = ['table' => $table, 'uid' => $uid, 'reason' => $error];
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
     * The escape hatch from page aggregation - one banner really being its own piece
     * of work. Only records that can carry a task on their own can be split off;
     * everything else would produce a card the board cannot route edits back to.
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
            return $this->error('This record does not belong to an open task.');
        }
        if ((string)$current['subject_table'] === $table && (int)$current['subject_uid'] === $uid) {
            return $this->error('A task cannot be split from itself.');
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
     * May the current user edit this record at all?
     *
     * @return string|null error message, or null when allowed
     */
    private function assertMayEdit(string $table, int $uid): ?string
    {
        if ($uid < 1) {
            return 'Missing record uid.';
        }
        if (!$this->subjectRegistry->isTrackable($table)) {
            return sprintf('Table "%s" is not versionable and cannot be tracked.', $table);
        }

        $record = BackendUtility::getRecord($table, $uid);
        if ($record === null) {
            return sprintf('Record %s:%d does not exist.', $table, $uid);
        }

        $backendUser = $this->getBackendUser();
        if ($table === 'pages') {
            if (!$backendUser->doesUserHaveAccess($record, Permission::PAGE_EDIT)) {
                return sprintf('No edit permission on page %d.', $uid);
            }
        } else {
            $page = BackendUtility::getRecord('pages', (int)($record['pid'] ?? 0));
            if ($page === null || !$backendUser->doesUserHaveAccess($page, Permission::CONTENT_EDIT)) {
                return sprintf('No edit permission on the page holding %s:%d.', $table, $uid);
            }
        }
        if (!$backendUser->recordEditAccessInternals($table, $record)) {
            return sprintf('Editing %s:%d is not allowed.', $table, $uid);
        }
        // The workspace is always the user's own - never taken from the request.
        if (!$backendUser->workspaceAllowsLiveEditingInTable($table) && $backendUser->workspace === 0) {
            return sprintf('Table "%s" cannot be edited in the Live workspace.', $table);
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
     * @return array<string, mixed>
     */
    private function getBody(ServerRequestInterface $request): array
    {
        $parsed = $request->getParsedBody();

        return is_array($parsed) ? $parsed : [];
    }

    private function error(string $message): ResponseInterface
    {
        return new JsonResponse(['success' => false, 'message' => $message], 400);
    }

    private function getBackendUser(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }
}
