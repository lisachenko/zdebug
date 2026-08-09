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

use ZDebug\Breakpoint\Breakpoint;
use ZDebug\Breakpoint\BreakpointRegistry;
use ZDebug\Context\ContextProvider;
use ZDebug\Context\StackCollector;
use ZDebug\Log;
use ZDebug\Session\ConditionEvaluator;
use ZDebug\Session\DebugSession;
use ZDebug\Stepping\StepController;
use ZEngine\System\ExecutionData;
use ZEngine\System\OpCode;

/**
 * The EXT_STMT opcode handler: the point where the debuggee is inspected and suspended
 *
 * Compiling with COMPILE_EXTENDED_STMT emits an EXT_STMT opline before every statement;
 * this handler runs on each. The latch/never-throw envelope it runs inside belongs to
 * EngineHook; what is left here is the break decision alone, and it is still on the
 * hottest path in the process - hence the gate memoization, the breakpoint index and the
 * lazily materialized locals below.
 */
final class StatementHook extends EngineHook
{
    private readonly StackCollector $stack;

    /** The "op_array:line" of the last statement seen on an entry line, or null */
    private ?string $lastEntryMarker = null;

    public function __construct(
        private readonly OpArrayGate $gate,
        private readonly BreakpointRegistry $breakpoints,
        private readonly StepController $stepper,
        Log $log,
        private readonly ContextProvider $context,
        private readonly ConditionEvaluator $evaluator,
    ) {
        parent::__construct($log);
        // A pure walker over the gate this hook already owns: the stepping depth here and
        // the frame list DebugSession builds at break time then share one implementation
        $this->stack = new StackCollector($gate);
    }

    protected function opCode(): int
    {
        return OpCode::EXT_STMT;
    }

    protected function checkForBreak(ExecutionData $frame, DebugSession $session): void
    {
        $decision = $this->gate->decide($frame);
        if (!$decision->observed) {
            return;
        }

        $shouldBreak = false;

        if ($this->stepper->isStepping) {
            $depth       = $this->stepper->needsDepth ? $this->stack->collect($frame)->rawDepth : 0;
            $shouldBreak = $this->stepper->shouldBreak($depth);
        }

        if ($this->breakpoints->hasCallBreakpoints && $this->enteredFunction($frame, $decision)) {
            $shouldBreak = true;
        }

        if (!$shouldBreak && $this->breakpoints->hasLineBreakpoints) {
            $line     = $frame->getOpline()->getLine();
            $matching = $this->breakpoints->atLine($decision->file, $line);

            // The frame's locals are materialized at most once per statement, and only
            // when a breakpoint on this very line actually carries a condition
            $locals    = null;
            $triggered = [];
            foreach ($matching as $breakpoint) {
                if ($breakpoint->condition !== null) {
                    $locals ??= $this->context->localsOf($frame);
                    if (!$this->conditionHolds($breakpoint, $locals)) {
                        continue;
                    }
                }
                // A "hit" is a location + condition match; the hit condition then decides
                // whether this hit suspends the debuggee
                $breakpoint->hitCount++;
                if ($breakpoint->hitConditionSatisfied) {
                    $shouldBreak = true;
                    $triggered[] = $breakpoint;
                }
            }

            // A one-shot breakpoint (DBGp `-r 1`) is dropped before the debuggee suspends,
            // so the IDE already sees it gone in breakpoint_list while it is stopped on it
            $this->breakpoints->dropTemporary($triggered);
        }

        if ($shouldBreak) {
            $session->enterBreak($frame);
        }
    }

    /**
     * Whether this statement is a call breakpoint firing, i.e. a matching function's entry
     *
     * DBGp's call breakpoint is "break on entry into a new stack for function name", and
     * the engine offers userland no function-entry event: the first EXT_STMT of an
     * op_array is the closest thing that exists, and it is an exact one - control always
     * enters a frame at the top of its op_array, so that statement runs once per call.
     *
     * Consecutive statements on that same line in the same op_array are one entry, not
     * several (`function f() { $a = 1; $b = 2; }` written on one line), so a repeat of the
     * previous marker is suppressed. The residual gap is the mirror image: a function
     * whose whole body is one statement, called twice inside one expression with no other
     * statement in between (`f(f())`), reports a single entry.
     */
    private function enteredFunction(ExecutionData $frame, GateDecision $decision): bool
    {
        $line     = $frame->getOpline()->getLine();
        $isEntry  = $line !== 0 && $line === $this->gate->entryLine($frame);
        $marker   = $decision->address . ':' . $line;
        $repeated = $isEntry && $this->lastEntryMarker === $marker;

        $this->lastEntryMarker = $isEntry ? $marker : null;
        if (!$isEntry || $repeated) {
            return false;
        }

        $matching = $this->breakpoints->forCall($decision->functionName, self::boundClass($frame));

        return $this->recordHits($matching);
    }

    /**
     * Counts a hit on every matching breakpoint and reports whether any of them suspends
     *
     * @param list<Breakpoint> $matching
     */
    private function recordHits(array $matching): bool
    {
        $triggered = [];
        foreach ($matching as $breakpoint) {
            $breakpoint->hitCount++;
            if ($breakpoint->hitConditionSatisfied) {
                $triggered[] = $breakpoint;
            }
        }
        $this->breakpoints->dropTemporary($triggered);

        return $triggered !== [];
    }

    /**
     * The class of the frame's bound object, for matching a "Class::method" breakpoint
     *
     * Null for a plain function or a static method - there is simply no object to compare
     * against, and BreakpointRegistry treats that as "match on the method name alone".
     */
    private static function boundClass(ExecutionData $frame): ?string
    {
        $bound = $frame->getThis();
        if ($bound === null) {
            return null;
        }
        $object = null;
        $bound->getNativeValue($object);

        return is_object($object) ? $object::class : null;
    }

    /**
     * Evaluates a breakpoint condition; a broken condition never suspends the debuggee
     *
     * @param array<string, mixed> $locals
     */
    private function conditionHolds(Breakpoint $breakpoint, array $locals): bool
    {
        $condition = (string) $breakpoint->condition;
        $result    = $this->evaluator->evaluate($condition, $locals);
        if (!$result->ok) {
            // A condition that does not parse or throws is a user error, not a debuggee
            // one: report it to the log and leave the program running
            $this->log->error(sprintf(
                'Breakpoint #%d condition failed (%s): %s',
                $breakpoint->id,
                $condition,
                (string) $result->error,
            ));

            return false;
        }

        return $result->isTruthy;
    }
}
