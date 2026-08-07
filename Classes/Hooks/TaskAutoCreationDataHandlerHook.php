<?php

declare(strict_types=1);

namespace GbWeb\ContentFlow\Hooks;

use GbWeb\ContentFlow\Service\TaskAutoCreationService;
use TYPO3\CMS\Core\DataHandling\DataHandler;

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
