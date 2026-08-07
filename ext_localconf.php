<?php

declare(strict_types=1);

defined('TYPO3') or die();

// Creates or advances a task when an editor edits a record inside a workspace.
// A hook rather than a PSR-14 listener because TYPO3 core dispatches no event for
// "a workspace version was created" - see the class docblock.
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass'][] =
    \GbWeb\ContentFlow\Hooks\TaskAutoCreationDataHandlerHook::class;

// Page-like tables: records that stand on their own and get their own card,
// instead of joining the task of the page they sit on. `pages` is implicit.
//
// The motivating case is news: technically a record, but it has its own detail
// page and therefore reads as a page to an editor. Only the integrator knows
// which of their tables work that way, so this is configuration - other
// extensions may append to it from their own ext_localconf.php.
$GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['content_flow']['subjectTables'] ??= [];
