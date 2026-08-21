<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\EventListener;

use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\Components\ComponentFactory;
use TYPO3\CMS\Backend\Template\Components\ModifyButtonBarEvent;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Page\PageRenderer;

/** Adds the active-task selector to every backend module document header. */
final readonly class ActiveTaskButtonBarEventListener
{
    public function __construct(
        private ComponentFactory $componentFactory,
        private PageRenderer $pageRenderer,
    ) {
    }

    #[AsEventListener(identifier: 'editorial-flow/active-task-button-bar')]
    public function __invoke(ModifyButtonBarEvent $event): void
    {
        [$table, $uid] = $this->recordContext($event->getRequest()->getQueryParams());

        $control = sprintf(
            '<span class="editorialflow-active-control" data-editorialflow-active-control'
                . ' data-editorialflow-context-table="%s" data-editorialflow-context-uid="%d"'
                . ' aria-live="polite"></span>',
            htmlspecialchars($table, ENT_QUOTES | ENT_HTML5),
            $uid,
        );
        $button = $this->componentFactory->createFullyRenderedButton()->setHtmlSource($control);

        $buttons = $event->getButtons();
        $buttons[ButtonBar::BUTTON_POSITION_RIGHT][15][] = $button;
        $event->setButtons($buttons);

        $this->pageRenderer->loadJavaScriptModule('@gb-web/editorial-flow/task/active-task-control.js');
        $this->pageRenderer->addCssFile('EXT:editorial_flow/Resources/Public/Css/Styles.css');
    }

    /**
     * @param array<string, mixed> $query
     * @return array{0: string, 1: int}
     */
    private function recordContext(array $query): array
    {
        $records = [];
        foreach ((array)($query['edit'] ?? []) as $table => $commands) {
            if (!is_string($table) || !is_array($commands)) {
                continue;
            }
            foreach ($commands as $uid => $command) {
                if ((string)$command === 'edit' && (int)$uid > 0) {
                    $records[] = [$table, (int)$uid];
                }
            }
        }

        return count($records) === 1 ? $records[0] : ['', 0];
    }
}
