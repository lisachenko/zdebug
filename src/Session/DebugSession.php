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

namespace ZDebug\Session;

use ZDebug\Breakpoint\BreakpointRegistry;
use ZDebug\Context\ContextProvider;
use ZDebug\Context\PropertySerializer;
use ZDebug\Context\StackCollector;
use ZDebug\Context\StackFrame;
use ZDebug\Log;
use ZDebug\Protocol\CommandParser;
use ZDebug\Protocol\DbgpConnection;
use ZDebug\Protocol\FileUri;
use ZDebug\Protocol\ProtocolException;
use ZDebug\Protocol\ResponseBuilder;
use ZDebug\Stepping\StepController;
use ZEngine\System\ExecutionData;

/**
 * The DBGp session: status machine, command loop and suspend/resume
 *
 * A single-threaded session mirrors the DBGp status model (starting -> running ->
 * break -> stopping/stopped). Continuation commands are answered lazily: the loop
 * records the pending run/step_* and unblocks the debuggee, and the response is sent
 * from enterBreak() when the next suspend happens - carrying the <xdebug:message> the
 * IDE uses to move its cursor.
 *
 * The session owns the transport and the status; what a command handler may read of a
 * suspended debuggee is the SuspendedState contract it implements, which is all the
 * dispatcher ever sees of it.
 */
final class DebugSession implements SuspendedState
{
    private SessionStatus $status = SessionStatus::Starting;

    /** @var array{string, string}|null [command, transactionId] awaiting the next break */
    private ?array $pendingContinuation = null;

    /** @var list<StackFrame> Valid only while suspended */
    private array $suspendedStack = [];

    /** The returning value of the suspended frame, for a return stop only */
    private ?ReturnValue $suspendedReturn = null;

    private readonly Features $features;

    private readonly CommandDispatcher $dispatcher;

    private readonly CommandParser $parser;

    /**
     * @param CommandDispatcherFactory|null $dispatchers Builds the dispatcher this session
     *                                                   serves commands with; the default
     *                                                   wiring is used when none is given
     */
    public function __construct(
        private readonly DbgpConnection $connection,
        private readonly ResponseBuilder $xml,
        Features $features,
        BreakpointRegistry $breakpoints,
        ContextProvider $context,
        private readonly StackCollector $stackCollector,
        private readonly StepController $stepper,
        private readonly Log $log,
        ?CommandDispatcherFactory $dispatchers = null,
    ) {
        $this->parser     = new CommandParser();
        $this->features   = $features;
        $factory          = $dispatchers ?? new CommandDispatcherFactory($features, $breakpoints, $context, $xml);
        $this->dispatcher = $factory->create($this);
    }

    /**
     * Sends the <init> packet and services commands until the IDE issues the first run
     */
    public function start(string $fileUri, string $ideKey, string $languageVersion): void
    {
        $this->connection->send($this->xml->init($fileUri, $ideKey, getmypid() ?: 0, $languageVersion));
        // No frame is suspended in the starting state: a step_* issued here must break on
        // the debuggee's first statement, not run the script to completion
        $this->commandLoop(StepController::NO_FRAME_DEPTH);
    }

    /**
     * Suspends the debuggee at $top: answers the pending continuation with a break, then
     * services commands until the next continuation. Blocks inside the opcode handler.
     *
     * $exception is set by the THROW hook only; it turns the continuation response into a
     * first-chance exception break instead of the plain line/step one. $return is set by
     * the RETURN hook and carries the value the frame is about to hand back.
     */
    public function enterBreak(ExecutionData $top, ?ExceptionBreak $exception = null, ?ReturnValue $return = null): void
    {
        $snapshot              = $this->stackCollector->collect($top);
        $this->status          = SessionStatus::Break;
        $this->suspendedStack  = $snapshot->frames;
        $this->suspendedReturn = $return;

        $this->answerPendingContinuation($exception);
        $this->commandLoop($snapshot->rawDepth);

        // Borrowed frames are only valid while suspended; drop them on resume
        $this->suspendedStack  = [];
        $this->suspendedReturn = null;
    }

