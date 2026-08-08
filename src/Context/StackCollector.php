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

use ZDebug\Instrumentation\OpArrayGate;
use ZEngine\System\ExecutionData;

/**
 * Walks the ExecutionData chain into DBGp stack frames
 *
 * The topmost frame's line is the currently executing opline; each parent frame's line
 * is its own opline, which is the call site of the child (exactly what DBGp wants for
 * a backtrace). File and "where" (function name) come from the OpArrayGate, reusing its
 * memoized raw reads and never touching native reflection (which throws for closures).
 */
final class StackCollector
{
    public function __construct(private readonly OpArrayGate $gate) {}

    /**
     * Walks the suspended stack once, yielding both views of it
     *
     * The displayable frames (innermost first, level 0) and the raw engine depth come
     * out of the same traversal: they diverge as soon as an internal frame sits mid-stack
     * (an `array_map` callback, say), and walking twice both cost O(depth) on every break
     * and let the two numbers be read from different moments.
     */
    public function collect(ExecutionData $top): StackSnapshot
    {
        $frames   = [];
        $level    = 0;
        $rawDepth = 1;
        $current  = $top;

        while (true) {
            $frame = $this->frameFor($current, $level);
            if ($frame !== null) {
                $frames[] = $frame;
                $level++;
            }
            if (!$current->hasPrevious()) {
                break;
            }
            $current = $current->getPrevious();
            $rawDepth++;
        }

        return new StackSnapshot($frames, $rawDepth);
    }

    private function frameFor(ExecutionData $execution, int $level): ?StackFrame
    {
        $decision = $this->gate->decide($execution);
        if ($decision->file === '') {
            // Frames with no user source (internal calls, engine pseudo-frames) are not
            // shown as stack entries; the walk still descends past them via getPrevious()
            return null;
        }

        $line  = $execution->getOpline()->getLine();
        $where = $decision->functionName !== '' ? $decision->functionName : '{main}';

        return new StackFrame($level, $decision->file, $line, $where, $execution);
    }
}
