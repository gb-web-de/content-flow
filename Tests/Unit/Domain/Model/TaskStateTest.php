<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Tests\Unit\Domain\Model;

use GbWeb\EditorialFlow\Domain\Model\TaskState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Workspaces\Service\StagesService;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class TaskStateTest extends UnitTestCase
{
    public static function stageIdDataProvider(): \Generator
    {
        yield 'edit stage is in progress' => [StagesService::STAGE_EDIT_ID, TaskState::IN_PROGRESS];
        yield 'publish stage is ready' => [StagesService::STAGE_PUBLISH_ID, TaskState::READY];
        yield 'first custom stage is review' => [1, TaskState::REVIEW];
        yield 'later custom stage is review' => [42, TaskState::REVIEW];
    }

    #[DataProvider('stageIdDataProvider')]
    #[Test]
    public function fromStageIdMapsCoreStages(int $stageId, TaskState $expected): void
    {
        self::assertSame($expected, TaskState::fromStageId($stageId));
    }

    #[Test]
    public function onlyUnversionedStatesAreOwnedByEditorialFlow(): void
    {
        self::assertTrue(TaskState::BACKLOG->isOwnedByEditorialFlow());
        self::assertTrue(TaskState::PLANNED->isOwnedByEditorialFlow());
        self::assertTrue(TaskState::DONE->isOwnedByEditorialFlow());

        // These are core's to control - Editorial Flow must not write them directly.
        self::assertFalse(TaskState::IN_PROGRESS->isOwnedByEditorialFlow());
        self::assertFalse(TaskState::REVIEW->isOwnedByEditorialFlow());
        self::assertFalse(TaskState::READY->isOwnedByEditorialFlow());
    }

    #[Test]
    public function hasVersionIsTheInverseOfOwnership(): void
    {
        foreach (TaskState::cases() as $state) {
            self::assertSame(!$state->isOwnedByEditorialFlow(), $state->hasVersion(), $state->value);
        }
    }
}
