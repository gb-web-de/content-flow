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
    // Column drop / move stage: update task state or stage.
    'contentflow_task_move_stage' => [
        'path' => '/contentflow/task/move-stage',
        'target' => TaskAjaxController::class . '::moveStageAction',
    ],
    // Self assign: assign task to current backend user.
    'contentflow_task_assign_me' => [
        'path' => '/contentflow/task/assign-me',
        'target' => TaskAjaxController::class . '::assignMeAction',
    ],
    // Fetch full task inspector details (diffs, comments, activities).
    'contentflow_task_details' => [
        'path' => '/contentflow/task/details',
        'target' => TaskAjaxController::class . '::detailsAction',
    ],
    // Post a standalone comment on a task.
    'contentflow_task_comment' => [
        'path' => '/contentflow/task/comment',
        'target' => TaskAjaxController::class . '::commentAction',
    ],
    // Ticket view: the full task rendered as HTML for a modal.
    'contentflow_task_ticket' => [
        'path' => '/contentflow/task/ticket',
        'target' => TaskAjaxController::class . '::ticketAction',
    ],
    // Workspace stage transition execution with comments and recipients.
    'contentflow_task_execute_stage' => [
        'path' => '/contentflow/task/execute-stage',
        'target' => TaskAjaxController::class . '::executeStageAction',
    ],
    // Publish everything a task still has pending, straight to live.
    'contentflow_task_publish' => [
        'path' => '/contentflow/task/publish',
        'target' => TaskAjaxController::class . '::publishTaskAction',
    ],
    // Post-Save Task Routing Wizard session check and submission.
    'contentflow_task_wizard_pending' => [
        'path' => '/contentflow/task/wizard-pending',
        'target' => TaskAjaxController::class . '::getPendingWizardAction',
    ],
    'contentflow_task_wizard_submit' => [
        'path' => '/contentflow/task/wizard-submit',
        'target' => TaskAjaxController::class . '::wizardSubmitAction',
    ],
];
