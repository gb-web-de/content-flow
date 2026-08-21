<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Service;

use GbWeb\EditorialFlow\Domain\Model\TaskState;
use GbWeb\EditorialFlow\Domain\Repository\TaskRepository;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * "This context's edits go to this task" - the declaration an editor makes
 * before typing anything, kept in the backend user's session so every later
 * save can honour it.
 *
 * It lives in a service of its own because two sides need the same answer and
 * must not disagree about it: TaskAjaxController writes it and reads it back to
 * pre-select the choice, and TaskAutoCreationService reads it on every captured
 * edit, on any surface - Visual Editor, Layout, Records. The resolution used to
 * exist only inside TaskAutoCreationService, which left the select with no way
 * to show what the server was already doing.
 *
 * Page contexts cover every editable record on that page. Record contexts cover
 * exactly one record, so starting a dedicated content-element task cannot hijack
 * later saves of its siblings. A task that was published or closed silently
 * stops routing anything.
 */
final readonly class ActiveTaskSession
{
    /**
     * Session key, single-sourced here: a second literal elsewhere would be a
     * silent no-op rather than an error.
     */
    private const SESSION_KEY = 'editorial_flow_active_task';

    public function __construct(
        private TaskRepository $taskRepository,
    ) {
    }

    public function remember(BackendUserAuthentication $backendUser, int $pageUid, int $taskUid): void
    {
        $this->rememberForContext($backendUser, 'pages', $pageUid, $taskUid);
    }

    public function rememberForContext(
        BackendUserAuthentication $backendUser,
        string $table,
        int $uid,
        int $taskUid,
    ): void {
        if ($table === '' || $uid < 1 || $taskUid < 1) {
            throw new \InvalidArgumentException('An active task requires a record context and task uid.');
        }

        $backendUser->setAndSaveSessionData(self::SESSION_KEY, [
            'table' => $table,
            'uid' => $uid,
            'taskUid' => $taskUid,
        ]);
    }

    /**
     * Drop the declaration entirely - "no task", the editor's way back out of a
     * choice that would otherwise keep routing every future edit on this page.
     */
    public function forget(BackendUserAuthentication $backendUser): void
    {
        $backendUser->setAndSaveSessionData(self::SESSION_KEY, null);
    }

    public function forgetIfTask(BackendUserAuthentication $backendUser, int $taskUid): bool
    {
        $active = $this->current($backendUser);
        if ($active === null || $active['taskUid'] !== $taskUid) {
            return false;
        }

        $this->forget($backendUser);
        return true;
    }

    public function resolve(BackendUserAuthentication $backendUser, int $pageUid): ?int
    {
        $active = $this->current($backendUser);
        if ($active === null || $active['table'] !== 'pages' || $active['uid'] !== $pageUid) {
            return null;
        }

        return $active['taskUid'];
    }

    public function resolveForEdit(
        BackendUserAuthentication $backendUser,
        string $table,
        int $recordUid,
        int $pageUid,
    ): ?int {
        $active = $this->current($backendUser);
        if ($active === null) {
            return null;
        }

        if ($active['table'] === 'pages' && $active['uid'] === $pageUid) {
            return $active['taskUid'];
        }
        if ($active['table'] === $table && $active['uid'] === $recordUid) {
            return $active['taskUid'];
        }

        return null;
    }

    /**
     * @return array{table: string, uid: int, taskUid: int}|null
     */
    public function current(BackendUserAuthentication $backendUser): ?array
    {
        $active = $backendUser->getSessionData(self::SESSION_KEY);
        if (!is_array($active)) {
            return null;
        }

        // Sessions written before record-scoped activation used pageUid only.
        $table = (string)($active['table'] ?? 'pages');
        $uid = (int)($active['uid'] ?? $active['pageUid'] ?? 0);
        $taskUid = (int)($active['taskUid'] ?? 0);
        if ($table === '' || $uid < 1 || $taskUid < 1) {
            $this->forget($backendUser);
            return null;
        }

        $task = $this->taskRepository->findByUid($taskUid);
        if (
            $task === null
            || (int)$task['closed'] === 1
            || (string)$task['state'] === TaskState::DONE->value
            || (int)$task['workspace_uid'] !== (int)$backendUser->workspace
        ) {
            $this->forget($backendUser);
            return null;
        }

        return ['table' => $table, 'uid' => $uid, 'taskUid' => $taskUid];
    }
}
