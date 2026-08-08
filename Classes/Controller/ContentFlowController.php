<?php

declare(strict_types=1);

namespace GbWeb\ContentFlow\Controller;

use GbWeb\ContentFlow\Domain\Repository\TaskRepository;
use GbWeb\ContentFlow\Service\BoardColumnRegistry;
use GbWeb\ContentFlow\Service\TaskSubjectRegistry;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Workspaces\Authorization\WorkspacePublishGate;

/**
 * The Content Flow board.
 *
 * Renders one column set (see BoardColumnRegistry) and distributes the page's open
 * tasks into it. Cards are grouped in PHP from a single query - the number of review
 * stages an integrator configures never changes the number of queries.
 */
#[AsController]
final class ContentFlowController extends ActionController
{
    public function __construct(
        protected readonly ModuleTemplateFactory $moduleTemplateFactory,
        protected readonly PageRenderer $pageRenderer,
        protected readonly BoardColumnRegistry $boardColumnRegistry,
        protected readonly TaskRepository $taskRepository,
        protected readonly TaskSubjectRegistry $subjectRegistry,
        protected readonly UriBuilder $backendUriBuilder,
        protected readonly WorkspacePublishGate $workspacePublishGate,
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
        $this->pageRenderer->loadJavaScriptModule('@gb-web/content-flow/board.js');
        // Core's element browser gives the "+" button a page tree with live search
        // and depth navigation - no bespoke picker needed.
        //
        // Plain route URL only: the parameters are appended client-side the way
        // core's FormEngine.openPopupWindow() builds them (mode/allowedTypes/
        // useEvents as query params). The old pipe-delimited `bparams` string is
        // deprecated in v14 and silently produced a browser that selected nothing.
        $this->pageRenderer->addInlineSetting(
            'ContentFlow',
            'elementBrowserUrl',
            (string)$this->backendUriBuilder->buildUriFromRoute('wizard_element_browser'),
        );
        $this->pageRenderer->addInlineSetting(
            'ContentFlow',
            'currentUserId',
            (int)($backendUser->user['uid'] ?? 0),
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
        // One flag for the whole board, not per card: a backend user is in
        // exactly one workspace at a time, and WorkspacePublishGate::isGranted()
        // returns true unconditionally for the live workspace (uid 0) - matching
        // that a task can only ever hold a real pending version once its own
        // workspace_uid is set to this same current workspace.
        $this->pageRenderer->addInlineSetting(
            'ContentFlow',
            'canPublish',
            $workspaceUid > 0 && $this->workspacePublishGate->isGranted($backendUser, $workspaceUid),
        );

        return $moduleTemplate->renderResponse('ContentFlow/Index');
    }

    /**
     * Records the explicit "+" flow may promote into their own task.
     *
     * Page-like tables are obvious candidates. Page-bound content and custom
     * records are included too: they auto-join their page's task by default, but
     * an editor can still choose them deliberately and give them a dedicated card.
     *
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
     * @return list<array<string, mixed>>
     */
    private function buildBoard(BackendUserAuthentication $backendUser, int $workspaceUid, int $pageUid): array
    {
        $columns = $this->boardColumnRegistry->getColumns($backendUser, $workspaceUid);
        if ($pageUid < 1) {
            return $columns;
        }

        $tasks = $this->taskRepository->findOpenForBoard($pageUid);
        $enrichedTasks = array_map(function (array $task) use ($backendUser): array {
            $table = (string)($task['subject_table'] ?? 'pages');
            $uid = (int)($task['subject_uid'] ?? 0);
            $task['iconIdentifier'] = $table === 'pages' ? 'apps-pagetree-page-default' : 'mimetypes-x-content-text';

            $assigneeUid = (int)($task['assignee'] ?? 0);
            if ($assigneeUid > 0) {
                $userRecord = BackendUtility::getRecord('be_users', $assigneeUid, 'username,realName');
                if ($userRecord) {
                    $task['assigneeName'] = !empty($userRecord['realName']) ? $userRecord['realName'] : $userRecord['username'];
                }
            }

            $task['warnedMembers'] = $this->taskRepository->findWarnedMembers((int)$task['uid'], (int)$task['subject_pid']);
            $task['warnedCount'] = count($task['warnedMembers']);
            // Gates dragging the card at all (board.js checks the DRAGGED card's
            // own canAct, not the drop target's). Mirrors the real rule behind
            // every stage transition, BackendUserAuthentication::
            // workspaceCheckStageForCurrent(): responsibility is for what
            // currently SITS in a stage, never for what may move into one - see
            // WORKSPACE-STAGES.md. Correct unmodified for stage_uid 0 (always
            // allowed) and for tasks with no workspace version yet (also 0).
            //
            // Who counts as "responsible" is entirely core's own, existing
            // configuration - sys_workspace_stage.responsible_persons, edited on
            // the stage record in the Workspaces module like any other stage
            // setting. It already covers every case this could be asked to
            // support: `be_users_<uid>` for one person, `be_groups_<uid>` for a
            // team, several of either combined, or a group with every editor in
            // it for "anyone may act here". Content Flow deliberately adds no
            // parallel permission model of its own on top of it.
            $task['canAct'] = $backendUser->workspaceCheckStageForCurrent((int)($task['stage_uid'] ?? 0));
            return $task;
        }, $tasks);

        $board = [];
        foreach ($columns as $column) {
            $column['cards'] = [];
            foreach ($enrichedTasks as $task) {
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
