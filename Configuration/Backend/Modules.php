<?php

declare(strict_types=1);

use GbWeb\ContentFlow\Controller\ContentFlowController;

/**
 * Backend module configuration for TYPO3 v14.
 */
return [
    'web_contentflow' => [
        'parent' => 'web',
        'position' => ['after' => 'web_info'],
        'inheritNavigationComponentFromMainModule' => true,
        'access' => 'user',
        'workspaces' => '*',
        'icon' => 'EXT:content_flow/Resources/Public/Icons/module-dualtone.svg',
        'path' => '/module/web/contentflow',
        'labels' => 'LLL:EXT:content_flow/Resources/Private/Language/locallang_mod.xlf',
        'extensionName' => 'ContentFlow',
        'controllerActions' => [
            ContentFlowController::class => [
                'index',
            ],
        ],
    ],
];
