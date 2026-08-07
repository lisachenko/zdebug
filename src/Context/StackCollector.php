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
     * Collects the whole suspended stack, innermost first (level 0)
     *
     * @return list<StackFrame>
     */
    public function collect(ExecutionData $top): array
    {
        $frames  = [];
        $level   = 0;
        $current = $top;

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
        }

        return $frames;
    }

    /**
     * Computes the depth (frame count from the top) of a frame - the stepping metric
     */
    public static function depthOf(ExecutionData $frame): int
    {
        $depth   = 1;
        $current = $frame;
        while ($current->hasPrevious()) {
            $depth++;
            $current = $current->getPrevious();
        }

        return $depth;
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
