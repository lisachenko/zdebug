<?php

/**
 * This file is part of the zdebug package.
 *
 * @copyright Copyright 2026, Lisachenko Alexander <lisachenko.it@gmail.com>
 *
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 */
declare(strict_types=1);

namespace ZDebug\Context;

use ZDebug\Instrumentation\FileFilter;

/**
 * Reads debuggee source for the DBGp `source` command
 *
 * An IDE asks for source when its own copy of a file is not the one being executed:
 * remote debugging, a container path that does not exist on the developer's machine, a
 * deployment whose sources were never checked out locally. Without it the debugger can
 * report "stopped at /srv/app/x.php:42" to an IDE that has no way to show line 42.
 *
 * Reads are restricted to the SAME FileFilter the instrumentation uses, which is the
 * whole security story of this command: a DBGp connection is a file-read primitive
 * pointed at whatever the debuggee process can open, so it is scoped to the code the
 * user configured as debuggable rather than to the filesystem. With no ZDEBUG_PATH_FILTER
 * configured the filter observes everything, and so does this - the same trade the
 * instrumentation already makes, made once and visibly.
 */
final class SourceReader
{
    public function __construct(private readonly FileFilter $filter) {}

    /**
     * Returns the requested source, or null when the file may not be read
     *
     * $begin and $end are 1-based inclusive line numbers, as DBGp's -b / -e are; either
     * may be null for "from the start" / "to the end". A range that selects nothing
     * yields an empty string rather than null: the file was readable, the request simply
     * addressed no lines, and the two cases mean different things to the caller (error
     * 100 versus an empty success).
     */
    public function read(string $path, ?int $begin = null, ?int $end = null): ?string
    {
        if (!$this->filter->accepts($path) || !is_file($path) || !is_readable($path)) {
            return null;
        }

        $contents = @file_get_contents($path);
        if ($contents === false) {
            return null;
        }
        if ($begin === null && $end === null) {
            return $contents;
        }

        $lines = preg_split('/(?<=\n)/', $contents);
        if ($lines === false) {
            return null;
        }
        // Trailing "" after a final newline is an artifact of the split, not a line
        if (($lines[count($lines) - 1] ?? '') === '') {
            array_pop($lines);
        }

        $from  = max(1, $begin ?? 1);
        $to    = $end ?? count($lines);
        $count = $to - $from + 1;

        return $count > 0 ? implode('', array_slice($lines, $from - 1, $count)) : '';
    }
}
