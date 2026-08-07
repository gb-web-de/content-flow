<?php

declare(strict_types=1);

namespace GbWeb\ContentFlow\Service;

use GbWeb\ContentFlow\Domain\Repository\TaskRepository;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Workspaces\Service\HistoryService;

class WorkspaceIntegrationService
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly TaskRepository $taskRepository,
        private readonly ActivityLogger $activityLogger,
        private readonly HistoryService $historyService,
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
        $diffs = $this->getRecordDiffs($subjectTable, $subjectUid);

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
            'members' => $members,
            'warnedCount' => count($warnedMembers),
            'comments' => $comments,
            'activities' => $activities,
            'diffs' => $diffs,
        ];
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
}
