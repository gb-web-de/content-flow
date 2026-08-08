<?php

declare(strict_types=1);

namespace GbWeb\ContentFlow\Service;

use GbWeb\ContentFlow\Domain\Repository\TaskChecklistRepository;
use GbWeb\ContentFlow\Domain\Repository\TaskRepository;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Workspaces\Domain\Repository\WorkspaceRepository;
use TYPO3\CMS\Workspaces\Domain\Repository\WorkspaceStageRepository;
use TYPO3\CMS\Workspaces\Exception\WorkspaceStageNotFoundException;
use TYPO3\CMS\Workspaces\Service\HistoryService;
use TYPO3\CMS\Workspaces\Service\StagesService;

final class WorkspaceIntegrationService
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly TaskRepository $taskRepository,
        private readonly TaskChecklistRepository $checklistRepository,
        private readonly ActivityLogger $activityLogger,
        private readonly HistoryService $historyService,
        private readonly IconFactory $iconFactory,
        private readonly WorkspaceStageRepository $workspaceStageRepository,
        private readonly WorkspaceRepository $workspaceRepository,
        private readonly StagesService $stagesService,
    ) {
    }

    /**
     * Aggregates full task details, members, comments, activity log, and field diffs.
     *
     * @return array<string, mixed>|null
     */
    public function getTaskDetails(int $taskUid): ?array
    {
        $task = $this->taskRepository->findByUid($taskUid);
        if ($task === null) {
            return null;
        }

        $subjectTable = (string)$task['subject_table'];
        $subjectUid = (int)$task['subject_uid'];
        $subjectRecord = BackendUtility::getRecord($subjectTable, $subjectUid);

        $members = $this->taskRepository->findMembers($taskUid);
        $warnedMembers = $this->taskRepository->findWarnedMembers($taskUid, (int)$task['subject_pid']);
        $comments = $this->getTaskComments($taskUid);
        $activities = $this->activityLogger->findByTask($taskUid);
        $workspaceUid = (int)$task['workspace_uid'];
        $decoratedMembers = $this->decorateMembers($members, (int)$task['subject_pid'], $workspaceUid);
        // The subject is always a member of its own task (see
        // TaskRepository::findOrCreateOpenForSubject()), so aggregating diffs across
        // every member already covers the subject too - no separate subject-only
        // call needed, and nothing to deduplicate. Also stamps `hasDiffs` onto each
        // member so Ticket.html can offer a "Diff" jump button only where there is
        // something to jump to.
        $diffs = $this->getAggregatedMemberDiffs($decoratedMembers);
        // Empty before the task has a version at all - a checklist reviews
        // work against a stage, and there is no stage to review against yet.
        $checklist = $workspaceUid > 0
            ? $this->checklistRepository->findChecklistForTask($taskUid, $workspaceUid, (int)$task['stage_uid'])
            : [];

        $assigneeUser = null;
        $assigneeUid = (int)($task['assignee'] ?? 0);
        if ($assigneeUid > 0) {
            $userRecord = BackendUtility::getRecord('be_users', $assigneeUid, 'uid,username,realName');
            if ($userRecord) {
                $assigneeUser = [
                    'uid' => (int)$userRecord['uid'],
                    'name' => !empty($userRecord['realName']) ? $userRecord['realName'] : $userRecord['username'],
                ];
            }
        }

        return [
            'task' => $task,
            'subject' => [
                'table' => $subjectTable,
                'uid' => $subjectUid,
                'title' => $subjectRecord ? BackendUtility::getRecordTitle($subjectTable, $subjectRecord) : sprintf('%s:%d', $subjectTable, $subjectUid),
            ],
            'assignee' => $assigneeUser,
            'members' => $decoratedMembers,
            'warnedCount' => count($warnedMembers),
            'comments' => $comments,
            'activities' => $activities,
            'timeline' => $this->buildTimeline($activities, $comments),
            'diffs' => $diffs,
            'checklist' => $checklist,
        ];
    }

    /**
     * The full change picture for a task: every member's own field diffs, not just
     * the subject's - a page task with several content-element members previously
     * only ever showed the page's own history, silently hiding what actually changed
     * inside those elements.
     *
     * Grouped by member (each member's own diffs already newest-first, per core's
     * HistoryService), not merge-sorted across members: `datetime` here is a
     * site-formatted string from BackendUtility::datetime(), not a raw timestamp,
     * so it cannot be compared reliably across records.
     *
     * Mutates $decoratedMembers in place, stamping `hasDiffs` onto each member -
     * cheaper than a second pass, and keeps "does this member have a diff to jump
     * to" defined in exactly one place.
     *
     * @param list<array<string, mixed>> $decoratedMembers
     * @return list<array{label: string, html: string, user: string, datetime: string, record: string, table: string, uid: int}>
     */
    private function getAggregatedMemberDiffs(array &$decoratedMembers): array
    {
        $diffs = [];
        foreach ($decoratedMembers as &$member) {
            $table = (string)$member['record_table'];
            $uid = (int)$member['record_uid'];
            $memberDiffs = $this->getRecordDiffs($table, $uid);
            $member['hasDiffs'] = $memberDiffs !== [];
            foreach ($memberDiffs as $diff) {
                $diff['record'] = ($member['title'] ?? '') !== ''
                    ? sprintf('%s (%s:%d)', $member['title'], $table, $uid)
                    : sprintf('%s:%d', $table, $uid);
                $diff['table'] = $table;
                $diff['uid'] = $uid;
                $diffs[] = $diff;
            }
        }
        unset($member);

        return $diffs;
    }

    /**
     * Extract field-level diffs (old vs new value) from sys_history entries.
     *
     * Step-by-step change history: one entry per changed field per revision,
     * with core's rendered diff markup.
     *
     * @return list<array{label: string, html: string, user: string, datetime: string}>
     */
    public function getRecordDiffs(string $table, int $uid): array
    {
        // Delegated to core rather than diffed by hand. The previous
        // implementation read history_data['newFields'] / ['oldFields'], but core
        // writes 'newRecord' / 'oldRecord' - so it silently returned an empty diff
        // every time. Core also resolves processed values, FlexForm content and
        // TCA field labels, none of which a hand-rolled version had.
        //
        // HistoryService is flagged @internal, which is why it is used in exactly
        // this one place: if core changes it, only this method has to follow.
        $history = $this->historyService->getHistory($table, $uid);

        $diffs = [];
        foreach ($history as $entry) {
            $differences = $entry['differences'] ?? [];
            if (!is_array($differences)) {
                continue;
            }
            foreach ($differences as $difference) {
                $diffs[] = [
                    'label' => (string)($difference['label'] ?? ''),
                    // Already-rendered diff markup from core's DiffUtility.
                    'html' => (string)($difference['html'] ?? ''),
                    'user' => (string)($entry['user_realName'] ?: $entry['user'] ?? ''),
                    'datetime' => (string)($entry['datetime'] ?? ''),
                ];
            }
        }

        return $diffs;
    }

    /**
     * Get eligible backend user recipients for stage change notifications.
     *
     * @return list<array{uid: int, username: string, name: string}>
     */
    public function getStageRecipients(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('be_users');
        $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());

        $users = $queryBuilder
            ->select('uid', 'username', 'realName')
            ->from('be_users')
            ->where($queryBuilder->expr()->eq('disable', $queryBuilder->createNamedParameter(0)))
            ->orderBy('username', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        $recipients = [];
        foreach ($users as $u) {
            $recipients[] = [
                'uid' => (int)$u['uid'],
                'username' => (string)$u['username'],
                'name' => !empty($u['realName']) ? (string)$u['realName'] : (string)$u['username'],
            ];
        }

        return $recipients;
    }

    /**
     * Turn the workspace dialog payload into the recipient array core expects.
     *
     * TYPO3's send-to-stage dialog submits backend user ids plus free-text email
     * addresses. DataHandler, however, records and mails a list of recipient
     * descriptors (`email`, optionally `lang`). Matching core's merge here keeps
     * the task board on the same contract as the native workspace popup.
     *
     * @param list<int> $selectedRecipientUids
     * @return list<array{email: string, lang?: string}>
     */
    public function buildNotificationRecipients(
        int $workspaceUid,
        int $stageUid,
        array $selectedRecipientUids,
        string $additionalRecipients,
    ): array {
        $additional = $this->normalizeAdditionalRecipients($additionalRecipients);
        if ($workspaceUid < 1) {
            return array_values($additional);
        }

        try {
            $workspaceRecord = $this->workspaceRepository->findByUid($workspaceUid);
            $stageRecord = $this->stagesService->getStage(
                $this->workspaceStageRepository->findAllStagesByWorkspace($this->getBackendUser(), $workspaceRecord),
                $stageUid,
            );
        } catch (\RuntimeException|WorkspaceStageNotFoundException) {
            return array_values($additional);
        }

        $selected = [];
        $allowedRecipients = $this->stagesService->getResponsibleBeUser($stageRecord);
        foreach ($selectedRecipientUids as $backendUserId) {
            $recipient = $this->recipientFromBackendUser($allowedRecipients[$backendUserId] ?? null);
            if ($recipient !== null) {
                $selected[$recipient['email']] = $recipient;
            }
        }

        if (!$stageRecord->isPreselectionChangeable && $stageRecord->preselectedRecipients !== []) {
            foreach ($this->stagesService->getBackendUsers($stageRecord->preselectedRecipients) as $backendUser) {
                $recipient = $this->recipientFromBackendUser($backendUser);
                if ($recipient !== null) {
                    $selected[$recipient['email']] = $recipient;
                }
            }
        }

        return array_values(array_merge($additional, $selected));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function getTaskComments(int $taskUid): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_contentflow_comment');
        $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());

        return $queryBuilder
            ->select('*')
            ->from('tx_contentflow_comment')
            ->where($queryBuilder->expr()->eq('task', $queryBuilder->createNamedParameter($taskUid, \TYPO3\CMS\Core\Database\Connection::PARAM_INT)))
            ->orderBy('crdate', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Turn raw membership rows into something a ticket view can show: what the
     * record actually is, what it is called, and whether touching it reaches
     * beyond this page.
     *
     * Records can be pages just as well as content elements, so the icon is
     * resolved per record instead of assumed - that is the only honest way to
     * show a mixed member list.
     *
     * @param list<array<string, mixed>> $members
     * @return list<array<string, mixed>>
     */
    private function decorateMembers(array $members, int $subjectPid, int $workspaceUid): array
    {
        $decorated = [];
        foreach ($members as $member) {
            $table = (string)$member['record_table'];
            $uid = (int)$member['record_uid'];
            $record = BackendUtility::getRecord($table, $uid);

            $homePid = (int)($member['home_pid'] ?? 0);
            $member['title'] = $record !== null
                ? BackendUtility::getRecordTitle($table, $record)
                : sprintf('%s:%d', $table, $uid);
            $member['icon'] = $record !== null
                ? $this->iconFactory->getIconForRecord($table, $record, IconSize::SMALL)->render()
                : '';
            $member['isForeign'] = $homePid > 0 && $homePid !== $subjectPid;
            $member['isShared'] = (int)($member['shared'] ?? 0) === 1;
            $member['needsAttention'] = $member['isForeign'] || $member['isShared'];
            // Gates the preview/discard buttons: neither makes sense for a
            // record with nothing pending (e.g. the page subject itself,
            // before anyone has touched it in this workspace).
            $member['hasPendingVersion'] = $workspaceUid > 0
                && BackendUtility::getWorkspaceVersionOfRecord($workspaceUid, $table, $uid, 'uid') !== false;
            $decorated[] = $member;
        }

        return $decorated;
    }

    /**
     * One chronological stream of what happened, with each comment attached to the
     * action it explains.
     *
     * Comments and actions are not separate stories. A stage comment *is* the
     * reason for that stage change, so showing them in two disconnected lists
     * forces the reader to correlate timestamps by hand. Anything anchored to an
     * activity is nested under it; genuinely standalone remarks stay as their own
     * entries.
     *
     * @param list<array<string, mixed>> $activities
     * @param list<array<string, mixed>> $comments
     * @return list<array<string, mixed>>
     */
    private function buildTimeline(array $activities, array $comments): array
    {
        $commentsByActivity = [];
        $standalone = [];
        foreach ($comments as $comment) {
            $anchor = (int)($comment['activity'] ?? 0);
            if ($anchor > 0) {
                $commentsByActivity[$anchor][] = $comment;
            } else {
                $standalone[] = $comment;
            }
        }

        $timeline = [];
        foreach ($activities as $activity) {
            $uid = (int)$activity['uid'];
            $timeline[] = [
                'type' => 'activity',
                'crdate' => (int)$activity['crdate'],
                'event' => (string)$activity['event'],
                'beUser' => $this->resolveUserName((int)($activity['be_user'] ?? 0)),
                'payload' => $this->decodePayload($activity['payload'] ?? null),
                'historyUid' => (int)($activity['history_uid'] ?? 0),
                'comments' => $commentsByActivity[$uid] ?? [],
            ];
        }
        foreach ($standalone as $comment) {
            $timeline[] = [
                'type' => 'comment',
                'crdate' => (int)$comment['crdate'],
                'beUser' => $this->resolveUserName((int)($comment['be_user'] ?? 0)),
                'content' => (string)($comment['content'] ?? ''),
                'comments' => [],
            ];
        }

        usort($timeline, static fn (array $a, array $b): int => $a['crdate'] <=> $b['crdate']);

        return $timeline;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodePayload(mixed $payload): array
    {
        if (!is_string($payload) || $payload === '') {
            return [];
        }
        $decoded = json_decode($payload, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<string, array{email: string}>
     */
    private function normalizeAdditionalRecipients(string $additionalRecipients): array
    {
        $normalized = [];
        foreach (GeneralUtility::trimExplode(LF, $additionalRecipients, true) as $email) {
            if (!GeneralUtility::validEmail($email)) {
                continue;
            }
            $normalized[$email] = ['email' => $email];
        }

        return $normalized;
    }

    /**
     * Public because the assignment notification builds its recipient the same
     * way: same be_users record shape, same uc/lang fallback.
     *
     * @param array<string, mixed>|null $backendUser
     * @return array{email: string, lang?: string}|null
     */
    public function recipientFromBackendUser(?array $backendUser): ?array
    {
        if (!is_array($backendUser)) {
            return null;
        }

        $email = (string)($backendUser['email'] ?? '');
        if ($email === '' || !GeneralUtility::validEmail($email)) {
            return null;
        }

        $recipient = ['email' => $email];
        $language = '';
        if (!empty($backendUser['uc'])) {
            $userConfiguration = unserialize((string)$backendUser['uc'], ['allowed_classes' => false]);
            if (is_array($userConfiguration) && ($userConfiguration['lang'] ?? '') !== '') {
                $language = (string)$userConfiguration['lang'];
            }
        }
        if ($language === '') {
            $language = (string)($backendUser['lang'] ?? '');
        }
        if ($language !== '') {
            $recipient['lang'] = $language;
        }

        return $recipient;
    }

    private function resolveUserName(int $beUserId): string
    {
        if ($beUserId < 1) {
            return 'System';
        }
        $user = BackendUtility::getRecord('be_users', $beUserId, 'username,realName');
        if ($user === null) {
            return 'unknown';
        }

        return trim((string)($user['realName'] ?? '')) !== ''
            ? (string)$user['realName']
            : (string)($user['username'] ?? 'unknown');
    }

    private function getBackendUser(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }
}
