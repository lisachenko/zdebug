<?php

/**
 * This file is part of the zdebug package.
 *
 * @copyright Copyright 2026, Lisachenko Alexander <lisachenko.it@gmail.com>
 *
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 *
 * Debuggee for the property_set tests. Every value the test writes is read back by the
 * program itself AFTER the debugger resumes it, and printed: that final line is the only
 * evidence that a write reached the engine's own zval rather than a materialized copy the
 * debugger happened to keep. The readonly property and the private array are here for the
 * two ends of the range - one write the engine must refuse, one it must let through.
 * Compiled AFTER the debugger bootstraps (required from mutation-entry.php).
 */
declare(strict_types=1);

final class Holder
{
    public int $open = 1;

    protected string $shared = 'p';

    /** @var array<string, string> */
    private array $bag = ['k' => 'v'];

    public readonly string $sealed;

    public function __construct()
    {
        $this->sealed = 'locked';
    }

    public function describe(): string
    {
        return $this->shared . ($this->bag['k'] ?? '');
    }
}

function mutate(): string
{
    $counter = 1;
    $label   = 'before';
    $flag    = false;
    $ratio   = 1.5;
    $rows    = ['a' => ['n' => 1], 'list' => [10, 20]];
    $holder  = new Holder();
    $later   = null;

    $stop = true; // inspection point

    return implode('|', [
        $counter,
        $label,
        var_export($flag, true),
        $ratio,
        $rows['a']['n'],
        $rows['list'][1],
        $holder->open,
        $holder->describe(),
        $holder->sealed,
        (string) $later,
    ]);
}

echo 'MUTATED=' . mutate() . "\n";
echo "MUTATION APP DONE\n";
