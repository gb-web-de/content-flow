<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Tests\Unit\Domain\Repository;

use GbWeb\EditorialFlow\Domain\Repository\TaskRepository;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Covers what addMember() writes without needing a real database - the
 * QueryBuilder-based reads (findMembers(), findOpenTaskByMember(), the
 * close()/detachIntoOwnTask() collision paths that call them) need an actual
 * connection to execute against and are covered by
 * Tests/Functional/Domain/Repository/TaskRepositoryTest.php instead.
 */
final class TaskRepositoryTest extends UnitTestCase
{
    #[Test]
    public function addMemberWritesPidAsHomePid(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('insert')
            ->with(
                'tx_editorialflow_task_item',
                self::callback(static function (array $data): bool {
                    self::assertSame(5, $data['pid']);
                    self::assertSame(5, $data['home_pid']);
                    return true;
                }),
            );

        $connectionPool = $this->createMock(ConnectionPool::class);
        $connectionPool->method('getConnectionForTable')->willReturn($connection);

        (new TaskRepository($connectionPool))->addMember(
            4,
            'tt_content',
            21,
            TaskRepository::ORIGIN_AUTO,
            5,
        );
    }

    #[Test]
    public function addMemberDefaultsPidToZeroWhenNoHomePidIsGiven(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('insert')
            ->with(
                'tx_editorialflow_task_item',
                self::callback(static function (array $data): bool {
                    self::assertSame(0, $data['pid']);
                    self::assertSame(0, $data['home_pid']);
                    return true;
                }),
            );

        $connectionPool = $this->createMock(ConnectionPool::class);
        $connectionPool->method('getConnectionForTable')->willReturn($connection);

        (new TaskRepository($connectionPool))->addMember(
            4,
            'pages',
            5,
            TaskRepository::ORIGIN_SUBJECT,
        );
    }
}
