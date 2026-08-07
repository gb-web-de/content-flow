<?php

declare(strict_types=1);

namespace GbWeb\ContentFlow\Service;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Workspaces\Service\WorkspaceService;

/**
 * Decides which data source drives the board columns.
 *
 * Content Flow has two column sources that share one board UI:
 * - "status": xima_typo3_content_planner's tx_ximatypo3contentplanner_domain_model_status
 *   records, applied to live pages/records via tx_ximatypo3contentplanner_status.
 *   Used on the Live workspace, where there is no versioning/stage workflow.
 * - "stage": TYPO3 workspace stages (as in web-vision/kanban-workspaces), used once
 *   the editor has switched into a non-Live workspace.
 *
 * @see /home/gordon/Projekte/content-flow/ARCHITECTURE.md
 */
class BoardModeResolver
{
    public const MODE_STATUS = 'status';
    public const MODE_STAGE = 'stage';

    public function resolve(BackendUserAuthentication $backendUser): string
    {
        return $backendUser->workspace !== WorkspaceService::LIVE_WORKSPACE_ID
            ? self::MODE_STAGE
            : self::MODE_STATUS;
    }
}
