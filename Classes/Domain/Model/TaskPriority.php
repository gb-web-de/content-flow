<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Domain\Model;

/**
 * How urgent a task is.
 *
 * An enum rather than a bare int, so `max(1, min(3, $priority))` and the three
 * unexplained numbers behind it disappear from the controller. Clamping an
 * out-of-range value is now `fromRequest()`'s single, named job.
 */
enum TaskPriority: int
{
    case HIGH = 1;
    case NORMAL = 2;
    case LOW = 3;

    /**
     * Client input is never trusted to be in range; anything unknown falls back
     * to NORMAL rather than being rejected - a bad priority must not stop an
     * editor from planning work.
     */
    public static function fromRequest(mixed $value): self
    {
        return self::tryFrom((int)$value) ?? self::NORMAL;
    }

    public function label(): string
    {
        return match ($this) {
            self::HIGH => 'High',
            self::NORMAL => 'Normal',
            self::LOW => 'Low',
        };
    }
}
