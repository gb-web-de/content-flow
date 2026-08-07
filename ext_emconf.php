<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Content Flow',
    'description' => 'Editorial task board for TYPO3: a backlog in front of TYPO3 workspaces, with tasks that open themselves when editors start working.',
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
