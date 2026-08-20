<?php

declare(strict_types=1);

namespace GbWeb\ContentFlow\Tests\Functional\Controller;

use GbWeb\ContentFlow\Controller\TaskAjaxController;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * "Compare versions": the workspace-vs-workspace diff behind every conflict
 * badge. Keyed by table+uid, not by task - a conflicted record may belong to
 * no task at all on one side (see WorkspaceConflictDetector's docblock and
 * WorkspaceConflictDetectorTest), so this exercises the endpoint the same way,
 * independent of task bookkeeping.
 */
final class ConflictDiffActionTest extends FunctionalTestCase
{
    /**
     * @var string[]
     */
    protected array $coreExtensionsToLoad = [
        'typo3/cms-workspaces',
        'typo3/cms-dashboard',
    ];

    /**
     * @var string[]
     */
    protected array $testExtensionsToLoad = [
        'gb-web/content-flow',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($GLOBALS['BE_USER']);

        $connection = $this->getConnectionPool()->getConnectionForTable('sys_workspace');
        $connection->insert('sys_workspace', ['uid' => 2, 'pid' => 0, 'title' => 'Legal', 'deleted' => 0]);
    }

    private function subject(): TaskAjaxController
    {
        return $this->get(TaskAjaxController::class);
    }

    private function getRequest(array $query): ServerRequestInterface
    {
        // conflictDiffAction() renders a Fluid view with f:translate, which
        // needs ApplicationType::fromRequest() to resolve - the real backend
        // entry point sets this via middleware, a bare ServerRequest() does not.
        return (new ServerRequest())
            ->withQueryParams($query)
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
    }

    private function editInWorkspace(string $table, int $uid, array $fields, int $workspaceUid): void
    {
        $GLOBALS['BE_USER']->setWorkspace($workspaceUid);

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([$table => [$uid => $fields]], []);
        $dataHandler->process_datamap();
    }

    #[Test]
    public function comparesBothWorkspacesAndFlagsAFieldTheyBothChangedDifferently(): void
    {
        // Both sides touch 'title', to different values - a genuine conflict.
        // Only workspace 1 touches 'nav_title' - informational, not a conflict.
        $this->editInWorkspace('pages', 2, ['title' => 'About us (Editorial)', 'nav_title' => 'Editorial nav'], 1);
        $this->editInWorkspace('pages', 2, ['title' => 'About us (Legal)'], 2);

        $response = $this->subject()->conflictDiffAction($this->getRequest(['table' => 'pages', 'uid' => 2]));
        $body = (string)$response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Editorial', $body, 'workspace 1\'s title must appear as a column header');
        self::assertStringContainsString('Legal', $body, 'workspace 2\'s title must appear as a column header');
        self::assertStringContainsString('contentflow-conflict-diff-row--conflict', $body, 'the title row is a true conflict - both sides disagree');
        self::assertStringContainsString('contentflow-conflict-diff-unchanged', $body, 'nav_title was only touched on one side, so the other cell renders as unchanged');
    }

    #[Test]
    public function respondsWithAnInvalidRecordCalloutForAnUntrackableTable(): void
    {
        $response = $this->subject()->conflictDiffAction($this->getRequest(['table' => 'be_users', 'uid' => 1]));

        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('callout-danger', (string)$response->getBody());
    }

    #[Test]
    public function respondsWithANoConflictCalloutWhenOnlyOneWorkspaceHasAPendingVersion(): void
    {
        $this->editInWorkspace('pages', 2, ['title' => 'About us (Editorial)'], 1);

        $response = $this->subject()->conflictDiffAction($this->getRequest(['table' => 'pages', 'uid' => 2]));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('callout-info', (string)$response->getBody());
    }
}
