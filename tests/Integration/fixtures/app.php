<?php

/**
 * This file is part of the zdebug package.
 *
 * @copyright Copyright 2026, Lisachenko Alexander <lisachenko.it@gmail.com>
 *
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 *
 * Deterministic debuggee for the DBGp integration test. Compiled AFTER the debugger
 * bootstraps (it is required from entry.php), so its statements are instrumented.
 */
declare(strict_types=1);

function compute(int $seed): int
{
    $doubled = $seed * 2;
    $tripled = $seed * 3;
    $result  = $doubled + $tripled;

    return $result;
}

$answer = compute(7);
echo 'RESULT=' . $answer . "\n";
echo "APP DONE\n";
