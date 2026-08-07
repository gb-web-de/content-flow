<?php

declare(strict_types=1);

namespace GbWeb\ContentFlow\Tests\Functional;

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
        'gb-web/content-flow',
    ];

    #[Test]
    public function extensionIsLoaded(): void
    {
        self::assertTrue(ExtensionManagementUtility::isLoaded('content_flow'));
    }

    #[Test]
    public function taskTableExists(): void
    {
        $schemaManager = $this->getConnectionPool()
            ->getConnectionForTable('tx_contentflow_task')
            ->createSchemaManager();

        self::assertTrue($schemaManager->tablesExist(['tx_contentflow_task']));
    }
}
