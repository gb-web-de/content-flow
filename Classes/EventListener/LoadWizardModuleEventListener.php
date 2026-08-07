<?php

declare(strict_types=1);

namespace GbWeb\ContentFlow\EventListener;

use TYPO3\CMS\Backend\Controller\Event\AfterBackendPageRenderEvent;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Page\PageRenderer;

/**
 * Loads the post-save routing wizard everywhere in the backend.
 *
 * `Configuration/JavaScriptModules.php` only makes a module importable; nothing
 * reads a key like `includeInModules` to decide when to load one automatically -
 * that key existed in an earlier version of this extension and did nothing.
 *
 * There is no config-driven "always load this module" mechanism at all. The
 * correct place is AfterBackendPageRenderEvent, dispatched once by
 * BackendController for the outer backend chrome that every module loads into.
 * EXT:workspaces uses exactly this event for the same reason (loading
 * workspace-state.js everywhere) - copied from
 * TYPO3\CMS\Workspaces\EventListener\AfterBackendPageRenderEventListener.
 *
 * This is what makes the wizard reachable regardless of where an editor saves a
 * content element from - the Page module, the List module, or a direct
 * record_edit link - rather than only the two places that called
 * loadJavaScriptModule() by hand before (the board module and the page banner).
 */
final readonly class LoadWizardModuleEventListener
{
    public function __construct(
        private PageRenderer $pageRenderer,
    ) {
    }

    #[AsEventListener(event: AfterBackendPageRenderEvent::class)]
    public function __invoke(): void
    {
        $this->pageRenderer->loadJavaScriptModule('@gb-web/content-flow/wizard.js');
    }
}
