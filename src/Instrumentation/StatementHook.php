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

        if ($this->stepper->isStepping()) {
            $depth       = $this->stepper->needsDepth() ? $this->stack->collect($frame)->rawDepth : 0;
            $shouldBreak = $this->stepper->shouldBreak($depth);
        }

        if (!$shouldBreak && $this->breakpoints->hasLineBreakpoints()) {
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
                if ($breakpoint->hitConditionSatisfied()) {
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

        return $result->isTruthy();
    }
}
