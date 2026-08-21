<?php

declare(strict_types=1);

defined('TYPO3') or die();

// Creates or advances a task when an editor edits a record inside a workspace.
//
// A DataHandler hook, not a PSR-14 listener: TYPO3 core dispatches no event for
// "a workspace version was created". An attempt to replace this with a listener on
// PostProcessDatabaseOperationsEvent was reverted - that event does not exist, and
// removing the registration silently switched auto-creation off entirely.
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass'][] =
    \GbWeb\EditorialFlow\Hooks\TaskAutoCreationDataHandlerHook::class;

// The same hook again for the other half of DataHandler. Deleting or moving a
// record in a workspace is a pending change that has to be published, but core
// routes those through the cmdmap, which the registration above never sees - a
// page whose only change was a deletion therefore got no task and no card.
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processCmdmapClass'][] =
    \GbWeb\EditorialFlow\Hooks\TaskAutoCreationDataHandlerHook::class;

// Page-like tables: records that stand on their own and get their own card,
// instead of joining the task of the page they sit on. `pages` is implicit.
//
// The motivating case is news: technically a record, but it has its own detail
// page and therefore reads as a page to an editor. Only the integrator knows
// which of their tables work that way, so this is configuration - other
// extensions may append to it from their own ext_localconf.php.
$GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['editorial_flow']['subjectTables'] ??= [];

// How urgently a card's due date is flagged on the board (see
// EditorialFlowController::dueDateUrgency() and the Styles.css rules it feeds
// via injected --editorialflow-due-* custom properties). An integrator can
// override any of these from their own ext_localconf.php; these are only the
// defaults if they don't.
$GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['editorial_flow']['dueDateThresholds'] ??= [
    // A card starts showing as "due soon" this many days before its due date.
    'warningDays' => 3,
    'warningColor' => '#e0a810',
    'overdueColor' => '#d9534f',
];
