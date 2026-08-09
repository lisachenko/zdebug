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

namespace ZDebug\Tests\Instrumentation;

use PHPUnit\Framework\TestCase;
use ZDebug\Breakpoint\BreakpointRegistry;
use ZDebug\Context\ContextProvider;
use ZDebug\Context\StackCollector;
use ZDebug\Instrumentation\EngineHook;
use ZDebug\Instrumentation\FileFilter;
use ZDebug\Instrumentation\HookLatch;
use ZDebug\Instrumentation\OpArrayGate;
use ZDebug\Log;
use ZDebug\Protocol\DbgpConnection;
use ZDebug\Protocol\ResponseBuilder;
use ZDebug\Session\DebugSession;
use ZDebug\Session\Features;
use ZDebug\Session\SessionStatus;
use ZDebug\Stepping\StepController;
use ZEngine\Core;
use ZEngine\System\ExecutionData;
use ZEngine\System\OpCode;

/**
 * A hook whose break decision does the worst thing it can: throw
 *
 * $relevant/$calls make the two guards the envelope owns observable from the test.
 */
final class ExplodingHook extends EngineHook
{
    public int $calls = 0;

    public bool $relevant = true;

    public function __construct(Log $log, private readonly \Throwable $error)
    {
        parent::__construct($log);
    }

    protected function opCode(): int
    {
        return OpCode::EXT_STMT;
    }

    protected function isRelevant(): bool
    {
        return $this->relevant;
    }

    protected function checkForBreak(ExecutionData $frame, DebugSession $session): void
    {
        $this->calls++;

        throw $this->error;
    }
}

/**
 * The safety envelope EngineHook wraps every user opcode handler in
 *
 * The handler is driven directly rather than through OpCode::setHandler: what is under
 * test is the envelope itself (latch, containment, dispatch result), and no engine has
 * to be running for that. The frame is a bare ExecutionData because a hook that throws
 * on entry never reads it.
 */
final class EngineHookTest extends TestCase
{
    private string $logFile = '';

    /** @var list<resource> */
    private array $sockets = [];

    protected function setUp(): void
    {
        HookLatch::leave();
        $file = tempnam(sys_get_temp_dir(), 'zdebug-hook-');
        $this->assertIsString($file);
        $this->logFile = $file;
    }

    protected function tearDown(): void
    {
        HookLatch::leave();
        foreach ($this->sockets as $socket) {
            if (is_resource($socket)) {
                fclose($socket);
            }
        }
        if ($this->logFile !== '' && is_file($this->logFile)) {
            unlink($this->logFile);
        }
    }

    public function testAThrowingBreakDecisionIsSwallowedAndLogged(): void
    {
        $hook = $this->hook(new \RuntimeException('breakpoint condition blew up'));

        // Escaping here would be a fatal engine abort, not a test failure
        $result = $this->fire($hook);

        $this->assertSame(1, $hook->calls, 'the break decision was reached');
        $this->assertSame(Core::ZEND_USER_OPCODE_DISPATCH, $result, 'the VM handler still runs');
        $this->assertStringContainsString('RuntimeException: breakpoint condition blew up', $this->logged());
    }

    public function testTheLatchIsReleasedEvenWhenTheBreakDecisionThrows(): void
    {
        $hook = $this->hook(new \RuntimeException('boom'));

        $this->fire($hook);

        // A latch left engaged by a failed callback would silently disable every hook
        // for the rest of the process
        $this->assertFalse(HookLatch::isEngaged());
    }

    public function testAnErrorIsContainedExactlyLikeAnException(): void
    {
        $hook = $this->hook(new \TypeError('bad argument'));

        $this->fire($hook);

        $this->assertFalse(HookLatch::isEngaged());
        $this->assertStringContainsString('TypeError: bad argument', $this->logged());
    }

