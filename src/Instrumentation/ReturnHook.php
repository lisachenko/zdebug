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

use ZDebug\Breakpoint\BreakpointRegistry;
use ZDebug\Log;
use ZDebug\Session\DebugSession;
use ZDebug\Session\Features;
use ZDebug\Session\ReturnValue;
use ZDebug\Stepping\ResumeMode;
use ZDebug\Stepping\StepController;
use ZEngine\Reflection\ReflectionValue;
use ZEngine\System\ExecutionData;
use ZEngine\System\OpCode;
use ZEngine\Type\OpLine;

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
    /** The DBGp feature an IDE sets to ask for return values (Xdebug 3.2 and later) */
    private const string FEATURE = 'breakpoint_include_return_value';

    public function __construct(
        private readonly OpArrayGate $gate,
        private readonly BreakpointRegistry $breakpoints,
        private readonly Features $features,
        private readonly StepController $stepper,
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
     * whether to look at the frame at all has to be cheap: two flags and an array test,
     * with the return-value work reached only once the IDE has asked for it AND the user
     * is actually stepping
     */
    protected function isRelevant(): bool
    {
        return $this->breakpoints->hasReturnBreakpoints() || $this->stopsForReturnValue();
    }

    protected function checkForBreak(ExecutionData $frame, DebugSession $session): void
    {
        $decision = $this->gate->decide($frame);
        if (!$decision->observed) {
            return;
        }

        $suspend = $this->stopsForReturnValue();
        if ($this->breakpoints->hasReturnBreakpoints()) {
            $suspend = $this->breakpointTriggered($frame, $decision) || $suspend;
        }
        if (!$suspend) {
            return;
        }

        // The value is attached to whichever of the two reasons stopped us: an IDE that
        // asked for return values wants them on a return breakpoint just as much
        $returned = $this->features->isEnabled(self::FEATURE)
            ? new ReturnValue(self::returnedValue($frame))
            : null;

        $session->enterBreak($frame, null, $returned);
    }

    /**
     * Whether a plain return should suspend because the user is stepping through it
     *
     * Only step_into and step_out, matching Xdebug: a step_over is a request to get past
     * the call, and stopping inside the callee on its way out is the opposite of that.
     */
    private function stopsForReturnValue(): bool
    {
        return $this->features->isEnabled(self::FEATURE)
            && ($this->stepper->mode() === ResumeMode::StepInto || $this->stepper->mode() === ResumeMode::StepOut);
    }

    /**
     * Counts the hits of the matching return breakpoints, reporting whether one suspends
     */
    private function breakpointTriggered(ExecutionData $frame, GateDecision $decision): bool
    {
        $triggered = [];
        foreach ($this->breakpoints->forReturn($decision->functionName, self::boundClass($frame)) as $breakpoint) {
            $breakpoint->hitCount++;
            if ($breakpoint->hitConditionSatisfied()) {
                $triggered[] = $breakpoint;
            }
        }
        $this->breakpoints->dropTemporary($triggered);

        return $triggered !== [];
    }

    /**
     * Materializes the value the RETURN opline is about to hand back
     *
     * op1 is the returned operand, and reading it here is the whole reason this hook sits
     * on RETURN rather than anywhere else: one instruction later the value has been moved
     * into the caller's result slot and the frame that produced it is gone. IS_UNUSED is
     * a bare `return;` or a function falling off its end - the compiler appends a RETURN
     * of nothing - which is a genuine null rather than a failure to read.
     */
    private static function returnedValue(ExecutionData $frame): mixed
    {
        $opline = $frame->getOpline();
        $type   = $opline->getOp1Type();
        if ($type !== OpLine::IS_VAR && $type !== OpLine::IS_CV && $type !== OpLine::IS_TMP_VAR && $type !== OpLine::IS_CONST) {
            return null;
        }

        $operand = $opline->getOp1();
        if ($operand === null) {
            return null;
        }
        // getBaseType() reads the zval type alone; getType() would carry the type_info flags
        if ($operand->getBaseType() === ReflectionValue::IS_REFERENCE) {
            // ZVAL_DEREF: a borrowed view over the zend_reference's val slot
            $operand = $operand->dereference();
        }
        if ($operand->getBaseType() === ReflectionValue::IS_UNDEF) {
            return null;
        }

        $value = null;
        $operand->getNativeValue($value);

        return $value;
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
