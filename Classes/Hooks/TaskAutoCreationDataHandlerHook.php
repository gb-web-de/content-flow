<?php

declare(strict_types=1);

namespace GbWeb\ContentFlow\Hooks;

use GbWeb\ContentFlow\Domain\Model\TaskState;
use GbWeb\ContentFlow\Domain\Repository\TaskRepository;
use GbWeb\ContentFlow\Service\ActivityLogger;
use GbWeb\ContentFlow\Service\ReferenceInspector;
use GbWeb\ContentFlow\Service\TaskMemberSynchronizer;
use GbWeb\ContentFlow\Service\TaskSubjectRegistry;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Schema\Capability\TcaSchemaCapability;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;

/**
 * Creates or advances a task whenever an editor edits something inside a workspace -
 * "wenn kein Task da ist und die Seite bearbeitet wird, wird ein Task erzeugt".
 *
 * The routing is what makes the board readable:
 *
 *   editing a page          -> that page's task
 *   editing a news record   -> that news record's own task (it is page-like)
 *   editing a content elem. -> the task of the page it sits on, NOT its own card
 *   ...unless an editor detached that element, in which case it keeps its own task
 *
 * A DataHandler hook rather than a PSR-14 listener on purpose: TYPO3 core has no
 * event for "a workspace version was created". `getAutoVersionId()` is public API
 * and is the documented way to learn which version an update was redirected into.
 *
 * The editor never sees this happen - that is the point. Opening a page and typing
 * is the whole interaction; the board updates itself.
 */
#[Autoconfigure(public: true)]
final class TaskAutoCreationDataHandlerHook
{
    public function __construct(
        private readonly TaskRepository $taskRepository,
        private readonly TaskSubjectRegistry $subjectRegistry,
        private readonly TaskMemberSynchronizer $memberSynchronizer,
        private readonly ReferenceInspector $referenceInspector,
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
            // Live edits do not open tasks: the workflow starts when work becomes
            // reviewable, and on Live there is nothing to review against.
            return;
        }
        if (!$this->subjectRegistry->isTrackable($table)) {
            return;
        }

        $resolved = $this->resolveLiveAndVersion($table, (int)$id, $workspaceUid, $dataHandler);
        if ($resolved === null) {
            return;
        }
        [$liveUid, $versionUid] = $resolved;

        $stageUid = (int)(BackendUtility::getRecord($table, $versionUid, 't3ver_stage')['t3ver_stage'] ?? 0);
        $beUserId = (int)($dataHandler->BE_USER->user['uid'] ?? 0);

        $task = $this->resolveTask($table, $liveUid, $workspaceUid, $stageUid, $beUserId);
        if ($task === null) {
            return;
        }

