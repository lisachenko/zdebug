<?php

/**
 * This file is part of the zdebug package.
 *
 * @copyright Copyright 2026, Lisachenko Alexander <lisachenko.it@gmail.com>
 *
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 *
 * Method-scope debuggee for the $this regression tests: the breakpoint line sits inside
 * an instance method that is called three times, so a bound object is on the frame and a
 * condition over its (private) state can single out one pass. The three visibilities are
 * all represented on purpose - context_get must show every one of them. Compiled AFTER
 * the debugger bootstraps (required from object-entry.php), so its statements are
 * instrumented.
 */
declare(strict_types=1);

final class Counter
{
    private int $counter = 0;

    protected string $label = 'ticker';

    public function __construct(public readonly int $weight) {}

    public function tick(): int
    {
        $this->counter++;
        $step = $this->counter * $this->weight;

        return $step;
    }
}

$counter = new Counter(10);
$total   = 0;
for ($pass = 1; $pass <= 3; $pass++) {
    $total += $counter->tick();
}

echo 'TOTAL=' . $total . "\n";
echo "OBJECT DONE\n";
