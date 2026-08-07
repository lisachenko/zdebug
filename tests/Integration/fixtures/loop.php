<?php

/**
 * This file is part of the zdebug package.
 *
 * @copyright Copyright 2026, Lisachenko Alexander <lisachenko.it@gmail.com>
 *
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 *
 * Loop-bearing debuggee for the conditional-breakpoint and hit-condition tests: the
 * assignment inside the loop body is hit once per iteration, so a single line breakpoint
 * can be filtered by a condition on $i or by a hit count. Compiled AFTER the debugger
 * bootstraps (required from loop-entry.php), so its statements are instrumented.
 */
declare(strict_types=1);

function accumulate(int $count): int
{
    $total = 0;
    for ($i = 1; $i <= $count; $i++) {
        $step  = $i * 10;
        $total = $total + $step;
    }

    return $total;
}

$sum = accumulate(4);
echo 'SUM=' . $sum . "\n";
echo "LOOP DONE\n";
