<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Tests\Unit\Service;

use GbWeb\EditorialFlow\Service\TaskSubjectRegistry;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Schema\TcaSchema;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class TaskSubjectRegistryTest extends UnitTestCase
{
    protected bool $resetSingletonInstances = true;

    private function registryFor(string ...$workspaceAwareTables): TaskSubjectRegistry
    {
        $factory = $this->createMock(TcaSchemaFactory::class);
        $factory->method('has')->willReturnCallback(
            static fn (string $table): bool => in_array($table, $workspaceAwareTables, true),
        );
        $factory->method('get')->willReturnCallback(
            function (string $table) use ($workspaceAwareTables): TcaSchema {
                $schema = $this->createMock(TcaSchema::class);
                $schema->method('isWorkspaceAware')->willReturn(in_array($table, $workspaceAwareTables, true));
                return $schema;
            },
        );
        return new TaskSubjectRegistry($factory);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['editorial_flow']);
        parent::tearDown();
    }

    #[Test]
    public function pagesIsAlwaysASubject(): void
    {
        $registry = $this->registryFor('pages', 'tt_content');

        self::assertTrue($registry->isSubject('pages'));
        self::assertFalse($registry->isSubject('tt_content'));
    }

    #[Test]
    public function configuredTablesBecomeSubjects(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['editorial_flow']['subjectTables'] = ['tx_news_domain_model_news'];
        $registry = $this->registryFor('pages', 'tt_content', 'tx_news_domain_model_news');

        // A news record is a record, but behaves like a page - so it gets its own task.
        self::assertTrue($registry->isSubject('tx_news_domain_model_news'));
    }

    #[Test]
    public function nonVersionableTablesAreNotTrackable(): void
    {
        $registry = $this->registryFor('pages');

        self::assertFalse($registry->isTrackable('sys_log'));
    }

    #[Test]
    public function aSubjectResolvesToItself(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['editorial_flow']['subjectTables'] = ['tx_news_domain_model_news'];
        $registry = $this->registryFor('pages', 'tx_news_domain_model_news');

        self::assertSame(
            ['table' => 'tx_news_domain_model_news', 'uid' => 7],
            $registry->resolveSubjectFor('tx_news_domain_model_news', 7),
        );
    }

    #[Test]
    public function anUntrackableTableResolvesToNothing(): void
    {
        $registry = $this->registryFor('pages');

        self::assertNull($registry->resolveSubjectFor('sys_log', 3));
    }
}
