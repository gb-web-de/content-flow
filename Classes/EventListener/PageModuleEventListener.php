<?php

declare(strict_types=1);

namespace GbWeb\ContentFlow\EventListener;

use GbWeb\ContentFlow\Domain\Repository\TaskRepository;
use GbWeb\ContentFlow\Service\TaskSubjectRegistry;
use TYPO3\CMS\Backend\Controller\Event\ModifyPageLayoutContentEvent;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\View\ViewFactoryData;
use TYPO3\CMS\Core\View\ViewFactoryInterface;

/**
 * Shows a page's task in the Page module header.
 *
 * "Meet editors where they already are" - the xima content-planner lesson. An
 * editor working on a page should see its task without opening the board.
 *
 * Three v14 API corrections are baked in here, all of which were fatal before:
 * the event is ModifyPageLayoutContentEvent (there is no
 * RenderAdditionalContentToPageModuleHeaderEvent), AsEventListener lives in
 * Core\Attribute (not Backend\Attribute), and StandaloneView no longer exists in
 * v14 - ViewFactoryInterface replaces it.
 */
final class PageModuleEventListener
{
    public function __construct(
        private readonly TaskRepository $taskRepository,
        private readonly ViewFactoryInterface $viewFactory,
        private readonly UriBuilder $uriBuilder,
        private readonly PageRenderer $pageRenderer,
        private readonly TaskSubjectRegistry $subjectRegistry,
    ) {
    }

    #[AsEventListener(identifier: 'content-flow/page-module-header')]
    public function __invoke(ModifyPageLayoutContentEvent $event): void
    {
        $pageUid = (int)($event->getRequest()->getQueryParams()['id'] ?? 0);
        if ($pageUid < 1) {
            return;
        }

        $task = $this->taskRepository->findOpenBySubject('pages', $pageUid);
        $pageRecord = BackendUtility::getRecord('pages', $pageUid) ?? [];
        $pageTitle = $pageRecord !== [] ? BackendUtility::getRecordTitle('pages', $pageRecord) : '';

        $this->pageRenderer->addCssFile('EXT:content_flow/Resources/Public/Css/Styles.css');
        $this->pageRenderer->loadJavaScriptModule('@gb-web/content-flow/board.js');
        $this->pageRenderer->addInlineSetting(
            'ContentFlow',
            'elementBrowserUrl',
            (string)$this->uriBuilder->buildUriFromRoute('wizard_element_browser'),
        );
        $this->pageRenderer->addInlineSetting(
            'ContentFlow',
            'createTargetTables',
            $this->getCreateTargetTables(),
        );
        $this->pageRenderer->addInlineSetting(
            'ContentFlow',
            'currentPageId',
            $pageUid,
        );

        $view = $this->viewFactory->create(new ViewFactoryData(
            templateRootPaths: ['EXT:content_flow/Resources/Private/Templates/'],
            partialRootPaths: ['EXT:content_flow/Resources/Private/Partials/'],
            layoutRootPaths: ['EXT:content_flow/Resources/Private/Layouts/'],
            request: $event->getRequest(),
        ));
        $view->assignMultiple([
            'pageUid' => $pageUid,
            'pageTitle' => $pageTitle,
            'task' => $task,
            'assigneeName' => $this->resolveAssigneeName($task),
            'boardUrl' => (string)$this->uriBuilder->buildUriFromRoute('web_contentflow', ['id' => $pageUid]),
        ]);

        $event->addHeaderContent($view->render('PageModule/Banner'));
    }

    /**
     * @return list<string>
     */
    private function getCreateTargetTables(): array
    {
        return array_values(array_unique(array_merge(
            $this->subjectRegistry->getSubjectTables(),
            $this->subjectRegistry->getAggregatableTables(),
        )));
    }

    /**
     * @param array<string, mixed>|null $task
     */
    private function resolveAssigneeName(?array $task): string
    {
        $assignee = (int)($task['assignee'] ?? 0);
        if ($assignee < 1) {
            return '';
        }
        $user = BackendUtility::getRecord('be_users', $assignee, 'username,realName');
        if ($user === null) {
            return '';
        }

        return trim((string)($user['realName'] ?? '')) !== ''
            ? (string)$user['realName']
            : (string)($user['username'] ?? '');
    }
}
