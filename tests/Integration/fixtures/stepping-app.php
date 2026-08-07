<?php

/**
 * This file is part of the zdebug package.
 *
 * @copyright Copyright 2026, Lisachenko Alexander <lisachenko.it@gmail.com>
 *
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 *
 * Debuggee for the stepping integration tests. It is required from stepping-entry.php
 * (so it compiles after the debugger attaches, with EXT_STMT oplines) and deliberately
 * contains nested calls plus a throw/catch pair, which app.php does not: stepping is
 * only observable across frame boundaries.
 */
declare(strict_types=1);

/**
 * Plain callee: its first statement is where a step_into at the call site must land
 */
function stepInner(int $value): int
{
    $scaled  = $value * 3;
    $shifted = $scaled + 1;

    return $shifted;
}

/**
 * Always throws, so the frame it runs in disappears without ever reaching a statement
 * at its own depth again
 */
function stepThrower(int $value): int
{
    throw new \RuntimeException('boom ' . $value);
}

/**
 * Catches what stepThrower() throws one frame up: stepping over the throwing statement
 * has to resume at a *shallower* depth than the one it was issued from
 */
function stepGuarded(int $value): int
{
    try {
        return stepThrower($value);
    } catch (\RuntimeException) {
        $recovered = -1;

        return $recovered;
    }
}

$base      = 4;
$doubled   = $base * 2;
$fromInner = stepInner($doubled);
$guarded   = stepGuarded($base);
echo 'STEP RESULT=' . ($fromInner + $guarded) . "\n";
echo "STEP DONE\n";
