<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\EventListener;

use GbWeb\EditorialFlow\Service\AssignableUserProvider;
use TYPO3\CMS\Backend\Controller\Event\AfterBackendPageRenderEvent;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Page\PageRenderer;

/**
 * Loads the post-save routing wizard, and this extension's own CSS, everywhere
 * in the backend.
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
 *
 * The CSS file belongs here for a related but distinct reason: TYPO3.Modal
 * always renders into this same outer chrome document, even when the button
 * that opened it lives inside a module's content iframe (the board does -
 * see board.js's openTicket()). EditorialFlowController::indexAction() only
 * calls addCssFile() on the IFRAME's own PageRenderer, so every board card is
 * styled correctly, but the ticket/comment/checklist modal content - injected
 * into THIS outer document - had no stylesheet of its own and silently
 * rendered with browser defaults (bullet points, unstyled buttons) no matter
 * how much CSS Styles.css contained. Loading it here, once, for the whole
 * chrome fixes that regardless of which module the modal was opened from.
 */
final readonly class LoadWizardModuleEventListener
{
    public function __construct(
        private PageRenderer $pageRenderer,
        private AssignableUserProvider $assignableUserProvider,
    ) {
    }

    #[AsEventListener(event: AfterBackendPageRenderEvent::class)]
    public function __invoke(): void
    {
        $this->pageRenderer->loadJavaScriptModule('@gb-web/editorial-flow/wizard.js');
        $this->pageRenderer->addCssFile('EXT:editorial_flow/Resources/Public/Css/Styles.css');
        // Populated here, not only in EditorialFlowController, for the same reason
        // the module itself is loaded here: the wizard's "assign to" field must
        // work from the Page module, the List module, or a direct record_edit
        // link, not only from the board.
        $this->pageRenderer->addInlineSetting('EditorialFlow', 'assignableUsers', $this->assignableUserProvider->getAssignableUsers());
    }
}
