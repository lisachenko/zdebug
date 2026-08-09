<?php

/**
 * This file is part of the zdebug package.
 *
 * @copyright Copyright 2026, Lisachenko Alexander <lisachenko.it@gmail.com>
 *
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 *
 * Debuggee for the property_get tests: a frame holding the shapes an IDE asks to expand.
 * The caught exception is the case that motivated the command - a variables panel shows
 * it as a bare "object" leaf until property_get can be answered - and it carries a
 * `previous` so the nested walk has two levels to cross. The array is longer than the
 * max_children the test negotiates, so paging has something to page through. Compiled
 * AFTER the debugger bootstraps (required from property-entry.php).
 */
declare(strict_types=1);

final class ConfigError extends RuntimeException
{
    public function __construct(public readonly string $section, string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 42, $previous);
    }
}

function inspect(): string
{
    try {
        throw new ConfigError('database', 'connection refused', new LengthException('root cause'));
    } catch (ConfigError $error) {
        $report  = ['section' => $error->section, 'nested' => ['depth' => ['leaf' => 'bottom']]];
        $numbers = [10, 20, 30, 40, 50];
        $banner  = str_repeat('x', 40);

        return $error->getMessage(); // inspection point
    }
}

echo 'REPORT=' . inspect() . "\n";
echo "PROPERTY APP DONE\n";
