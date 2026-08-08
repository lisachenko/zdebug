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

use ZDebug\Log;
use ZDebug\Session\DebugSession;
use ZEngine\Core;
use ZEngine\System\ExecutionData;
use ZEngine\System\Hook\OpCodeHook;
use ZEngine\System\OpCode;

/**
 * The safety envelope every user opcode handler in zdebug runs inside
 *
 * A user opcode handler is a raw FFI callback, so the two AGENTS.md invariants are
 * absolute and identical for all of them - which is why they live here once instead of
 * being restated per hook:
 *
 * 1. The shared process-wide HookLatch is engaged FIRST, before a single line of zdebug
 *    code runs, and released in a `finally`. z-engine only auto-excludes `ZEngine\*` from
 *    user handlers, so resolving the session, deciding to break and the whole suspended
 *    command loop are instrumented PHP that would otherwise re-enter this very handler.
 *    The latch is shared by every hook rather than per-hook: while one handler is
 *    suspended, a `throw` anywhere in the debugger (or in a value being inspected) would
 *    otherwise still reach the THROW handler and recurse.
 * 2. Nothing may escape: a \Throwable leaving an FFI callback is a fatal engine abort
 *    ("Throwing from FFI callbacks is not allowed"), not a catchable error, so the whole
 *    body is wrapped and failures go to the log.
 *
 * Every path returns ZEND_USER_OPCODE_DISPATCH, i.e. the VM's own handler still runs and
 * the observed instruction executes exactly as it would undebugged.
 *
 * Subclasses contribute only their opcode and their break decision (checkForBreak()),
 * plus an optional cheap pre-check (isRelevant()) evaluated before the session is
 * resolved - never any part of the envelope.
 */
abstract class EngineHook
{
    private ?OpCodeHook $hook = null;

    /** @var (callable(): ?DebugSession)|null */
    private $sessionResolver;

    public function __construct(protected readonly Log $log) {}

    /**
     * Installs the handler. The session is resolved lazily so it can be attached later.
     *
     * @param callable(): ?DebugSession $sessionResolver
     */
    final public function install(callable $sessionResolver): void
    {
        $this->sessionResolver = $sessionResolver;
        $this->hook            = OpCode::setHandler($this->opCode(), fn($scope): int => $this->onOpCode($scope));
    }

    final public function uninstall(): void
    {
        $this->hook?->uninstall();
        $this->hook = null;
    }

    /**
     * The opcode this hook handles (an OpCode::* constant)
     */
    abstract protected function opCode(): int;

    /**
     * Decides whether the frame must suspend, and suspends it - the hook's only job
     *
     * Runs with the latch held and inside the envelope's try/catch, so it MAY throw:
     * anything it raises is logged and swallowed before it can reach the engine.
     */
    abstract protected function checkForBreak(ExecutionData $frame, DebugSession $session): void;

    /**
     * A cheap guard evaluated before the session is resolved (defaults to "always")
     *
     * Only for checks that are strictly cheaper than resolving the session, e.g. the
     * THROW hook's "is any exception breakpoint registered at all" array test that runs
     * on every single throw in the debuggee.
     */
    protected function isRelevant(): bool
    {
        return true;
    }

    /**
     * @param mixed $scope The ExecutionData the engine passes (typed loosely for the handler contract)
     */
    private function onOpCode($scope): int
    {
        // (1) Reentrancy latch FIRST, and engaged BEFORE any zdebug code runs
        if (!HookLatch::tryEnter()) {
            return Core::ZEND_USER_OPCODE_DISPATCH;
        }
        try {
            if (!$scope instanceof ExecutionData || !$this->isRelevant()) {
                return Core::ZEND_USER_OPCODE_DISPATCH;
            }
            $session = $this->sessionResolver !== null ? ($this->sessionResolver)() : null;
            if ($session === null || !$session->isLive()) {
                // Fast path: no active debugging session
                return Core::ZEND_USER_OPCODE_DISPATCH;
            }
            $this->checkForBreak($scope, $session);
        } catch (\Throwable $error) {
            // (2) Nothing escapes the FFI callback
            $this->log->exception($error);
        } finally {
            HookLatch::leave();
        }

        return Core::ZEND_USER_OPCODE_DISPATCH;
    }
}
