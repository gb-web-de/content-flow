<?php

declare(strict_types=1);

namespace GbWeb\ContentFlow\Service;

use GbWeb\ContentFlow\Domain\Model\TaskState;
use GbWeb\ContentFlow\Domain\Repository\TaskChecklistRepository;
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
final class BoardColumnRegistry
{
    public function __construct(
        private readonly WorkspaceStageRepository $workspaceStageRepository,
        private readonly WorkspaceRepository $workspaceRepository,
        private readonly StagesService $stagesService,
        private readonly TaskChecklistRepository $checklistRepository,
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
            $this->column('backlog', TaskState::BACKLOG, true),
            $this->column('planned', TaskState::PLANNED, true),
        ];

        $canManageChecklist = $this->canManageChecklist($backendUser, $workspaceUid);
        foreach ($this->getStages($backendUser, $workspaceUid) as $stageUid) {
            $checklistItems = array_map(
                static fn (array $item): array => ['uid' => (int)$item['uid'], 'title' => (string)$item['title']],
                $this->checklistRepository->findItemsForStage($workspaceUid, $stageUid),
            );
            $columns[] = [
                'key' => 'stage-' . $stageUid,
                'label' => $this->stagesService->getStageTitle($stageUid),
                'state' => TaskState::fromStageId($stageUid)->value,
                'stageUid' => $stageUid,
                // Dropping here triggers a core stage transition, never a direct write.
                'acceptsDrop' => true,
                // Configuring a stage's checklist is workspace policy, not
                // editorial work - restricted to whoever owns the workspace
                // (or admin), same as publishing.
                'canManageChecklist' => $canManageChecklist,
                // Pre-encoded: checklist.js's manage modal reads this straight
                // out of the column's dataset, and Fluid has no JSON format
                // ViewHelper in this version to do it in the template instead.
                'checklistItemsJson' => json_encode($checklistItems, JSON_THROW_ON_ERROR),
            ];
        }

        // Publishing is an explicit, irreversible action - it is deliberately not a
        // drop target. Editors publish from the card, with a confirmation.
        $columns[] = $this->column('done', TaskState::DONE, false);

        // A task whose workspace_uid points at a workspace other than the active
        // one never matches a stage column above (stage uids are per-workspace) and
        // never matches a Content Flow state column either (those require
        // workspace_uid === 0) - it would silently vanish from the board once scope
        // widens enough to reach it. This sentinel column is where
        // ContentFlowController::belongsInColumn() routes it instead: visible,
        // badged, read-only. Matched by 'key', not by state/stageUid, since those
        // two are already spoken for by the distinction above.
        $columns[] = [
            'key' => 'other-workspaces',
            'label' => $this->getLanguageService()->sL(
                'LLL:EXT:content_flow/Resources/Private/Language/locallang.xlf:column.other_workspaces'
            ) ?: 'Other workspaces',
            'state' => 'other_workspace',
            'stageUid' => null,
            'acceptsDrop' => false,
        ];

        return $columns;
    }

    /**
     * Whether the current user may add or remove checklist items for stages in
     * this workspace - workspace policy, not editorial work, so restricted to
     * whoever owns the workspace (or admin), same as publishing.
     */
    private function canManageChecklist(BackendUserAuthentication $backendUser, int $workspaceUid): bool
    {
        if ($backendUser->isAdmin()) {
            return true;
        }
        if ($workspaceUid < 1) {
            return false;
        }
        $access = $backendUser->checkWorkspace($workspaceUid);
        return is_array($access) && ($access['_ACCESS'] ?? '') === 'owner';
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
     * A Content Flow-owned column. These never map to a core stage - that is what
     * `stageUid => null` means, and it is how the board tells the two apart.
     *
     * @return array{key: string, label: string, state: string, stageUid: null, acceptsDrop: bool}
     */
    private function column(string $key, TaskState $state, bool $acceptsDrop): array
    {
        return [
            'key' => $key,
            'label' => $this->getLanguageService()->sL(
                'LLL:EXT:content_flow/Resources/Private/Language/locallang.xlf:column.' . $key
            ) ?: ucfirst($key),
            'state' => $state->value,
            'stageUid' => null,
            'acceptsDrop' => $acceptsDrop,
        ];
    }

    private function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}
