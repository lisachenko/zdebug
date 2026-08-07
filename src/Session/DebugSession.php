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
 */
final class DebugSession
{
    private SessionStatus $status = SessionStatus::Starting;

    /** @var array{string, string}|null [command, transactionId] awaiting the next break */
    private ?array $pendingContinuation = null;

    /** @var list<StackFrame> Valid only while suspended */
    private array $suspendedStack = [];

    private readonly CommandDispatcher $dispatcher;

    private readonly CommandParser $parser;

    public function __construct(
        private readonly DbgpConnection $connection,
        private readonly ResponseBuilder $xml,
        Features $features,
        BreakpointRegistry $breakpoints,
        ContextProvider $context,
        private readonly StackCollector $stackCollector,
        private readonly StepController $stepper,
        private readonly Log $log,
    ) {
        $this->parser     = new CommandParser();
        $this->dispatcher = new CommandDispatcher($this, $features, $breakpoints, $context, $xml);
    }

    /**
     * Sends the <init> packet and services commands until the IDE issues the first run
     */
    public function start(string $fileUri, string $ideKey, string $languageVersion): void
    {
        $this->connection->send($this->xml->init($fileUri, $ideKey, getmypid() ?: 0, $languageVersion));
        $this->commandLoop(0);
    }

    /**
     * Suspends the debuggee at $top: answers the pending continuation with a break, then
     * services commands until the next continuation. Blocks inside the opcode handler.
     *
     * $exception is set by the THROW hook only; it turns the continuation response into a
     * first-chance exception break instead of the plain line/step one.
     */
    public function enterBreak(ExecutionData $top, ?ExceptionBreak $exception = null): void
    {
        $this->status         = SessionStatus::Break;
        $this->suspendedStack = $this->stackCollector->collect($top);

        $this->answerPendingContinuation($exception);
        $this->commandLoop(StackCollector::depthOf($top));

        // Borrowed frames are only valid while suspended; drop them on resume
        $this->suspendedStack = [];
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

        $this->connection->send($this->xml->response($command, $transactionId, [
            'status' => SessionStatus::Break->value,
            // DBGp reserves "exception" for a break caused by a throw; everything else is "ok"
            'reason' => $exception !== null ? 'exception' : 'ok',
        ], $body));
    }
}
