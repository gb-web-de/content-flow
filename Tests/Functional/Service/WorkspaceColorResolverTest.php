<?php

declare(strict_types=1);

namespace GbWeb\ContentFlow\Tests\Functional\Service;

use GbWeb\ContentFlow\Service\WorkspaceColorResolver;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class WorkspaceColorResolverTest extends FunctionalTestCase
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

    private function subject(): WorkspaceColorResolver
    {
        return $this->get(WorkspaceColorResolver::class);
    }

    #[Test]
    public function resolvesTheWorkspacesOwnCoreColor(): void
    {
        $this->getConnectionPool()->getConnectionForTable('sys_workspace')->insert('sys_workspace', [
            'uid' => 1,
            'pid' => 0,
            'title' => 'Legal',
            'color' => 'teal',
            'deleted' => 0,
        ]);

        self::assertSame('teal', $this->subject()->resolve(1));
    }

    #[Test]
    public function fallsBackToADeterministicCoreColorForAMissingWorkspace(): void
    {
        // No sys_workspace row at all for uid 404 - BackendUtility::getRecord()
        // returns null, same as a deleted workspace still selected by a stale
        // be_user session.
        $color = $this->subject()->resolve(404);

        self::assertContains($color, ['orange', 'yellow', 'lime', 'green', 'teal', 'blue', 'indigo', 'purple', 'magenta']);
        // Deterministic: the same missing uid always resolves to the same color.
        self::assertSame($color, $this->subject()->resolve(404));
    }

    #[Test]
    public function fallsBackForAnUnrecognizedColorValue(): void
    {
        $this->getConnectionPool()->getConnectionForTable('sys_workspace')->insert('sys_workspace', [
            'uid' => 1,
            'pid' => 0,
            'title' => 'Legal',
            'color' => 'not-a-real-color',
            'deleted' => 0,
        ]);

        self::assertNotSame('not-a-real-color', $this->subject()->resolve(1));
    }
}
