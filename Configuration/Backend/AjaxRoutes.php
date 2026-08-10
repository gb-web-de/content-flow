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
    // "+" button, "Neue Seite erstellen": plan a page that does not exist yet.
    'contentflow_task_create_pending_page' => [
        'path' => '/contentflow/task/create-pending-page',
        'target' => TaskAjaxController::class . '::createPendingPageAction',
    ],
    // Page wizard closed without creating a page: drop the ticket's claim on it.
    'contentflow_task_cancel_page_wizard' => [
        'path' => '/contentflow/task/cancel-page-wizard',
        'target' => TaskAjaxController::class . '::cancelPageWizardAction',
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
    // Shareable preview link for one member's pending version.
    'contentflow_task_preview_member' => [
        'path' => '/contentflow/task/preview-member',
        'target' => TaskAjaxController::class . '::previewMemberAction',
    ],
    // Throw away one member's pending version, keeping its task membership.
    'contentflow_task_discard_member' => [
        'path' => '/contentflow/task/discard-member',
        'target' => TaskAjaxController::class . '::discardMemberAction',
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
    // Visual Editor task select: every open task touching a page.
    'contentflow_task_list_open_for_page' => [
        'path' => '/contentflow/task/list-open-for-page',
        'target' => TaskAjaxController::class . '::listOpenTasksForPageAction',
    ],
    // Visual Editor task select: declare the active task for a page before editing.
    'contentflow_task_set_active_for_page' => [
        'path' => '/contentflow/task/set-active-for-page',
        'target' => TaskAjaxController::class . '::setActiveTaskForPageAction',
    ],
    // Visual Editor hover markers: which task already claims which record on a page.
    'contentflow_task_list_member_markers' => [
        'path' => '/contentflow/task/list-member-markers',
        'target' => TaskAjaxController::class . '::listMemberTaskMarkersForPageAction',
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
    // Post-Save Task Routing Wizard session check - submission itself goes
    // through TYPO3 core's generic wizard_submit route (mode=contentflow_task_wizard),
    // see Classes/Wizard/TaskWizardProvider.php.
    'contentflow_task_wizard_pending' => [
        'path' => '/contentflow/task/wizard-pending',
        'target' => TaskAjaxController::class . '::getPendingWizardAction',
    ],
    // Review checklist: check/uncheck one item for one task.
    'contentflow_checklist_toggle' => [
        'path' => '/contentflow/checklist/toggle',
        'target' => TaskAjaxController::class . '::checklistToggleAction',
    ],
    // Review checklist: add an item to a stage's policy (workspace owner only).
    'contentflow_checklist_add' => [
        'path' => '/contentflow/checklist/add',
        'target' => TaskAjaxController::class . '::checklistAddAction',
    ],
    // Review checklist: remove an item from a stage's policy (workspace owner only).
    'contentflow_checklist_remove' => [
        'path' => '/contentflow/checklist/remove',
        'target' => TaskAjaxController::class . '::checklistRemoveAction',
    ],
];
