<?php

declare(strict_types=1);

namespace GbWeb\ContentFlow\Service;

use TYPO3\CMS\Backend\Utility\BackendUtility;

/**
 * A workspace's own accent color for the board's merged stage columns
 * (BoardColumnRegistry) - reusing `sys_workspace.color`, the same field core
 * itself already renders via `--typo3-state-{color}-*` design tokens in the
 * workspace selector and the top-bar indicator (EXT:workspaces). Riding on
 * that field means a workspace's board color is always the same color an
 * editor already knows it by elsewhere in the backend, and dark mode is
 * handled by those tokens rather than by this extension.
 *
 * The field is a required select with a default ('orange'), so it is never
 * actually empty - the fallback below exists for the row-missing/deleted
 * case and for any value core's own enum no longer recognizes.
 */
final class WorkspaceColorResolver
{
    /**
     * Exactly EXT:workspaces' Configuration/TCA/sys_workspace.php 'color'
     * column items, in that same order - keep in sync if core ever changes it.
     */
    private const CORE_COLORS = [
        'orange', 'yellow', 'lime', 'green', 'teal', 'blue', 'indigo', 'purple', 'magenta',
    ];

    public function resolve(int $workspaceUid): string
    {
        $record = BackendUtility::getRecord('sys_workspace', $workspaceUid, 'color');
        $color = (string)($record['color'] ?? '');

        return in_array($color, self::CORE_COLORS, true)
            ? $color
            : self::CORE_COLORS[abs($workspaceUid) % count(self::CORE_COLORS)];
    }
}
