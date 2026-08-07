<?php

declare(strict_types=1);

namespace GbWeb\ContentFlow\Controller;

use GbWeb\ContentFlow\Domain\Repository\StatusBoardRepository;
use GbWeb\ContentFlow\Service\BoardModeResolver;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

/**
 * Backend module controller for Content Flow.
 *
 * Renders one board UI backed by two possible data sources, decided by
 * BoardModeResolver: xima_typo3_content_planner statuses on Live, TYPO3
 * workspace stages once a non-Live workspace is active. See ARCHITECTURE.md.
 *
 * The DocHeader (title/breadcrumb/shortcut) is wired up directly here, unlike
 * kanban-workspaces' first version, which shipped a template that replaced the
 * core module header outright and broke page-tree navigation
 * (web-vision/kanban-workspaces#43) - avoid repeating that mistake.
 */
#[AsController]
class ContentFlowController extends ActionController
{
    public function __construct(
        protected readonly ModuleTemplateFactory $moduleTemplateFactory,
        protected readonly PageRenderer $pageRenderer,
        protected readonly BoardModeResolver $boardModeResolver,
        protected readonly StatusBoardRepository $statusBoardRepository,
    ) {
    }

    public function indexAction(): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $backendUser = $this->getBackendUser();
        $pageUid = (int)($this->request->getQueryParams()['id'] ?? 0);
        $pageRecord = $pageUid > 0 ? (BackendUtility::getRecord('pages', $pageUid) ?? []) : [];
        $pageTitle = $pageRecord !== [] ? BackendUtility::getRecordTitle('pages', $pageRecord) : '';

        $moduleTitle = $this->getLanguageService()->sL(
            'LLL:EXT:content_flow/Resources/Private/Language/locallang_mod.xlf:mlang_tabs_tab'
        );
        $moduleTemplate->setTitle($moduleTitle, $pageTitle);
        $docHeader = $moduleTemplate->getDocHeaderComponent();
        if ($pageRecord !== []) {
            $docHeader->setPageBreadcrumb($pageRecord);
        }
        $docHeader->setShortcutContext(
            'web_contentflow',
            sprintf('%s: %s [%d]', $moduleTitle, $pageTitle !== '' ? $pageTitle : '/', $pageUid),
            ['id' => $pageUid],
        );

        $mode = $this->boardModeResolver->resolve($backendUser);
        $columns = $mode === BoardModeResolver::MODE_STATUS
            ? $this->buildStatusColumns($pageUid)
            : [];

        $moduleTemplate->assignMultiple([
            'mode' => $mode,
            'pageUid' => $pageUid,
            'pageSelected' => $pageUid > 0,
            'columns' => $columns,
        ]);

        $this->pageRenderer->addCssFile('EXT:content_flow/Resources/Public/Css/Styles.css');

        return $moduleTemplate->renderResponse('ContentFlow/Index');
    }

    /**
     * @return list<array{id: int, title: string, icon: string, color: string, cards: list<array<string, mixed>>}>
     */
    private function buildStatusColumns(int $pageUid): array
    {
        if ($pageUid < 1) {
            return [];
        }
        $cardsByStatus = $this->statusBoardRepository->getCardsGroupedByStatus($pageUid);
        $columns = [];
        foreach ($this->statusBoardRepository->getStatusColumns() as $statusColumn) {
            $columns[] = [
                'id' => $statusColumn['id'],
                'title' => $statusColumn['title'],
                'icon' => $statusColumn['icon'],
                'color' => $statusColumn['color'],
                'cards' => $cardsByStatus[$statusColumn['id']] ?? [],
            ];
        }
        return $columns;
    }

    protected function getBackendUser(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }

    protected function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}
