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

/**
 * The result of one walk of the ExecutionData chain
 *
 * The two numbers a suspended debuggee needs are read off the same walk, because they
 * are not the same number: $frames is what DBGp shows (frames with user source only,
 * renumbered from 0), while $rawDepth counts every engine frame, internal ones included.
 * Stepping compares raw depths - an `array_map` callback sits at a real engine depth its
 * DBGp level would not reflect, and a step_over resuming from one must return to the
 * caller, not to the next filtered frame.
 */
final class StackSnapshot
{
    /**
     * @param list<StackFrame> $frames   Displayable frames, innermost first (level 0)
     * @param int              $rawDepth Total engine frame count from the top (1 = top-level)
     */
    public function __construct(
        public readonly array $frames,
        public readonly int $rawDepth,
    ) {}
}
