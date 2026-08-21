<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Tests\Functional\Service;

use GbWeb\EditorialFlow\Service\BoardScopeResolver;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Page tree: 1 "Home" (root) -> 2 "About us" (child) - see Fixtures/pages.csv.
 */
final class BoardScopeResolverTest extends FunctionalTestCase
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
        'gb-web/editorial-flow',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->setUpBackendUser(1);
    }

    private function subject(): BoardScopeResolver
    {
        return new BoardScopeResolver($this->getConnectionPool());
    }

    #[Test]
    public function resolvePageUidsWithZeroDepthReturnsOnlyTheSelectedPage(): void
    {
        self::assertSame([1], $this->subject()->resolvePageUids(1, 0, $GLOBALS['BE_USER']));
    }

    #[Test]
    public function resolvePageUidsWithDepthIncludesSubpages(): void
    {
        self::assertEqualsCanonicalizing(
            [1, 2],
            $this->subject()->resolvePageUids(1, 999, $GLOBALS['BE_USER']),
        );
    }

    #[Test]
    public function resolvePageUidsForAnInvalidPageReturnsNothing(): void
    {
        self::assertSame([], $this->subject()->resolvePageUids(0, 0, $GLOBALS['BE_USER']));
    }

    #[Test]
    public function resolveWorkspaceRootPageUidsFallsBackToPidZeroPagesWhenNoMountpointsAreConfigured(): void
    {
        // Fixture workspace 1 "Editorial" has no db_mountpoints set - the common
        // case, per BoardScopeResolver's own docblock. Falls back to page 1
        // "Home" (pid=0) and its subtree, rather than surfacing nothing.
        self::assertEqualsCanonicalizing(
            [1, 2],
            $this->subject()->resolveWorkspaceRootPageUids(1, $GLOBALS['BE_USER']),
        );
    }

    #[Test]
    public function resolveWorkspaceRootPageUidsReturnsNothingForAnInvalidWorkspace(): void
    {
        self::assertSame([], $this->subject()->resolveWorkspaceRootPageUids(0, $GLOBALS['BE_USER']));
    }

    #[Test]
    public function resolveWorkspaceRootPageUidsExpandsEachConfiguredMountpointsSubtree(): void
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('sys_workspace');
        $connection->insert('sys_workspace', [
            'title' => 'Marketing',
            'db_mountpoints' => '1',
        ]);
        $workspaceUid = (int)$connection->lastInsertId();

        self::assertEqualsCanonicalizing(
            [1, 2],
            $this->subject()->resolveWorkspaceRootPageUids($workspaceUid, $GLOBALS['BE_USER']),
        );
    }
}
