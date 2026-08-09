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
use ZDebug\Log;
use ZDebug\Session\DebugSession;
use ZEngine\System\ExecutionData;
use ZEngine\System\OpCode;

/**
 * The RETURN opcode handler: DBGp `return` breakpoints
 *
 * "Break on exit from stack for function name" is, at the opcode level, exactly the
 * instruction this handler replaces: a user handler on RETURN runs while the returning
 * frame is still the current one, so the whole stack, the locals and the line of the
 * `return` are all still there to be inspected - which is the entire point of stopping
 * on a return rather than on the caller's next statement.
 *
 * Every function body ends in a RETURN, including one that falls off the end without a
 * `return` statement (the compiler appends one), so a plain function is covered whether
 * or not it returns anything. Two exits are NOT this opcode and are therefore invisible
 * here: `return` by reference (RETURN_BY_REF) and a generator's return
 * (GENERATOR_RETURN), and neither is a shape zdebug can reach through one handler.
 * Leaving via an exception is not a return at all - that is the THROW hook's route.
 *
 * The latch/never-throw envelope is EngineHook's; see it for the two AGENTS.md invariants.
 */
final class ReturnHook extends EngineHook
{
    public function __construct(
        private readonly OpArrayGate $gate,
        private readonly BreakpointRegistry $breakpoints,
        Log $log,
    ) {
        parent::__construct($log);
    }

    protected function opCode(): int
    {
        return OpCode::RETURN;
    }

    /**
     * Every single function call in the debuggee ends here, so the check that decides
     * whether to look at the frame at all has to be one array test
     */
    protected function isRelevant(): bool
    {
        return $this->breakpoints->hasReturnBreakpoints();
    }

    protected function checkForBreak(ExecutionData $frame, DebugSession $session): void
    {
        $decision = $this->gate->decide($frame);
        if (!$decision->observed) {
            return;
        }

        $matching = $this->breakpoints->forReturn($decision->functionName, self::boundClass($frame));
        if ($matching === []) {
            return;
        }

        $triggered = [];
        foreach ($matching as $breakpoint) {
            $breakpoint->hitCount++;
            if ($breakpoint->hitConditionSatisfied()) {
                $triggered[] = $breakpoint;
            }
        }
        $this->breakpoints->dropTemporary($triggered);

        if ($triggered !== []) {
            $session->enterBreak($frame);
        }
    }

    /**
     * The class of the frame's bound object, for matching a "Class::method" breakpoint
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
}
