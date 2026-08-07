<?php

declare(strict_types=1);

namespace GbWeb\ContentFlow\Service;

use GbWeb\ContentFlow\Domain\Model\TaskState;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Workspaces\Domain\Repository\WorkspaceRepository;
use TYPO3\CMS\Workspaces\Domain\Repository\WorkspaceStageRepository;
use TYPO3\CMS\Workspaces\Service\StagesService;

/**
 * Builds the board's columns.
 *
 * A Content Flow board is:
 *
 *   [ Backlog ] [ Planned ]  |  [ In Progress ] [ ...custom stages... ] [ Ready ]  |  [ Done ]
 *   \___ Content Flow ____/     \_________ TYPO3 core workspace stages _________/     \_ CF _/
 *
 * The middle section is read straight from `sys_workspace_stage`, so an integrator
 * defines review steps exactly where TYPO3 already expects them (Workspace record ->
 * Stages) and Content Flow picks them up without its own configuration. The outer
 * columns are Content Flow's own states, which is what makes a backlog possible at
 * all: core has no notion of "planned but not yet touched".
 *
 * This also answers web-vision/kanban-workspaces#31 (custom stages before/after the
 * default ones) for free - "before editing" is Backlog/Planned, "after ready" is Done,
 * and everything in between is already freely definable in core.
 */
class BoardColumnRegistry
{
    public function __construct(
        private readonly WorkspaceStageRepository $workspaceStageRepository,
        private readonly WorkspaceRepository $workspaceRepository,
        private readonly StagesService $stagesService,
    ) {
    }

    /**
     * @return list<array{
     *     key: string,
     *     label: string,
     *     state: string,
     *     stageUid: int|null,
     *     acceptsDrop: bool
     * }>
     */
    public function getColumns(BackendUserAuthentication $backendUser, int $workspaceUid): array
    {
        $columns = [
            $this->column('backlog', TaskState::BACKLOG, null, true),
            $this->column('planned', TaskState::PLANNED, null, true),
        ];

        foreach ($this->getStages($backendUser, $workspaceUid) as $stageUid) {
            $columns[] = [
                'key' => 'stage-' . $stageUid,
                'label' => $this->stagesService->getStageTitle($stageUid),
                'state' => TaskState::fromStageId($stageUid)->value,
                'stageUid' => $stageUid,
                // Dropping here triggers a core stage transition, never a direct write.
                'acceptsDrop' => true,
            ];
        }

        // Publishing is an explicit, irreversible action - it is deliberately not a
        // drop target. Editors publish from the card, with a confirmation.
        $columns[] = $this->column('done', TaskState::DONE, null, false);

        return $columns;
    }

    /**
     * Stage uids for the workspace, in board order, excluding the internal
     * "publish execute" stage (-20) which is a core implementation detail and never
     * a column an editor should see.
     *
     * @return list<int>
     */
    private function getStages(BackendUserAuthentication $backendUser, int $workspaceUid): array
    {
        if ($workspaceUid < 1) {
            return [];
        }
        try {
            // Throws (does not return null) when the workspace record is gone,
            // e.g. a workspace deleted while a be_user still had it selected.
            $workspaceRecord = $this->workspaceRepository->findByUid($workspaceUid);
        } catch (\RuntimeException) {
            return [];
        }

        $stageUids = [];
        foreach ($this->workspaceStageRepository->findAllStagesByWorkspace($backendUser, $workspaceRecord) as $stage) {
            $stageUid = (int)$stage->uid;
            if ($stageUid === StagesService::STAGE_PUBLISH_EXECUTE_ID) {
                continue;
            }
            $stageUids[] = $stageUid;
        }
        return $stageUids;
    }

    /**
     * @return array{key: string, label: string, state: string, stageUid: null, acceptsDrop: bool}
     */
    private function column(string $key, TaskState $state, ?int $stageUid, bool $acceptsDrop): array
    {
        return [
            'key' => $key,
            'label' => $this->getLanguageService()->sL(
                'LLL:EXT:content_flow/Resources/Private/Language/locallang.xlf:column.' . $key
            ) ?: ucfirst($key),
            'state' => $state->value,
            'stageUid' => $stageUid,
            'acceptsDrop' => $acceptsDrop,
        ];
    }

    private function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}
