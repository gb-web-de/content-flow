<?php

declare(strict_types=1);

namespace GbWeb\ContentFlow\Controller;

use GbWeb\ContentFlow\Domain\Repository\TaskRepository;
use GbWeb\ContentFlow\Service\BoardColumnRegistry;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

/**
 * The Content Flow board.
 *
 * Renders one column set (see BoardColumnRegistry) and distributes the page's open
 * tasks into it. Cards are grouped in PHP from a single query - the number of review
 * stages an integrator configures never changes the number of queries.
 */
#[AsController]
class ContentFlowController extends ActionController
{
    public function __construct(
        protected readonly ModuleTemplateFactory $moduleTemplateFactory,
        protected readonly PageRenderer $pageRenderer,
        protected readonly BoardColumnRegistry $boardColumnRegistry,
        protected readonly TaskRepository $taskRepository,
    ) {
    }

    public function indexAction(): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $backendUser = $this->getBackendUser();
        $pageUid = (int)($this->request->getQueryParams()['id'] ?? 0);
        $workspaceUid = (int)$backendUser->workspace;

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

        $moduleTemplate->assignMultiple([
            'pageUid' => $pageUid,
            'pageSelected' => $pageUid > 0,
            'workspaceUid' => $workspaceUid,
            'columns' => $this->buildBoard($backendUser, $workspaceUid, $pageUid),
        ]);

        $this->pageRenderer->addCssFile('EXT:content_flow/Resources/Public/Css/Styles.css');

        return $moduleTemplate->renderResponse('ContentFlow/Index');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildBoard(BackendUserAuthentication $backendUser, int $workspaceUid, int $pageUid): array
    {
        $columns = $this->boardColumnRegistry->getColumns($backendUser, $workspaceUid);
        if ($pageUid < 1) {
            return $columns;
        }

        // One query for the whole board; the cards are then handed to the column they
        // belong to. Building a new array (rather than mutating $columns in place)
        // keeps this a plain read - $column is a copy on every pass.
        $tasks = $this->taskRepository->findOpenForBoard($pageUid);

        $board = [];
        foreach ($columns as $column) {
            $column['cards'] = [];
            foreach ($tasks as $task) {
                if ($this->belongsInColumn($task, $column)) {
                    $column['cards'][] = $task;
                }
            }
            $board[] = $column;
        }

        return $board;
    }

    /**
     * A versioned task belongs to the column of its concrete stage; an unversioned
     * task belongs to the column of its state.
     *
     * @param array<string, mixed> $task
     * @param array<string, mixed> $column
     */
    private function belongsInColumn(array $task, array $column): bool
    {
        if ($column['stageUid'] !== null) {
            return (int)($task['workspace_uid'] ?? 0) > 0
                && (int)($task['stage_uid'] ?? 0) === $column['stageUid'];
        }
        return (int)($task['workspace_uid'] ?? 0) === 0
            && (string)($task['state'] ?? '') === $column['state'];
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