        $taskUid = (int)$task['uid'];
        if ((int)($task['workspace_uid'] ?? 0) === 0) {
            // Backlog/Planned -> In Progress, now that real work exists.
            $this->taskRepository->attachWorkspace($taskUid, $workspaceUid, $stageUid);
            $this->activityLogger->log(
                $taskUid,
                ActivityLogger::EVENT_WORK_STARTED,
                $beUserId,
                ['table' => $table, 'recordUid' => $liveUid, 'stageUid' => $stageUid],
            );
        }
    }

    /**
     * Work out which live record this edit was really about, and which version now
     * holds it.
     *
     * The subtlety that makes this necessary: DataHandler **rewrites `$id` to the
     * version uid** before calling this hook. In processDatamap_afterDatabaseOperations
     * `$id` is therefore usually the version, not the live record - see
     * `$id = $this->autoVersionIdMap[$table][$id];` in DataHandler::process_datamap().
     * Calling getAutoVersionId() on that id returns null, because a version has no
     * version of its own, and an implementation that trusts it alone silently does
     * nothing at all.
     *
     * Both directions are handled: `$id` already being a version (the normal case),
     * and `$id` still being live with a version created alongside it.
     *
     * @return array{0: int, 1: int}|null [liveUid, versionUid]
     */
    private function resolveLiveAndVersion(
        string $table,
        int $id,
        int $workspaceUid,
        DataHandler $dataHandler,
    ): ?array {
        if ($id < 1) {
            return null;
        }

        $record = BackendUtility::getRecord($table, $id, 'uid,t3ver_oid,t3ver_wsid');
        if ($record === null) {
            return null;
        }

        $versionedFrom = (int)($record['t3ver_oid'] ?? 0);
        if ($versionedFrom > 0) {
            // $id is the version. Only ours if it lives in the active workspace.
            if ((int)($record['t3ver_wsid'] ?? 0) !== $workspaceUid) {
                return null;
            }
            return [$versionedFrom, $id];
        }

        // $id is still the live record - a version may have been created next to it.
        $autoVersionUid = $dataHandler->getAutoVersionId($table, $id);
        if ($autoVersionUid === null) {
            return null;
        }
        return [$id, $autoVersionUid];
    }

    /**
     * Find the task this edit belongs to, creating it if the work was unplanned.
     *
     * @return array<string, mixed>|null
     */
    private function resolveTask(string $table, int $liveUid, int $workspaceUid, int $stageUid, int $beUserId): ?array
    {
        // An existing membership wins over everything else. This is what keeps a
        // detached element with its own task instead of being pulled back into the
        // page's task the next time someone edits it.
        $existing = $this->taskRepository->findOpenTaskByMember($table, $liveUid);
        if ($existing !== null) {
            return $existing;
        }

        $subject = $this->subjectRegistry->resolveSubjectFor($table, $liveUid);
        if ($subject === null) {
            return null;
        }

        $isNew = $this->taskRepository->findOpenBySubject($subject['table'], $subject['uid']) === null;
        $subjectPid = $this->derivePid($subject['table'], $subject['uid']);
        $task = $this->taskRepository->findOrCreateOpenForSubject($subject['table'], $subject['uid'], [
            'title' => $this->deriveTitle($subject['table'], $subject['uid']),
            'subject_pid' => $subjectPid,
            'state' => TaskState::IN_PROGRESS->value,
            'workspace_uid' => 0,
            'stage_uid' => $stageUid,
            'assignee' => $beUserId,
            // Nobody planned this - the editor simply started working. The board
            // marks it, and the post-save wizard offers to merge it somewhere.
            'auto_created' => 1,
        ]);
        $taskUid = (int)$task['uid'];

        if ($isNew) {
            $this->activityLogger->log($taskUid, ActivityLogger::EVENT_TASK_CREATED, $beUserId, [
                'subjectTable' => $subject['table'],
                'subjectUid' => $subject['uid'],
                'unplanned' => true,
            ]);
            // A page's task covers the page and everything on it.
            if ($subject['table'] === 'pages') {
                $this->memberSynchronizer->syncPageMembers($taskUid, $subject['uid']);
            }
        }

        // The edited record may still be unclaimed - a record created after the last
        // sync, or one on a subject that is not a page. Claim it now; if someone else
        // already owns it, leave it with them.
        $homePid = $this->derivePid($table, $liveUid);
        $this->taskRepository->addMemberIfUnclaimed(
            $taskUid,
            $table,
            $liveUid,
            TaskRepository::ORIGIN_AUTO,
            $homePid,
            $this->referenceInspector->isSharedAcrossPages($table, $liveUid, $homePid),
        );

        return $task;
    }

    /**
     * A task needs a human-readable title from the first moment, so an editor who
     * never opens the board still leaves behind something readable.
     *
     * Reads the label field via the TCA schema rather than calling
     * BackendUtility::getRecordTitle(). That helper resolves LLL references and
     * therefore requires $GLOBALS['LANG'], which is not guaranteed here: this hook
     * also runs when DataHandler is driven from the CLI (imports, scheduler tasks,
     * tests), and there it fataled on a null LanguageService.
     */
    private function deriveTitle(string $table, int $uid): string
    {
        $fallback = sprintf('%s:%d', $table, $uid);
        if (!$this->tcaSchemaFactory->has($table)) {
            return $fallback;
        }

        $labelCapability = $this->tcaSchemaFactory->get($table)->getCapability(TcaSchemaCapability::Label);
        $labelField = $labelCapability->getPrimaryFieldName();
        if ($labelField === null) {
            return $fallback;
        }

        $record = BackendUtility::getRecord($table, $uid, $labelField);
        $title = trim((string)($record[$labelField] ?? ''));

        return $title !== '' ? $title : $fallback;
    }

    private function derivePid(string $table, int $uid): int
    {
        // A page's board scope is the page itself; a page-like record (news) is
        // scoped to the folder or page it is stored on.
        if ($table === 'pages') {
            return $uid;
        }
        return (int)(BackendUtility::getRecord($table, $uid, 'pid')['pid'] ?? 0);
    }
}
