<?php

declare(strict_types=1);

namespace GbWeb\ContentFlow\Service;

use GbWeb\ContentFlow\Domain\Repository\TaskRepository;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Workspaces\Service\StagesService;

/**
 * Closes the loop opened by PendingPageHandoff: a page has just been created,
 * and a ticket was waiting for exactly that.
 *
 * Called from the DataHandler hook rather than from whatever opened the wizard,
 * because core's page wizard reports its result to the browser as a redirect
 * and hands no uid back to a third package. The hook sees the creation itself,
 * which is both earlier and more reliable.
 *
 * The page's own position is not checked against the one the ticket planned:
 * the wizard's first step exists precisely so an editor can put the page
 * somewhere else, and overriding that decision afterwards would be worse than
 * recording it. Where it actually landed is written into the activity entry.
 */
final readonly class PendingPageClaimService
{
    public function __construct(
        private TaskRepository $taskRepository,
        private PendingPageHandoff $handoff,
        private ActivityLogger $activityLogger,
    ) {
    }

    public function claimCreatedPage(BackendUserAuthentication $backendUser, int $pageUid, int $parentPageUid): void
    {
        if ($pageUid < 1) {
            return;
        }

        $taskUid = $this->handoff->resolve($backendUser);
        if ($taskUid === null) {
            return;
        }

        $workspaceUid = (int)$backendUser->workspace;
        if ($workspaceUid < 1) {
            // The wizard was used on Live. Content Flow's workflow starts when
            // work becomes reviewable, so there is nothing to attach the ticket
            // to yet - and claiming the page without a workspace would leave the
            // ticket claiming to be in Editing with nothing pending.
            return;
        }

        $this->taskRepository->attachCreatedSubject($taskUid, 'pages', $pageUid, $pageUid);
        // A record created inside a workspace already sits in Editing; this only
        // makes the task's own bookkeeping say the same thing.
        $this->taskRepository->attachWorkspace($taskUid, $workspaceUid, StagesService::STAGE_EDIT_ID);

        $this->activityLogger->log(
            $taskUid,
            ActivityLogger::EVENT_WORK_STARTED,
            (int)($backendUser->user['uid'] ?? 0),
            [
                'table' => 'pages',
                'recordUid' => $pageUid,
                'parentPid' => $parentPageUid,
                'createdWith' => 'core-page-wizard',
            ],
        );

        // One page per handoff: whatever the editor creates next is their own
        // business again.
        $this->handoff->forget($backendUser);
    }
}
