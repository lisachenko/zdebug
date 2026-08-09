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
use ZDebug\Session\ExceptionBreak;
use ZEngine\Reflection\ReflectionValue;
use ZEngine\System\ExecutionData;
use ZEngine\System\OpCode;
use ZEngine\Type\OpLine;

/**
 * The THROW opcode handler: first-chance exception breakpoints
 *
 * A user handler on OpCode::THROW runs *instead of* the VM handler, i.e. one instruction
 * before the engine sets EG(exception). That is the only safe window in the whole engine
 * for a PHP callback to see an exception: ext-ffi refuses to enter a PHP callback while
 * the engine carries a live exception ("Throwing from FFI callbacks is not allowed"),
 * which closes zend_throw_exception_hook and a CATCH handler to userland for good. The
 * handler inspects the throwable in op1, suspends the session if a breakpoint matches,
 * and the envelope then returns ZEND_USER_OPCODE_DISPATCH so the throw proceeds untouched.
 *
 * Coverage gap, by design: only a *userland* `throw` compiles to a THROW opline. Throws
 * raised inside internal/C functions and engine-generated errors (TypeError,
 * DivisionByZeroError, ValueError, the ArgumentCountError of a bad call, ...) never
 * execute one and are therefore invisible on this route - no amount of plumbing on the
 * zdebug side can surface them. Same compile-order caveat as the statement hook: the
 * throwing op_array must have been compiled after the handler was installed.
 *
 * The latch/never-throw envelope is EngineHook's; see it for the two AGENTS.md invariants.
 */
final class ThrowHook extends EngineHook
{
    public function __construct(
        private readonly BreakpointRegistry $breakpoints,
        Log $log,
    ) {
        parent::__construct($log);
    }

    protected function opCode(): int
    {
        return OpCode::THROW;
    }

    /**
     * Fast path for the overwhelmingly common case: one array check per throw, and the
     * session is not even resolved when no exception breakpoint exists
     */
    protected function isRelevant(): bool
    {
        return $this->breakpoints->hasExceptionBreakpoints;
    }

    protected function checkForBreak(ExecutionData $frame, DebugSession $session): void
    {
        $thrown = self::thrownValue($frame);
        if ($thrown === null) {
            return;
        }

        $matching = $this->breakpoints->forException($thrown::class);
        if ($matching === []) {
            return;
        }
        foreach ($matching as $breakpoint) {
            $breakpoint->hitCount++;
        }

        // Suspends exactly like a line breakpoint, on the frame that is about to throw:
        // its opline is still the THROW, so the reported line is the `throw` statement
        $session->enterBreak($frame, new ExceptionBreak($thrown::class, $thrown->getMessage()));
    }

    /**
     * Materializes the throwable the THROW opline is about to raise, or null when the
     * operand cannot be resolved into one
     *
     * op1 is IS_VAR for `throw new X()` and for a thrown call result, and IS_CV for the
     * re-throw of a local (`catch (X $e) { throw $e; }`) - where the slot may additionally
     * hold an IS_REFERENCE that has to be dereferenced before the object is visible. Every
     * other shape is rejected rather than guessed at: this runs in an FFI callback where a
     * bad read is not a recoverable error.
     */
    private static function thrownValue(ExecutionData $frame): ?\Throwable
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
        $valueType = $operand->getBaseType();
        if ($valueType === ReflectionValue::IS_REFERENCE) {
            // ZVAL_DEREF: a borrowed view over the zend_reference's val slot
            $operand   = $operand->dereference();
            $valueType = $operand->getBaseType();
        }
        if ($valueType !== ReflectionValue::IS_OBJECT) {
            return null;
        }

        $value = null;
        $operand->getNativeValue($value);

        return $value instanceof \Throwable ? $value : null;
    }
}
