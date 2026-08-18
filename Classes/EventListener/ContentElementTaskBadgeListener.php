<?php

declare(strict_types=1);

namespace GbWeb\ContentFlow\EventListener;

use GbWeb\ContentFlow\Domain\Repository\TaskRepository;
use GbWeb\ContentFlow\Service\ActiveTaskSession;
use GbWeb\ContentFlow\Service\TaskColor;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Backend\View\Event\AfterPageContentPreviewRenderedEvent;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Domain\RecordInterface;

/**
 * Marks a content element in the Page module when a task already claims it.
 *
 * The Visual Editor tells an editor this with a coloured bubble; the Page
 * module said nothing at all, so the same element looked free there. Same
 * question, same answer, same colour (TaskColor::hueFor()) - a task is "the
 * green one" on both surfaces.
 *
 * AfterPageContentPreviewRenderedEvent rather than a custom preview renderer:
 * this has no opinion about how a content element previews itself, it only adds
 * a line to whatever the element (or another extension) already rendered. A
 * preview renderer would have to reimplement every element type to say one
 * thing about it.
 */
final class ContentElementTaskBadgeListener
{
    /**
     * Claims per page, built once per request rather than per element: a page
     * module render walks every element on the page, and asking the database
     * for each one would turn one query into dozens.
     *
     * @var array<int, array<string, array{title: string, hue: float, isActive: bool, isSubject: bool}>>
     */
    private array $claimsByPage = [];

    public function __construct(
        private readonly TaskRepository $taskRepository,
        private readonly ActiveTaskSession $activeTaskSession,
    ) {
    }

    #[AsEventListener(identifier: 'content-flow/content-element-task-badge')]
    public function __invoke(AfterPageContentPreviewRenderedEvent $event): void
    {
        $record = $event->getRecord();
        $pageUid = $record->getPid();
        if ($pageUid < 1) {
            return;
        }

        $claims = $this->claimsFor($pageUid);
        // The membership row holds the live uid, and so does a record read for
        // the page module - unlike the frontend, which the Visual Editor renders
        // workspace-overlaid (see TaskAjaxController::memberIdentifiers()).
        $table = $event->getTable();
        $recordUid = $record->getUid();
        $claim = $claims[$table . ':' . $recordUid] ?? null;
        if ($claim === null) {
            return;
        }

        $event->setPreviewContent(
            $this->renderBadge($claim, $table, $recordUid, $this->titleOf($table, $record))
                . $event->getPreviewContent(),
        );
    }

    /**
     * @param array{title: string, hue: float, isActive: bool, isSubject: bool} $claim
     */
    private function renderBadge(array $claim, string $table, int $recordUid, string $recordTitle): string
    {
        // Never colour alone: the badge always carries the task's name, and the
        // active one says so in words rather than only through its ring.
        $label = htmlspecialchars($claim['title'], ENT_QUOTES | ENT_HTML5);
        $title = $claim['isActive']
            ? 'You picked this task in the Visual Editor - edits here go to it'
            : 'This element already belongs to this task';

        return sprintf(
            '<div class="contentflow-element-badge%s" style="--contentflow-task-hue: %s" title="%s">'
                . '<span class="contentflow-task-dot"></span>%s%s</div>',
            $claim['isActive'] ? ' contentflow-element-badge--active' : '',
            (string)$claim['hue'],
            htmlspecialchars($title, ENT_QUOTES | ENT_HTML5),
            $label,
            $this->renderActions($claim, $table, $recordUid, $recordTitle),
        );
    }

    /**
     * Split and move, right where the editor already sees which task owns this
     * element. The buttons carry nothing but data attributes: the behaviour is
     * task/membership.js', delegated from the document this markup lands in -
     * board.js is already loaded in the Page module (PageModuleEventListener),
     * so this surface needs no entry point of its own.
     *
     * The task's own subject is left out for the same reason the ticket leaves
     * it out: TaskAjaxController::detachAction() refuses to split a task from
     * itself, and offering the button anyway would only produce that error.
     *
     * @param array{title: string, hue: float, isActive: bool, isSubject: bool} $claim
     */
    private function renderActions(array $claim, string $table, int $recordUid, string $recordTitle): string
    {
        $data = sprintf(
            'data-table="%s" data-uid="%d" data-title="%s"',
            htmlspecialchars($table, ENT_QUOTES | ENT_HTML5),
            $recordUid,
            htmlspecialchars($recordTitle, ENT_QUOTES | ENT_HTML5),
        );

        $buttons = '';
        if (!$claim['isSubject']) {
            $buttons .= sprintf(
                '<button type="button" class="contentflow-element-action" data-contentflow-split="1" %s>%s</button>',
                $data,
                htmlspecialchars($this->label('membership.split.button', 'Split off'), ENT_QUOTES | ENT_HTML5),
            );
        }
        $buttons .= sprintf(
            '<button type="button" class="contentflow-element-action" data-contentflow-move="1" %s>%s</button>',
            $data,
            htmlspecialchars($this->label('membership.move.button', 'Move to task'), ENT_QUOTES | ENT_HTML5),
        );

        return '<span class="contentflow-element-actions">' . $buttons . '</span>';
    }

    private function label(string $key, string $fallback): string
    {
        $label = $GLOBALS['LANG']?->sL(
            'LLL:EXT:content_flow/Resources/Private/Language/locallang.xlf:' . $key
        ) ?? '';

        return $label !== '' ? $label : $fallback;
    }

    /**
     * The element's own name, for the dialogs' "%s gets a task of its own".
     */
    private function titleOf(string $table, RecordInterface $record): string
    {
        $title = BackendUtility::getRecordTitle($table, $record->toArray());

        return $title !== '' ? $title : sprintf('%s:%d', $table, $record->getUid());
    }

    /**
     * @return array<string, array{title: string, hue: float, isActive: bool, isSubject: bool}>
     */
    private function claimsFor(int $pageUid): array
    {
        if (isset($this->claimsByPage[$pageUid])) {
            return $this->claimsByPage[$pageUid];
        }

        $activeTaskUid = ($this->activeTaskSession->current($this->getBackendUser()) ?? [])['taskUid'] ?? 0;

        $claims = [];
        foreach ($this->taskRepository->findAllOpenForPage($pageUid) as $task) {
            $taskUid = (int)$task['uid'];
            $entry = [
                'title' => (string)$task['title'],
                'hue' => TaskColor::hueFor($taskUid),
                'isActive' => $taskUid === $activeTaskUid,
                'isSubject' => false,
            ];
            foreach ($this->taskRepository->findMembers($taskUid) as $member) {
                $memberTable = (string)$member['record_table'];
                $memberUid = (int)$member['record_uid'];
                $claims[$memberTable . ':' . $memberUid] = [
                    'isSubject' => $memberTable === (string)$task['subject_table']
                        && $memberUid === (int)$task['subject_uid'],
                ] + $entry;
            }
        }

        return $this->claimsByPage[$pageUid] = $claims;
    }

    private function getBackendUser(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }
}
