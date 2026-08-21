<?php

declare(strict_types=1);

/**
 * Only `imports` and `dependencies` are read here - see
 * TYPO3\CMS\Core\Page\ImportMap::loadConfiguration(). An `includeInModules` key
 * was added at one point and did nothing at all, because nothing in core looks
 * for it: a module is loaded by calling PageRenderer::loadJavaScriptModule(),
 * which the board controller and the page-module listener both do.
 */
return [
    'dependencies' => ['backend'],
    'imports' => [
        '@gb-web/editorial-flow/' => 'EXT:editorial_flow/Resources/Public/JavaScript/',
    ],
];
