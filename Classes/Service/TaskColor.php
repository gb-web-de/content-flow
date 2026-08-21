<?php

declare(strict_types=1);

namespace GbWeb\EditorialFlow\Service;

/**
 * The colour a task is drawn in, wherever it is drawn.
 *
 * A task's colour has to mean the same thing in the Visual Editor's bubbles,
 * its toolbar legend and the Page module's banner, or the colours are worse
 * than no colours at all - an editor would read "the green one" as one task in
 * one place and another elsewhere. So the hue comes from one formula, applied
 * to the one number a task always has.
 *
 * It is derived rather than stored: a colour column would need a UI, a
 * migration and a policy for what happens when two tasks pick the same one,
 * and none of that buys anything over a deterministic hue.
 *
 * The JavaScript side has the same formula in task-markers.js for the cases
 * where it only knows a uid (a marker matched against a claim). Everything the
 * server sends carries its hue with it, so the two only ever agree.
 */
final class TaskColor
{
    /**
     * The golden angle. Stepping the colour wheel by it keeps consecutive uids
     * far apart instead of shading into each other, which is what makes two
     * neighbouring tasks on the same page tellable apart at a glance.
     */
    private const GOLDEN_ANGLE = 137.508;

    public static function hueFor(int $taskUid): float
    {
        return fmod($taskUid * self::GOLDEN_ANGLE, 360.0);
    }
}
