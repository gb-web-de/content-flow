<?php

declare(strict_types=1);

namespace GbWeb\ContentFlow\EventListener;

use GbWeb\ContentFlow\Domain\Repository\TaskRepository;
use GbWeb\ContentFlow\Service\ActivityLogger;
use GbWeb\ContentFlow\Service\TaskMemberSynchronizer;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Workspaces\Event\AfterRecordPublishedEvent;

/**
 * "Es geht live, der Task wird geschlossen."
 *
 * With one qualification that the aggregation model forces: a task covers a page
 * AND everything on it, and this event fires once per published record. Closing on
 * the first one would archive a task while half its content elements are still in
 * review. So the task closes only when nothing it covers is pending any more.
 *
 * `getRecordId()` is the *live* uid, in both core publish paths (the swap path in
 * EXT:workspaces DataHandlerHook::publishVersion, and publishNewRecord). That
 * matches how membership is stored, so no version->live resolution is needed.
 *
 * Nothing is snapshotted here. Core migrates the version's sys_history rows onto
 * the live uid a few lines after dispatching this event
 * (RecordHistoryStore::publishRecord -> migrateWorkspaceHistory), so the trail
 * survives publishing on its own. What Content Flow keeps durably is written when
 * each decision is made - see ActivityLogger.
 */
final class CloseTaskAfterPublishListener
{
    public function __construct(
        private readonly TaskRepository $taskRepository,
        private readonly TaskMemberSynchronizer $memberSynchronizer,
        private readonly ActivityLogger $activityLogger,
    ) {
    }

    #[AsEventListener(identifier: 'content-flow/close-task-after-publish')]
    public function __invoke(AfterRecordPublishedEvent $event): void
    {
        $task = $this->taskRepository->findOpenTaskByMember($event->getTable(), $event->getRecordId());
        if ($task === null) {
            // Published something that was never tracked - nothing to close.
            return;
        }

        $taskUid = (int)$task['uid'];
        $beUserId = (int)($this->getBackendUser()?->user['uid'] ?? 0);

        if ($this->memberSynchronizer->hasPendingVersions($taskUid, $event->getWorkspaceId())) {
            // Part of the task went live, the rest has not. Record it and wait.
            $this->activityLogger->log($taskUid, ActivityLogger::EVENT_PUBLISHED, $beUserId, [
                'table' => $event->getTable(),
                'liveUid' => $event->getRecordId(),
                'workspaceId' => $event->getWorkspaceId(),
                'taskComplete' => false,
            ]);
            return;
        }

        $this->taskRepository->close($taskUid, $beUserId);
        $this->activityLogger->log($taskUid, ActivityLogger::EVENT_CLOSED, $beUserId, [
            'workspaceId' => $event->getWorkspaceId(),
            // Kept so the archived task can still find its trail: after publishing,
            // core has re-pointed the version's sys_history rows at these live uids.
            'table' => $event->getTable(),
            'liveUid' => $event->getRecordId(),
        ]);
    }

    private function getBackendUser(): ?BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'] ?? null;
    }
}
