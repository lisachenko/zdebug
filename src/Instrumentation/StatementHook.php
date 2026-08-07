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
use ZDebug\Context\StackCollector;
use ZDebug\Log;
use ZDebug\Session\DebugSession;
use ZDebug\Stepping\StepController;
use ZEngine\Core;
use ZEngine\System\ExecutionData;
use ZEngine\System\Hook\OpCodeHook;
use ZEngine\System\OpCode;

/**
 * The EXT_STMT opcode handler: the point where the debuggee is inspected and suspended
 *
 * Compiling with COMPILE_EXTENDED_STMT emits an EXT_STMT opline before every statement;
 * this handler runs on each. It is a raw FFI callback, so the two invariants from
 * AGENTS.md are absolute: (1) a static reentrancy latch is checked first, because the
 * debugger's own PHP would otherwise recurse into this very handler, and (2) nothing
 * may throw - the whole body is wrapped, and only the closure-safe frame API is used.
 */
final class StatementHook
{
    private static bool $inDebugger = false;

    private ?OpCodeHook $hook = null;

    /** @var (callable(): ?DebugSession)|null */
    private $sessionResolver;

    public function __construct(
        private readonly OpArrayGate $gate,
        private readonly BreakpointRegistry $breakpoints,
        private readonly StepController $stepper,
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
        $this->hook            = OpCode::setHandler(OpCode::EXT_STMT, fn($scope): int => $this->onStatement($scope));
    }

    public function uninstall(): void
    {
        $this->hook?->uninstall();
        $this->hook = null;
    }

    /**
     * @param mixed $scope The ExecutionData the engine passes (typed loosely for the handler contract)
     */
    private function onStatement($scope): int
    {
        // (1) Reentrancy latch FIRST, and set BEFORE any zdebug code runs: resolving the
        // session and every check below execute instrumented PHP that would otherwise
        // re-enter this very handler (isLive(), evaluate(), the whole break loop).
        if (self::$inDebugger) {
            return Core::ZEND_USER_OPCODE_DISPATCH;
        }
        self::$inDebugger = true;
        try {
            if (!$scope instanceof ExecutionData) {
                return Core::ZEND_USER_OPCODE_DISPATCH;
            }
            $session = $this->sessionResolver !== null ? ($this->sessionResolver)() : null;
            if ($session === null || !$session->isLive()) {
                // Fast path: no active debugging session
                return Core::ZEND_USER_OPCODE_DISPATCH;
            }
            $this->evaluate($scope, $session);
        } catch (\Throwable $error) {
            // (2) Nothing escapes the FFI callback
            $this->log->exception($error);
        } finally {
            self::$inDebugger = false;
        }

        return Core::ZEND_USER_OPCODE_DISPATCH;
    }

    private function evaluate(ExecutionData $frame, DebugSession $session): void
    {
        $decision = $this->gate->decide($frame);
        if (!$decision->observed) {
            return;
        }

        $shouldBreak = false;

        if ($this->stepper->isStepping()) {
            $depth       = $this->stepper->needsDepth() ? StackCollector::depthOf($frame) : 0;
            $shouldBreak = $this->stepper->shouldBreak($depth);
        }

        if (!$shouldBreak && $this->breakpoints->hasLineBreakpoints()) {
            $line     = $frame->getOpline()->getLine();
            $matching = $this->breakpoints->atLine($decision->file, $line);
            foreach ($matching as $breakpoint) {
                $breakpoint->hitCount++;
                $shouldBreak = true;
            }
        }

        if ($shouldBreak) {
            $session->enterBreak($frame);
        }
    }
}
