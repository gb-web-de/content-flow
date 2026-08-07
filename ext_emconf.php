<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Content Flow',
    'description' => 'Editorial Kanban board unifying xima/xima-typo3-content-planner statuses with TYPO3 workspace stages.',
    'category' => 'module',
    'author' => 'Gordon Brueggemann',
    'author_email' => 'gordon.brueggemann@gb-web.de',
    'author_company' => 'gb-web',
    'state' => 'alpha',
    'version' => '0.0.1',
    'constraints' => [
        'depends' => [
            'typo3' => '14.3.0-14.99.99',
            'workspaces' => '14.3.0-14.99.99',
            'xima_typo3_content_planner' => '2.2.0-2.99.99',
            'php' => '8.2.0-8.5.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
    'autoload' => [
        'psr-4' => [
            'GbWeb\\ContentFlow\\' => 'Classes/',
        ],
    ],
];