    public function testAReentrantCallBailsWithoutRunningOrReleasingTheLatch(): void
    {
        $hook = $this->hook(new \RuntimeException('never reached'));

        // Another hook is already inside the debugger: this one must return immediately
        // and, crucially, must NOT release the latch its caller still holds
        HookLatch::tryEnter();
        $result = $this->fire($hook);

        $this->assertSame(0, $hook->calls);
        $this->assertSame(Core::ZEND_USER_OPCODE_DISPATCH, $result);
        $this->assertTrue(HookLatch::isEngaged());
        $this->assertSame('', $this->logged());
    }

    public function testANonExecutionDataScopeIsIgnoredAndTheLatchReleased(): void
    {
        $hook   = $this->hook(new \RuntimeException('never reached'));
        $result = $this->invoke($hook, 'not a frame');

        $this->assertSame(0, $hook->calls);
        $this->assertSame(Core::ZEND_USER_OPCODE_DISPATCH, $result);
        $this->assertFalse(HookLatch::isEngaged());
    }

    public function testAnIrrelevantHookNeverReachesItsBreakDecision(): void
    {
        $hook           = $this->hook(new \RuntimeException('never reached'));
        $hook->relevant = false;

        $this->assertSame(Core::ZEND_USER_OPCODE_DISPATCH, $this->fire($hook));
        $this->assertSame(0, $hook->calls);
        $this->assertFalse(HookLatch::isEngaged());
    }

    public function testNoSessionShortCircuitsTheHandler(): void
    {
        $hook = $this->hook(new \RuntimeException('never reached'));
        $this->attachResolver($hook, static fn(): ?DebugSession => null);

        $this->assertSame(Core::ZEND_USER_OPCODE_DISPATCH, $this->invoke($hook, $this->frame()));
        $this->assertSame(0, $hook->calls);
        $this->assertFalse(HookLatch::isEngaged());
    }

    private function hook(\Throwable $error): ExplodingHook
    {
        return new ExplodingHook(new Log($this->logFile), $error);
    }

    /**
     * Runs the handler against a live session, the way an armed debugger would
     */
    private function fire(ExplodingHook $hook): int
    {
        $session = $this->liveSession();
        $this->attachResolver($hook, static fn(): DebugSession => $session);

        return $this->invoke($hook, $this->frame());
    }

    /**
     * Calls the private handler entry point (install() would need a running engine)
     */
    private function invoke(ExplodingHook $hook, mixed $scope): int
    {
        $method = new \ReflectionMethod(EngineHook::class, 'onOpCode');
        $result = $method->invoke($hook, $scope);
        $this->assertIsInt($result, 'the handler always answers the engine with an opcode result');

        return $result;
    }

    /**
     * @param callable(): ?DebugSession $resolver
     */
    private function attachResolver(ExplodingHook $hook, callable $resolver): void
    {
        $property = new \ReflectionProperty(EngineHook::class, 'sessionResolver');
        $property->setValue($hook, $resolver);
    }

    /**
     * A frame object that is never read: the hook throws before touching it
     */
    private function frame(): ExecutionData
    {
        return (new \ReflectionClass(ExecutionData::class))->newInstanceWithoutConstructor();
    }

    /**
     * A DebugSession that reports isLive(): a connected socket plus the running status
     */
    private function liveSession(): DebugSession
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $this->assertIsArray($pair);
        $this->sockets = array_merge($this->sockets, array_values($pair));

        $gate    = new OpArrayGate(new FileFilter([]));
        $session = new DebugSession(
            DbgpConnection::fromStream($pair[0]),
            new ResponseBuilder(),
            new Features(PHP_VERSION),
            new BreakpointRegistry(),
            new ContextProvider(),
            new StackCollector($gate),
            new StepController(),
            new Log(),
        );
        // Only a session that already answered the IDE's first `run` is live; the status
        // machine gets there through the command loop, which needs a real IDE on the wire
        (new \ReflectionProperty(DebugSession::class, 'status'))->setValue($session, SessionStatus::Running);
        $this->assertTrue($session->isLive);

        return $session;
    }

    private function logged(): string
    {
        return (string) file_get_contents($this->logFile);
    }
}
