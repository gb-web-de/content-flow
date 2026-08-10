<?php

declare(strict_types=1);

namespace GbWeb\ContentFlow\Service;

use GbWeb\ContentFlow\Domain\Repository\TaskRepository;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * "This page's edits go to this task" - the declaration an editor makes in the
 * Visual Editor's task select before typing anything, kept in the backend
 * user's session so every later save can honour it.
 *
 * It lives in a service of its own because two sides need the same answer and
 * must not disagree about it: TaskAjaxController writes it and reads it back to
 * pre-select the choice, and TaskAutoCreationService reads it on every captured
 * edit, on any surface - Visual Editor, Layout, Records. The resolution used to
 * exist only inside TaskAutoCreationService, which left the select with no way
 * to show what the server was already doing.
 *
 * Resolution is deliberately strict: a choice only counts for the page it was
 * made on, and only while that task is still open. A task that was published or
 * closed in the meantime silently stops routing anything, rather than sending
 * edits onto a finished piece of work.
 */
final readonly class ActiveTaskSession
{
    /**
     * Session key, single-sourced here: a second literal elsewhere would be a
     * silent no-op rather than an error.
     */
    private const SESSION_KEY = 'content_flow_active_task';

    public function __construct(
        private TaskRepository $taskRepository,
    ) {
    }

    public function remember(BackendUserAuthentication $backendUser, int $pageUid, int $taskUid): void
    {
        $backendUser->setAndSaveSessionData(self::SESSION_KEY, [
            'pageUid' => $pageUid,
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

    public function resolve(BackendUserAuthentication $backendUser, int $pageUid): ?int
    {
        $active = $backendUser->getSessionData(self::SESSION_KEY);
        if (!is_array($active) || (int)($active['pageUid'] ?? 0) !== $pageUid) {
            return null;
        }
        $taskUid = (int)($active['taskUid'] ?? 0);
        if ($taskUid < 1) {
            return null;
        }
        $task = $this->taskRepository->findByUid($taskUid);
        if ($task === null || (int)$task['closed'] === 1) {
            return null;
        }

        return $taskUid;
    }
}
