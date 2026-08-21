<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Hooks;

use GbWeb\EditorialFlow\Service\PendingPageClaimService;
use GbWeb\EditorialFlow\Service\PendingSubjectClaimService;
use GbWeb\EditorialFlow\Service\TaskAutoCreationService;
use TYPO3\CMS\Core\DataHandling\DataHandler;

final class TaskAutoCreationDataHandlerHook
{
    public function __construct(
        private readonly TaskAutoCreationService $taskAutoCreationService,
        private readonly PendingPageClaimService $pendingPageClaimService,
        private readonly PendingSubjectClaimService $pendingSubjectClaimService,
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
        // A brand new page may be the one a ticket has been waiting for - see
        // PendingPageHandoff for why the two halves meet here rather than in
        // whatever opened core's page wizard. captureEdit() ignores 'new'
        // entirely, so the two never compete for the same save.
        if ($status === 'new' && $table === 'pages') {
            // On 'new', $id is still the NEW... placeholder the datamap was keyed
            // by - casting it to int yields 0 and the claim silently never
            // happens. The real uid is in substNEWwithIDs, which DataHandler
            // fills before it calls this hook. (Same class of trap as the one
            // documented in AGENDA.md for the version uid on 'update'.)
            $this->pendingPageClaimService->claimCreatedPage(
                $dataHandler->BE_USER,
                (int)($dataHandler->substNEWwithIDs[$id] ?? 0),
                (int)($fieldArray['pid'] ?? 0),
            );
        }
        if ($status === 'new' && $table !== 'pages') {
            $this->pendingSubjectClaimService->claimCreatedSubject(
                $dataHandler->BE_USER,
                $table,
                (int)($dataHandler->substNEWwithIDs[$id] ?? 0),
                (int)($fieldArray['pid'] ?? 0),
            );
        }

        $this->taskAutoCreationService->captureEdit($status, $table, $id, $dataHandler);
    }

    /**
     * Deleting or moving a record in a workspace is a pending change like any
     * edit - it has to be reviewed and published - but core answers those
     * commands through the cmdmap, which never reaches
     * processDatamap_afterDatabaseOperations(). Without this, a page whose only
     * workspace change was a deletion opened no task at all: the board showed
     * nothing, while the page tree plainly marked the page as changed.
     *
     * captureEdit() is reused rather than reimplemented - the routing question
     * ("whose task does this record belong to?") has exactly one right answer
     * and it does not depend on which command produced the version. 'update' is
     * the honest status here: the live record still exists, it is its workspace
     * version that now says "gone".
     *
     * @param string|int $id
     */
    public function processCmdmap_postProcess(
        string $command,
        string $table,
        $id,
        mixed $value,
        DataHandler $dataHandler,
    ): void {
        if ($command !== 'delete' && $command !== 'move') {
            return;
        }
        if ((int)($dataHandler->BE_USER->workspace ?? 0) < 1) {
            // On Live there is no pending version to track, and the record is
            // simply gone - nothing for a task to cover.
            return;
        }

        $this->taskAutoCreationService->captureEdit('update', $table, (int)$id, $dataHandler);
    }
}
