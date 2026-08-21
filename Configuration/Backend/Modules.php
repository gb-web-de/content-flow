<?php

declare(strict_types=1);

use GbWeb\EditorialFlow\Controller\EditorialFlowController;

/**
 * Backend module configuration for TYPO3 v14.
 */
return [
    'web_editorialflow' => [
        'parent' => 'content',
        'position' => ['after' => 'records'],
        'navigationComponent' => '@typo3/backend/tree/page-tree-element',
        'access' => 'user',
        'workspaces' => '*',
        'icon' => 'EXT:editorial_flow/Resources/Public/Icons/module-dualtone.svg',
        'path' => '/module/web/editorialflow',
        'labels' => 'LLL:EXT:editorial_flow/Resources/Private/Language/locallang_mod.xlf',
        'extensionName' => 'EditorialFlow',
        'controllerActions' => [
            EditorialFlowController::class => [
                'index',
            ],
        ],
    ],
];
