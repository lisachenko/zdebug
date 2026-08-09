<?php

/**
 * This file is part of the zdebug package.
 *
 * @copyright Copyright 2026, Lisachenko Alexander <lisachenko.it@gmail.com>
 *
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 *
 * Debuggee for the call/return breakpoint tests. handle() is a method called twice, so a
 * call breakpoint has to fire once per call and a hit condition has something to filter;
 * its body is deliberately two statements so entry and return are different lines and the
 * two breakpoint types cannot be confused for one another. helper() is a plain function
 * whose single statement is both its entry and its return. Compiled AFTER the debugger
 * bootstraps (required from functions-entry.php).
 */
declare(strict_types=1);

final class Service
{
    public function handle(int $value): int
    {
        $doubled = $value * 2; // handle entry

        return $doubled; // handle return
    }
}

function helper(int $value): int
{
    return $value + 1; // helper entry and return
}

$service = new Service();
$total   = 0;
foreach ([1, 2] as $value) {
    $total += $service->handle($value);
}
$total += helper(5);

echo 'CALLS=' . $total . "\n";
echo "FUNCTIONS APP DONE\n";
