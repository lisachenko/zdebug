<?php

/**
 * This file is part of the zdebug package.
 *
 * @copyright Copyright 2026, Lisachenko Alexander <lisachenko.it@gmail.com>
 *
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 *
 * Debuggee for the return-value debugging tests. Every function here returns something
 * it never stores in a variable, which is the case the feature exists for: without it
 * the one value the user stepped towards is the one value the variables panel cannot
 * show. void, scalar and array returns are all represented. Compiled AFTER the debugger
 * bootstraps (required from return-entry.php).
 */
declare(strict_types=1);

/**
 * @param list<int> $numbers
 */
function total(array $numbers): int
{
    return array_sum($numbers) * 2; // total return
}

/**
 * @return array{ok: bool, n: int}
 */
function describe(): array
{
    return ['ok' => true, 'n' => 3]; // describe return
}

function nothing(): void
{
    echo ''; // nothing body

    return; // nothing return
}

$sum = total([1, 2, 3]);
nothing();
$info = describe();

echo 'RETURNED=' . $sum . '/' . var_export($info['ok'], true) . '/' . $info['n'] . "\n";
echo "RETURN APP DONE\n";