    /**
     * Called from register_shutdown_function: closes the session cleanly at script end
     */
    public function onScriptEnd(): void
    {
        if (!$this->isActive()) {
            return;
        }
        try {
            if ($this->pendingContinuation !== null) {
                [$command, $transactionId] = $this->pendingContinuation;
                $this->connection->send($this->xml->response($command, $transactionId, [
                    'status' => SessionStatus::Stopping->value,
                    'reason' => 'ok',
                ]));
                $this->pendingContinuation = null;
            }
            $this->status = SessionStatus::Stopping;
            // Give the IDE a final chance to send stop/detach
            $this->commandLoop(0);
        } catch (\Throwable $error) {
            $this->log->exception($error);
        } finally {
            $this->connection->close();
        }
    }

    /**
     * Drops the IDE connection and marks the session stopped (idempotent)
     *
     * Unlike onScriptEnd() this exchanges no messages: it is the teardown path for a
     * debugger that is going away mid-flight, where the debuggee must simply run on.
     */
    public function close(): void
    {
        $this->status = SessionStatus::Stopped;
        $this->connection->close();
    }

    public function status(): SessionStatus
    {
        return $this->status;
    }

    /**
     * Whether the session can still exchange messages with the IDE
     */
    public function isActive(): bool
    {
        return $this->status !== SessionStatus::Stopped && $this->connection->isConnected();
    }

    /**
     * Whether the debuggee should still be observed (breakpoints/stepping evaluated)
     */
    public function isLive(): bool
    {
        return $this->status === SessionStatus::Running && $this->connection->isConnected();
    }

    /**
     * @return list<StackFrame>
     */
    public function suspendedStack(): array
    {
        return $this->suspendedStack;
    }

    public function frameAtLevel(int $level): ?StackFrame
    {
        return $this->suspendedStack[$level] ?? null;
    }

    public function returnValue(): ?ReturnValue
    {
        return $this->suspendedReturn;
    }

    /**
     * Reads and dispatches commands until a continuation, termination, or dropped peer
     */
    private function commandLoop(int $currentDepth): void
    {
        while (true) {
            $line = $this->connection->receive();
            if ($line === null) {
                // The IDE closed the socket: stop debugging, let the script run to completion
                $this->status = SessionStatus::Stopped;

                return;
            }

            try {
                $command = $this->parser->parse($line);
            } catch (ProtocolException $error) {
                $this->log->debug('Ignoring malformed command: ' . $error->getMessage());
                continue;
            }

            $result = $this->dispatcher->dispatch($command);
            if ($result->response !== null) {
                $this->connection->send($result->response);
            }
            if ($result->terminate) {
                $this->status = SessionStatus::Stopped;
                $this->connection->close();

                return;
            }
            if ($result->resume !== null) {
                $this->pendingContinuation = [$command->name, $command->transactionId];
                $this->stepper->resume($result->resume, $currentDepth);
                $this->status = SessionStatus::Running;

                return;
            }
        }
    }

    private function answerPendingContinuation(?ExceptionBreak $exception): void
    {
        if ($this->pendingContinuation === null) {
            return;
        }
        [$command, $transactionId] = $this->pendingContinuation;
        $this->pendingContinuation = null;

        $body     = '';
        $topFrame = $this->suspendedStack[0] ?? null;
        if ($topFrame !== null) {
            $body = ResponseBuilder::breakMessage(
                FileUri::fromPath($topFrame->file),
                $topFrame->line,
                $exception?->className,
                $exception !== null ? $exception->message : '',
            );
        }

        // The returning value rides along with the break that reports it, so an IDE can
        // show it without having to know it should go looking in the context
        if ($this->suspendedReturn !== null) {
            [$maxDepth, $maxChildren, $maxData] = $this->features->propertyLimits();
            $body .= ResponseBuilder::returnValue(
                (new PropertySerializer($maxDepth, $maxChildren, $maxData))
                    ->serialize(ReturnValue::VARIABLE, ReturnValue::VARIABLE, $this->suspendedReturn->value),
            );
        }

        $this->connection->send($this->xml->response($command, $transactionId, [
            'status' => SessionStatus::Break->value,
            // DBGp reserves "exception" for a break caused by a throw; everything else is "ok"
            'reason' => $exception !== null ? 'exception' : 'ok',
        ], $body));
    }
}
