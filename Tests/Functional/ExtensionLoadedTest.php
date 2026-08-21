<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Tests\Functional;

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
        'gb-web/editorial-flow',
    ];

    #[Test]
    public function extensionIsLoaded(): void
    {
        self::assertTrue(ExtensionManagementUtility::isLoaded('editorial_flow'));
    }

    #[Test]
    public function taskTableExists(): void
    {
        $schemaManager = $this->getConnectionPool()
            ->getConnectionForTable('tx_editorialflow_task')
            ->createSchemaManager();

        self::assertTrue($schemaManager->tablesExist(['tx_editorialflow_task']));
    }
}
