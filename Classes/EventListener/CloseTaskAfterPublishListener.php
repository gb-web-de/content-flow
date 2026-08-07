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
 * Two things about this event that the implementation depends on:
 *
 * 1. `getRecordId()` is the *live* uid, in both core publish paths (the swap path in
 *    EXT:workspaces DataHandlerHook::publishVersion and publishNewRecord). That
 *    matches how a task stores `record_uid`, so no version->live resolution is needed.
 * 2. It is dispatched while the version record still exists (core discards it later),
 *    which is the only window in which the version's sys_history rows can still be
 *    snapshotted. Doing this any later would lose the trail - see ActivityLogger.
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
        $versionUid = (int)($task['version_uid'] ?? 0);
        $beUserId = (int)($this->getBackendUser()?->user['uid'] ?? 0);

        if ($versionUid > 0) {
            $this->activityLogger->snapshotHistory($taskUid, $event->getTable(), $versionUid, $beUserId);
        }

        $this->taskRepository->close($taskUid, $beUserId);
        $this->activityLogger->log($taskUid, ActivityLogger::EVENT_CLOSED, $beUserId, [
            'workspaceId' => $event->getWorkspaceId(),
        ]);
    }

    private function getBackendUser(): ?BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'] ?? null;
    }
}
