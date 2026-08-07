<?php

defined('TYPO3') or die();

// Auto-creates / advances a task when an editor edits a record inside a workspace.
// A hook rather than a PSR-14 listener because TYPO3 core dispatches no event for
// "a workspace version was created" - see the class docblock.
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass'][] =
    \GbWeb\ContentFlow\Hooks\TaskAutoCreationDataHandlerHook::class;
