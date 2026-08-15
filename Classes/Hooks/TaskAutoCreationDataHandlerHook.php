<?php

declare(strict_types=1);

namespace GbWeb\ContentFlow\Hooks;

use GbWeb\ContentFlow\Service\PendingPageClaimService;
use GbWeb\ContentFlow\Service\PendingSubjectClaimService;
use GbWeb\ContentFlow\Service\TaskAutoCreationService;
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
}
