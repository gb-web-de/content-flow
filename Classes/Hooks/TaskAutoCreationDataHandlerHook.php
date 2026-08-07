<?php

declare(strict_types=1);

namespace GbWeb\ContentFlow\Hooks;

use GbWeb\ContentFlow\Service\TaskAutoCreationService;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use TYPO3\CMS\Core\DataHandling\DataHandler;

/**
 * Trigger adapter. Contains no logic - it exists only to satisfy the hook
 * signature and hand over to TaskAutoCreationService.
 *
 * Why a hook and not a PSR-14 listener, checked against v14.3 rather than assumed:
 *
 * - DataHandler dispatches exactly two events, `BeforeRemoveNonCopyableFieldsEvent`
 *   and `EnrichPasswordValidationContextDataEvent`. Neither fires on versioning.
 * - EXT:workspaces ships events for publishing (`AfterRecordPublishedEvent`, which
 *   this extension does use as a listener), for grid rendering and for diffs - but
 *   none for "a workspace version was created".
 * - `RecordCreationEvent` sounds right but is dispatched by `RecordFactory` when a
 *   Record object is hydrated for reading. It is not a persistence event.
 * - `processDatamapClass` carries no deprecation marker in v14.3.
 *
 * So there is currently no listener that can observe this moment. Keeping the
 * adapter this thin is the practical answer: all behaviour lives in a normal
 * injectable service, and if core ever adds a suitable event, only this file is
 * replaced by a listener - the logic and its tests stay untouched.
 */
#[Autoconfigure(public: true)]
final class TaskAutoCreationDataHandlerHook
{
    public function __construct(
        private readonly TaskAutoCreationService $taskAutoCreationService,
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
        $this->taskAutoCreationService->captureEdit($status, $table, $id, $dataHandler);
    }
}
