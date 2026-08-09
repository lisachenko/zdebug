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

namespace ZDebug\Tests\Session;

use ZDebug\Context\StackFrame;
use ZDebug\Session\ReturnValue;
use ZDebug\Session\SessionStatus;
use ZDebug\Session\SuspendedState;

/**
 * A suspended debuggee for CommandDispatcherTest: a stack without an engine under it
 *
 * failWith() makes every accessor throw, which is how the dispatcher's "no throwable
 * ever escapes towards the FFI callback" guarantee is exercised.
 */
final class FakeSuspendedState implements SuspendedState
{
    /** @var list<StackFrame> */
    private array $stack = [];

    private ?\Throwable $failure = null;

    public SessionStatus $status {
        get {
            $this->guard();

            return $this->statusValue;
        }
    }

    /** @var list<StackFrame> */
    public array $suspendedStack {
        get {
            $this->guard();

            return $this->stack;
        }
    }

    public ?ReturnValue $returnValue {
        get {
            $this->guard();

            return $this->returnValueHeld;
        }
    }

    private ?ReturnValue $returnValueHeld = null;

    public function __construct(private readonly SessionStatus $statusValue = SessionStatus::Break) {}

    /**
     * @param list<StackFrame> $frames Innermost frame first, as StackCollector produces them
     */
    public function suspendOn(array $frames): void
    {
        $this->stack = $frames;
    }

    public function failWith(\Throwable $error): void
    {
        $this->failure = $error;
    }

    public function frameAtLevel(int $level): ?StackFrame
    {
        $this->guard();

        return $this->stack[$level] ?? null;
    }

    /**
     * Makes the fake report a return stop, the way the RETURN hook does in production
     */
    public function returnsWith(mixed $value): void
    {
        $this->returnValueHeld = new ReturnValue($value);
    }

    private function guard(): void
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }
    }
}
