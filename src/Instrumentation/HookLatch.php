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

namespace ZDebug\Instrumentation;

/**
 * The single process-wide "debugger code is running" latch shared by every engine hook
 *
 * z-engine only auto-excludes `ZEngine\*` classes from user opcode handlers, so the
 * debugger's own PHP re-enters its handlers unless it says otherwise. One latch per hook
 * would not be enough: while the statement hook is suspended in the command loop, any
 * `throw` inside the debugger (or inside a value being inspected) would still reach the
 * THROW handler and recurse. A single shared flag closes every hook at once.
 *
 * Usage is always `if (!HookLatch::tryEnter()) { return ...; }` first thing in the
 * handler, with `HookLatch::leave()` in a `finally` block.
 */
final class HookLatch
{
    private static bool $engaged = false;

    /**
     * Engages the latch, or reports false when it is already held (a reentrant call)
     */
    public static function tryEnter(): bool
    {
        if (self::$engaged) {
            return false;
        }

        return self::$engaged = true;
    }

    /**
     * Releases the latch so the next engine callback can be serviced
     */
    public static function leave(): void
    {
        self::$engaged = false;
    }

    /**
     * Whether debugger code is currently executing inside an engine callback
     */
    public static function isEngaged(): bool
    {
        return self::$engaged;
    }
}
