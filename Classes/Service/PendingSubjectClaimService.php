<?php

declare(strict_types=1);

namespace GbWeb\ContentFlow\Service;

use GbWeb\ContentFlow\Domain\Repository\TaskRepository;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Workspaces\Service\StagesService;

/**
 * Attaches a newly created page or record to the task waiting for it.
 */
final readonly class PendingSubjectClaimService
{
    public function __construct(
        private TaskRepository $taskRepository,
        private PendingSubjectHandoff $handoff,
        private ActivityLogger $activityLogger,
    ) {
    }

    public function claimCreatedSubject(
        BackendUserAuthentication $backendUser,
        string $table,
        int $recordUid,
        int $pageUid,
    ): void {
        if ($recordUid < 1) {
            return;
        }

        $handoff = $this->handoff->resolve($backendUser);
        if ($handoff === null || $handoff['subjectTable'] !== $table) {
            return;
        }
        if ($table !== 'pages' && $handoff['targetPageUid'] !== $pageUid) {
            return;
        }

        $workspaceUid = (int)$backendUser->workspace;
        if ($workspaceUid < 1) {
            return;
        }

        $subjectPid = $table === 'pages' ? $recordUid : $pageUid;
        $this->taskRepository->attachCreatedSubject(
            $handoff['taskUid'],
            $table,
            $recordUid,
            $subjectPid,
        );
        $this->taskRepository->attachWorkspace(
            $handoff['taskUid'],
            $workspaceUid,
            StagesService::STAGE_EDIT_ID,
        );
        $this->activityLogger->log(
            $handoff['taskUid'],
            ActivityLogger::EVENT_WORK_STARTED,
            (int)($backendUser->user['uid'] ?? 0),
            [
                'table' => $table,
                'recordUid' => $recordUid,
                'pageUid' => $pageUid,
                'createdWith' => $table === 'pages' ? 'core-page-wizard' : 'core-form-engine',
            ],
        );

        $this->handoff->forget($backendUser, $handoff['taskUid']);
    }
}
