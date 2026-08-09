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

namespace ZDebug\Session;

use ZDebug\Context\StackFrame;

/**
 * The read-only view of a suspended debuggee that CommandDispatcher works against
 *
 * Exactly what answering status / stack_get / context_get / eval needs, and nothing
 * else: no socket, no command loop, no resume. DebugSession is the production
 * implementation, and narrowing the dependency to this contract is what lets the
 * dispatcher be exercised without an engine underneath or an IDE on the other end.
 *
 * Frames are only meaningful while the debuggee is actually suspended (status "break");
 * in every other state suspendedStack() is empty and frameAtLevel() returns null.
 */
interface SuspendedState
{
    public SessionStatus $status { get; }

    /**
     * The suspended call stack, innermost frame first
     *
     * @var list<StackFrame>
     */
    public array $suspendedStack { get; }

    /**
     * The value the suspended frame is returning, when the break was a return stop
     *
     * Null for every other break. Only the innermost frame can have one - it is the frame
     * that is leaving - which is why context_get exposes it at depth 0 and nowhere else.
     */
    public ?ReturnValue $returnValue { get; }

    /**
     * The frame at a DBGp stack depth (0 = innermost), or null when out of range
     */
    public function frameAtLevel(int $level): ?StackFrame;
}
