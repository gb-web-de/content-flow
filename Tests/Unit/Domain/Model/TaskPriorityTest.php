<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Tests\Unit\Domain\Model;

use GbWeb\EditorialFlow\Domain\Model\TaskPriority;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class TaskPriorityTest extends UnitTestCase
{
    public static function requestValueDataProvider(): \Generator
    {
        yield 'high' => [1, TaskPriority::HIGH];
        yield 'normal' => [2, TaskPriority::NORMAL];
        yield 'low' => [3, TaskPriority::LOW];
        yield 'numeric string from a form' => ['3', TaskPriority::LOW];
        yield 'out of range falls back' => [99, TaskPriority::NORMAL];
        yield 'negative falls back' => [-5, TaskPriority::NORMAL];
        yield 'nonsense falls back' => ['urgent!', TaskPriority::NORMAL];
        yield 'missing falls back' => [null, TaskPriority::NORMAL];
    }

    /**
     * A bad priority must never stop an editor from planning work, so anything
     * unusable becomes NORMAL rather than an error.
     */
    #[DataProvider('requestValueDataProvider')]
    #[Test]
    public function unusableRequestValuesFallBackToNormal(mixed $input, TaskPriority $expected): void
    {
        self::assertSame($expected, TaskPriority::fromRequest($input));
    }
}
