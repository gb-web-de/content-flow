<?php

declare(strict_types=1);

namespace GbWeb\ContentFlow\Domain\Model;

use TYPO3\CMS\Workspaces\Service\StagesService;

/**
 * The lifecycle of a Content Flow task.
 *
 * Content Flow deliberately owns only the states that exist *outside* a workspace
 * version's lifetime. Everything between IN_PROGRESS and READY is owned by the TYPO3
 * core workspace stage engine (`sys_workspace_stage`) - Content Flow does not
 * reimplement approval steps, notifications or recipients, it renders them.
 *
 *   BACKLOG   own state    task exists, nothing versioned yet
 *   PLANNED   own state    assigned to a be_user, still nothing versioned
 *   IN_PROGRESS  stage 0   a workspace version exists and is being edited
 *   REVIEW    stage >= 1   custom approval stages, freely definable per workspace
 *   READY     stage -10    core "ready to publish"
 *   DONE      own state    version published, task closed
 *
 * @see \GbWeb\ContentFlow\Service\BoardColumnRegistry for how these become columns
 */
enum TaskState: string
{
    case BACKLOG = 'backlog';
    case PLANNED = 'planned';
    case IN_PROGRESS = 'in_progress';
    case REVIEW = 'review';
    case READY = 'ready';
    case DONE = 'done';

    /**
     * States that exist without a workspace version backing them. These are the only
     * ones Content Flow may move a task into on its own authority; every other
     * transition has to go through the core stage engine.
     */
    public function isOwnedByContentFlow(): bool
    {
        return match ($this) {
            self::BACKLOG, self::PLANNED, self::DONE => true,
            self::IN_PROGRESS, self::REVIEW, self::READY => false,
        };
    }

    /**
     * A task in these states has a workspace version and therefore a `t3ver_stage`.
     */
    public function hasVersion(): bool
    {
        return !$this->isOwnedByContentFlow();
    }

    /**
     * Map a core workspace stage id onto the state it represents.
     * Custom stages (uid >= 1) all map to REVIEW - the concrete column is then
     * identified by the stage uid, not by this enum.
     */
    public static function fromStageId(int $stageId): self
    {
        return match (true) {
            $stageId === StagesService::STAGE_EDIT_ID => self::IN_PROGRESS,
            $stageId === StagesService::STAGE_PUBLISH_ID => self::READY,
            $stageId >= 1 => self::REVIEW,
            default => self::IN_PROGRESS,
        };
    }
}
