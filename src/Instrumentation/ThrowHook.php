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
use ZEngine\Core;
use ZEngine\Reflection\ReflectionValue;
use ZEngine\System\ExecutionData;
use ZEngine\System\Hook\OpCodeHook;
use ZEngine\System\OpCode;
use ZEngine\Type\OpLine;
use ZEngine\Type\ReferenceEntry;

/**
 * The THROW opcode handler: first-chance exception breakpoints
 *
 * A user handler on OpCode::THROW runs *instead of* the VM handler, i.e. one instruction
 * before the engine sets EG(exception). That is the only safe window in the whole engine
 * for a PHP callback to see an exception: ext-ffi refuses to enter a PHP callback while
 * the engine carries a live exception ("Throwing from FFI callbacks is not allowed"),
 * which closes zend_throw_exception_hook and a CATCH handler to userland for good. The
 * handler inspects the throwable in op1, suspends the session if a breakpoint matches,
 * and always returns ZEND_USER_OPCODE_DISPATCH so the throw then proceeds untouched.
 *
 * Coverage gap, by design: only a *userland* `throw` compiles to a THROW opline. Throws
 * raised inside internal/C functions and engine-generated errors (TypeError,
 * DivisionByZeroError, ValueError, the ArgumentCountError of a bad call, ...) never
 * execute one and are therefore invisible on this route - no amount of plumbing on the
 * zdebug side can surface them. Same compile-order caveat as the statement hook: the
 * throwing op_array must have been compiled after the handler was installed.
 *
 * The two AGENTS.md invariants apply exactly as in StatementHook: the shared HookLatch is
 * checked first (a separate latch would let the two hooks re-enter through each other),
 * and no \Throwable may escape the FFI callback.
 */
final class ThrowHook
{
    private ?OpCodeHook $hook = null;

    /** @var (callable(): ?DebugSession)|null */
    private $sessionResolver;

    public function __construct(
        private readonly BreakpointRegistry $breakpoints,
        private readonly Log $log,
    ) {}

    /**
     * Installs the handler. The session is resolved lazily so it can be attached later.
     *
     * @param callable(): ?DebugSession $sessionResolver
     */
    public function install(callable $sessionResolver): void
    {
        $this->sessionResolver = $sessionResolver;
        $this->hook            = OpCode::setHandler(OpCode::THROW, fn($scope): int => $this->onThrow($scope));
    }

    public function uninstall(): void
    {
        $this->hook?->uninstall();
        $this->hook = null;
    }

    /**
     * @param mixed $scope The ExecutionData the engine passes (typed loosely for the handler contract)
     */
    private function onThrow($scope): int
    {
        // (1) Reentrancy latch FIRST: everything below is instrumented PHP, and the break
        // loop it may enter runs arbitrary debugger code that throws on its own.
        if (!HookLatch::tryEnter()) {
            return Core::ZEND_USER_OPCODE_DISPATCH;
        }
        try {
            if (!$scope instanceof ExecutionData) {
                return Core::ZEND_USER_OPCODE_DISPATCH;
            }
            if (!$this->breakpoints->hasExceptionBreakpoints()) {
                // Fast path: the overwhelmingly common case, one array check per throw
                return Core::ZEND_USER_OPCODE_DISPATCH;
            }
            $session = $this->sessionResolver !== null ? ($this->sessionResolver)() : null;
            if ($session === null || !$session->isLive()) {
                return Core::ZEND_USER_OPCODE_DISPATCH;
            }
            $this->evaluate($scope, $session);
        } catch (\Throwable $error) {
            // (2) Nothing escapes the FFI callback
            $this->log->exception($error);
        } finally {
            HookLatch::leave();
        }

        return Core::ZEND_USER_OPCODE_DISPATCH;
    }

    private function evaluate(ExecutionData $frame, DebugSession $session): void
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
        // The high byte of type_info carries the zval type flags; only the type is wanted
        $valueType = $operand->getType() & 0xFF;
        if ($valueType === ReflectionValue::IS_REFERENCE) {
            $operand   = ReferenceEntry::fromCData($operand->getRawReference())->getValue();
            $valueType = $operand->getType() & 0xFF;
        }
        if ($valueType !== ReflectionValue::IS_OBJECT) {
            return null;
        }

        $value = null;
        $operand->getNativeValue($value);

        return $value instanceof \Throwable ? $value : null;
    }
}
