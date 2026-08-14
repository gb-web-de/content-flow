<?php

declare(strict_types=1);

namespace GbWeb\ContentFlow\Service;

use GbWeb\ContentFlow\Domain\Repository\TaskRepository;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Remembers which pending task should adopt the next record FormEngine creates.
 */
final readonly class PendingSubjectHandoff
{
    private const SESSION_KEY = 'content_flow_pending_subject_handoff';
    private const LIFETIME_SECONDS = 600;

    public function __construct(
        private TaskRepository $taskRepository,
    ) {
    }

    public function remember(
        BackendUserAuthentication $backendUser,
        int $taskUid,
        string $subjectTable,
        int $targetPageUid,
    ): void {
        $backendUser->setAndSaveSessionData(self::SESSION_KEY, [
            'taskUid' => $taskUid,
            'subjectTable' => $subjectTable,
            'targetPageUid' => $targetPageUid,
            'workspaceUid' => (int)$backendUser->workspace,
            'expiresAt' => time() + self::LIFETIME_SECONDS,
        ]);
    }

    public function forget(BackendUserAuthentication $backendUser, ?int $taskUid = null): void
    {
        if ($taskUid !== null) {
            $handoff = $backendUser->getSessionData(self::SESSION_KEY);
            if (is_array($handoff) && (int)($handoff['taskUid'] ?? 0) !== $taskUid) {
                return;
            }
        }
        $backendUser->setAndSaveSessionData(self::SESSION_KEY, null);
    }

    /**
     * @return array{taskUid: int, subjectTable: string, targetPageUid: int}|null
     */
    public function resolve(BackendUserAuthentication $backendUser): ?array
    {
        $handoff = $backendUser->getSessionData(self::SESSION_KEY);
        if (!is_array($handoff) || (int)($handoff['expiresAt'] ?? 0) < time()) {
            return null;
        }

        $taskUid = (int)($handoff['taskUid'] ?? 0);
        $subjectTable = (string)($handoff['subjectTable'] ?? '');
        $task = $taskUid > 0 ? $this->taskRepository->findByUid($taskUid) : null;
        if ($task === null
            || (int)$task['closed'] === 1
            || (int)$task['subject_uid'] !== 0
            || (string)$task['subject_table'] !== $subjectTable
            || (int)($handoff['workspaceUid'] ?? 0) !== (int)$backendUser->workspace
        ) {
            return null;
        }

        return [
            'taskUid' => $taskUid,
            'subjectTable' => $subjectTable,
            'targetPageUid' => (int)($handoff['targetPageUid'] ?? 0),
        ];
    }
}
