<?php

declare(strict_types=1);

namespace GbWeb\ContentFlow\EventListener;

use GbWeb\ContentFlow\Domain\Repository\TaskRepository;
use GbWeb\ContentFlow\Service\ActivityLogger;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Workspaces\Event\AfterRecordPublishedEvent;

/**
 * "It goes live, the task is closed."
 *
 * `getRecordId()` is the *live* uid, in both core publish paths (the swap path in
 * EXT:workspaces DataHandlerHook::publishVersion, and publishNewRecord). That matches
 * how a task stores `record_uid`, so no version->live resolution is needed.
 *
 * Nothing is snapshotted here. Core migrates the version's sys_history rows onto the
 * live uid a few lines after dispatching this event (RecordHistoryStore::publishRecord
 * -> migrateWorkspaceHistory), so the trail survives publishing on its own and copying
 * it would be pure duplication. What Content Flow keeps durably is written when each
 * decision is made, not here - see ActivityLogger.
 */
final class CloseTaskAfterPublishListener
{
    public function __construct(
        private readonly TaskRepository $taskRepository,
        private readonly ActivityLogger $activityLogger,
    ) {
    }

    #[AsEventListener(identifier: 'content-flow/close-task-after-publish')]
    public function __invoke(AfterRecordPublishedEvent $event): void
    {
        $task = $this->taskRepository->findOpenByRecord($event->getTable(), $event->getRecordId());
        if ($task === null) {
            // Published something that was never tracked - nothing to close.
            return;
        }

        $taskUid = (int)$task['uid'];
        $beUserId = (int)($this->getBackendUser()?->user['uid'] ?? 0);

        $this->taskRepository->close($taskUid, $beUserId);
        $this->activityLogger->log($taskUid, ActivityLogger::EVENT_CLOSED, $beUserId, [
            'workspaceId' => $event->getWorkspaceId(),
            // Kept so the archived task can still find its trail: after publishing,
            // core has re-pointed the version's sys_history rows at this live uid.
            'liveUid' => $event->getRecordId(),
            'table' => $event->getTable(),
        ]);
    }

    private function getBackendUser(): ?BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'] ?? null;
    }
}
