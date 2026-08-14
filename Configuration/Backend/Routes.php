<?php

declare(strict_types=1);

use GbWeb\ContentFlow\Controller\TaskAjaxController;

return [
    'contentflow_record_creation_return' => [
        'path' => '/contentflow/record-creation-return',
        'methods' => ['GET'],
        'target' => TaskAjaxController::class . '::recordCreationReturnAction',
    ],
];
