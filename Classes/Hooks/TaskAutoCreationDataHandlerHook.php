<?php

declare(strict_types=1);

namespace GbWeb\ContentFlow\Hooks;

use GbWeb\ContentFlow\Domain\Model\TaskState;
use GbWeb\ContentFlow\Domain\Repository\TaskRepository;
use GbWeb\ContentFlow\Service\ActivityLogger;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;

/**
 * Creates or advances a Content Flow task whenever an editor edits a record inside a
 * workspace - "if there is no task yet and the page gets edited, a task is created".
 *
 * This is a DataHandler hook rather than a PSR-14 listener on purpose: TYPO3 core has
 * no event for "a workspace version was created" (EXT:workspaces ships events for
 * publishing and for grid rendering, but not for versioning). `getAutoVersionId()` is
 * public API on DataHandler and is the documented way to learn which version record
 * an update was redirected into.
 *
 * The editor never sees this happen - that is the point. Opening a page and typing is
 * the whole interaction; the board updates itself.
 */
#[Autoconfigure(public: true)]
final class TaskAutoCreationDataHandlerHook
{
    public function __construct(
        private readonly TaskRepository $taskRepository,
        private readonly ActivityLogger $activityLogger,
        private readonly TcaSchemaFactory $tcaSchemaFactory,
    ) {
    }

    /**
     * @param array<string, mixed> $fieldArray
     */
    public function processDatamap_afterDatabaseOperations(
        string $status,
        string $table,
        int|string $id,
        array $fieldArray,
        DataHandler $dataHandler,
    ): void {
        if ($status !== 'update') {
            return;
        }
        $workspaceUid = (int)($dataHandler->BE_USER->workspace ?? 0);
        if ($workspaceUid < 1) {
            // Live edits do not open tasks: Content Flow's workflow starts when work
            // becomes reviewable, and on Live there is nothing to review against.
            return;
        }
        if (!$this->tcaSchemaFactory->has($table) || !$this->tcaSchemaFactory->get($table)->isWorkspaceAware()) {
            return;
        }

        $liveUid = (int)$id;
        if ($liveUid < 1) {
            return;
        }

        // Non-null exactly when this update was redirected into a workspace version.
        $versionUid = $dataHandler->getAutoVersionId($table, $liveUid);
        if ($versionUid === null) {
            return;
        }

        $versionRecord = BackendUtility::getRecord($table, $versionUid, 'uid,pid,t3ver_stage');
        if ($versionRecord === null) {
            return;
        }
        $stageUid = (int)($versionRecord['t3ver_stage'] ?? 0);

        $task = $this->taskRepository->findOrCreateOpenByRecord($table, $liveUid, [
            'title' => $this->deriveTitle($table, $liveUid),
            'record_pid' => $this->derivePid($table, $liveUid, $versionRecord),
            'state' => TaskState::IN_PROGRESS->value,
            'workspace_uid' => $workspaceUid,
            'version_uid' => $versionUid,
            'stage_uid' => $stageUid,
            'assignee' => (int)($dataHandler->BE_USER->user['uid'] ?? 0),
        ]);

        $taskUid = (int)$task['uid'];
        $wasUnversioned = (int)($task['version_uid'] ?? 0) === 0;

        if ($wasUnversioned) {
            // Backlog/Planned -> In Progress, now that real work exists.
            $this->taskRepository->attachVersion($taskUid, $workspaceUid, $versionUid, $stageUid);
            $this->activityLogger->log(
                $taskUid,
                ActivityLogger::EVENT_WORK_STARTED,
                (int)($dataHandler->BE_USER->user['uid'] ?? 0),
                ['versionUid' => $versionUid, 'stageUid' => $stageUid],
            );
        }
    }

    /**
     * A task needs a human-readable title from the first moment, so an editor who
     * never opens the board still leaves behind something readable.
     */
    private function deriveTitle(string $table, int $liveUid): string
    {
        $record = BackendUtility::getRecord($table, $liveUid);
        if ($record === null) {
            return sprintf('%s:%d', $table, $liveUid);
        }
        $title = BackendUtility::getRecordTitle($table, $record);
        return $title !== '' ? $title : sprintf('%s:%d', $table, $liveUid);
    }

    /**
     * @param array<string, mixed> $versionRecord
     */
    private function derivePid(string $table, int $liveUid, array $versionRecord): int
    {
        // For pages the board groups by the page itself, for everything else by the
        // page the record sits on.
        if ($table === 'pages') {
            return $liveUid;
        }
        $liveRecord = BackendUtility::getRecord($table, $liveUid, 'pid');
        return (int)($liveRecord['pid'] ?? $versionRecord['pid'] ?? 0);
    }
}
