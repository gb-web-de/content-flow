<?php

declare(strict_types=1);

use GbWeb\ContentFlow\Controller\TaskAjaxController;

/**
 * Backend ajax routes for the board's write operations.
 *
 * Registered as backend routes rather than hand-rolled endpoints so the routing
 * layer enforces the backend user session and the request token (CSRF) for us.
 */
return [
    // "+" button: create a task for a page picked in the wizard.
    'contentflow_task_create' => [
        'path' => '/contentflow/task/create',
        'target' => TaskAjaxController::class . '::createAction',
    ],
    // "Select to task": move selected records onto a task.
    'contentflow_task_attach' => [
        'path' => '/contentflow/task/attach',
        'target' => TaskAjaxController::class . '::attachAction',
    ],
    // "Split from task": pull a record into a task of its own.
    'contentflow_task_detach' => [
        'path' => '/contentflow/task/detach',
        'target' => TaskAjaxController::class . '::detachAction',
    ],
];
