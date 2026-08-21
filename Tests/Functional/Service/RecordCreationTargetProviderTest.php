<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Tests\Functional\Service;

use GbWeb\EditorialFlow\Service\RecordCreationTargetProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class RecordCreationTargetProviderTest extends FunctionalTestCase
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
        $GLOBALS['BE_USER']->setWorkspace(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)
            ->createFromUserPreferences($GLOBALS['BE_USER']);
    }

    #[Test]
    public function recordTypesContainEligibleWorkspaceTablesButNeverPagesOrContentElements(): void
    {
        $types = $this->get(RecordCreationTargetProvider::class)
            ->getCreatableRecordTypes($GLOBALS['BE_USER']);
        $tables = array_column($types, 'table');

        self::assertContains('sys_category', $tables);
        self::assertNotContains('pages', $tables);
        self::assertNotContains('tt_content', $tables);
        self::assertNotContains('sys_log', $tables);
    }

    #[Test]
    public function recordTypeCategoriesGroupCreatableTablesWithAnIconEach(): void
    {
        $categories = $this->get(RecordCreationTargetProvider::class)
            ->getCreatableRecordTypeCategories($GLOBALS['BE_USER']);

        $tables = [];
        foreach ($categories as $identifier => $group) {
            self::assertSame($identifier, $group['identifier']);
            self::assertNotSame('', $group['label']);
            foreach ($group['items'] as $item) {
                self::assertNotSame('', $item['icon'], sprintf('table "%s" has no icon', $item['identifier']));
                self::assertSame('event', $item['requestType']);
                $tables[] = $item['identifier'];
            }
        }

        self::assertContains('sys_category', $tables);
        self::assertNotContains('pages', $tables);
        self::assertNotContains('tt_content', $tables);
        self::assertNotContains('sys_log', $tables);
    }

    #[Test]
    public function eligiblePagesCoverTheAccessibleTreeAndHonorPageTsRestrictions(): void
    {
        $this->getConnectionPool()->getConnectionForTable('pages')->update(
            'pages',
            ['TSconfig' => 'mod.web_list.deniedNewTables = sys_category'],
            ['uid' => 2],
        );

        $targets = $this->get(RecordCreationTargetProvider::class)
            ->getEligiblePages('sys_category', $GLOBALS['BE_USER']);
        $pageUids = array_column($targets, 'uid');

        self::assertContains(1, $pageUids, 'the root of the accessible page tree is a valid target');
        self::assertNotContains(2, $pageUids, 'PageTS can remove an otherwise valid target page');
    }

    #[Test]
    public function nonAdminWithoutWebmountCannotUseOtherwiseEditablePages(): void
    {
        $this->setUpBackendUser(2);
        $GLOBALS['BE_USER']->workspace = 1;
        $GLOBALS['BE_USER']->groupData['tables_modify'] = 'sys_category';
        $this->getConnectionPool()->getConnectionForTable('pages')->update(
            'pages',
            ['perms_everybody' => 17],
            [],
        );

        $targets = $this->get(RecordCreationTargetProvider::class)
            ->getEligiblePages('sys_category', $GLOBALS['BE_USER']);

        self::assertSame([], $targets);
    }
}
