<?php

declare(strict_types=1);

namespace GbWeb\ContentFlow\Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class ExtensionLoadedTest extends FunctionalTestCase
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
        'xima/xima-typo3-content-planner',
        'gb-web/content-flow',
    ];

    public static function loadedExtensionsDataSet(): \Generator
    {
        $packages = [
            'content_flow' => 'gb-web/content-flow',
            'xima_typo3_content_planner' => 'xima/xima-typo3-content-planner',
        ];
        foreach ($packages as $extensionKey => $packageName) {
            yield 'EXT:' . $extensionKey => ['identifier' => $extensionKey];
            yield $packageName => ['identifier' => $packageName];
        }
    }

    #[DataProvider('loadedExtensionsDataSet')]
    #[Test]
    public function isLoadedExtensionKey(string $identifier): void
    {
        self::assertTrue(ExtensionManagementUtility::isLoaded($identifier), $identifier);
    }
}
