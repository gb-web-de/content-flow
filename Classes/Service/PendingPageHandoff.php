<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Service;

use GbWeb\EditorialFlow\Domain\Repository\TaskRepository;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * "This ticket is waiting for the page TYPO3 is about to create."
 *
 * A ticket planned with "Neue Seite erstellen" has no page until it reaches
 * Editing, and the page is created by *core's* page wizard - the same dialog
 * the page tree opens, with its position step, doktype step and FormEngine
 * fields. Editorial Flow does not rebuild that wizard and does not wrap it: the
 * host element `<typo3-backend-page-wizard>` submits to core's own
 * `page_wizard` provider, which is hardcoded in core's JavaScript, and a copy
 * of it here would drift out of date the moment core improves the original.
 *
 * So the two halves are joined at the other end instead: this note is written
 * before the wizard opens, and the DataHandler hook that sees the new page
 * claims it for the waiting ticket. That also means a page created this way is
 * linked no matter which of core's paths created it.
 *
 * The note expires. Without that, an editor who opens the wizard and cancels
 * would leave a claim lying around that silently adopts whatever page they
 * create next, possibly for something else entirely.
 */
final readonly class PendingPageHandoff
{
    private const SESSION_KEY = 'editorial_flow_pending_page_handoff';

    /**
     * Long enough to fill in a page form without hurrying, short enough that a
     * forgotten claim cannot follow an editor around for the rest of the day.
     */
    private const LIFETIME_SECONDS = 600;

    public function __construct(
        private TaskRepository $taskRepository,
    ) {
    }

    public function remember(BackendUserAuthentication $backendUser, int $taskUid, int $parentPageUid): void
    {
        $backendUser->setAndSaveSessionData(self::SESSION_KEY, [
            'taskUid' => $taskUid,
            'parentPageUid' => $parentPageUid,
            'expiresAt' => time() + self::LIFETIME_SECONDS,
        ]);
    }

    public function forget(BackendUserAuthentication $backendUser): void
    {
        $backendUser->setAndSaveSessionData(self::SESSION_KEY, null);
    }

    /**
     * The ticket still waiting for a page, or null.
     *
     * Re-validated on every read rather than trusted: the ticket may have been
     * closed, or may have got its page through another route in the meantime,
     * in which case there is nothing left to claim.
     */
    public function resolve(BackendUserAuthentication $backendUser): ?int
    {
        $handoff = $backendUser->getSessionData(self::SESSION_KEY);
        if (!is_array($handoff)) {
            return null;
        }
        if ((int)($handoff['expiresAt'] ?? 0) < time()) {
            return null;
        }

        $taskUid = (int)($handoff['taskUid'] ?? 0);
        if ($taskUid < 1) {
            return null;
        }

        $task = $this->taskRepository->findByUid($taskUid);
        if (
            $task === null
            || (int)$task['closed'] === 1
            || (string)$task['subject_table'] !== 'pages'
            || (int)$task['subject_uid'] !== 0
        ) {
            return null;
        }

        return $taskUid;
    }
}
