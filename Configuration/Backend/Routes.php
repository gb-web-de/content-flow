<?php

declare(strict_types=1);

use GbWeb\EditorialFlow\Controller\TaskAjaxController;

return [
    'editorialflow_record_creation_return' => [
        'path' => '/editorialflow/record-creation-return',
        'methods' => ['GET'],
        'target' => TaskAjaxController::class . '::recordCreationReturnAction',
    ],
];
