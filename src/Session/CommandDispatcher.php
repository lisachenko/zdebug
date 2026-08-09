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

use ZDebug\Protocol\Command;
use ZDebug\Protocol\DbgpCommand;
use ZDebug\Protocol\ErrorCode;
use ZDebug\Protocol\ResponseBuilder;
use ZDebug\Session\Handler\CommandHandler;

/**
 * Routes DBGp commands to their handler objects
 *
 * Each command is a CommandHandler; this class only looks the handler up by the wire
 * name, answers error 4 for a name outside DbgpCommand, and turns a throwing handler
 * into error 998 - the loop above never sees an exception, because it runs inside the
 * FFI statement callback where a throw is fatal.
 *
 * Construction verifies that the registered handlers cover the DbgpCommand enum exactly
 * (every case served, no case served twice). That check is what preserves the old match
 * statement's guarantee after its arms became objects: feature_get advertises support
 * straight off the enum, so an enum case without a handler would be an advertised
 * command that answers error 4 - the protocol lie this codebase is built to make
 * impossible. It fails at session wiring, in every test that builds a dispatcher, not
 * at the first IDE keystroke in production.
 */
final class CommandDispatcher
{
    /** @var array<string, CommandHandler> Wire command name => the object serving it */
    private array $handlers = [];

    /**
     * @param iterable<CommandHandler> $handlers
     */
    public function __construct(
        private readonly ResponseBuilder $xml,
        iterable $handlers,
    ) {
        foreach ($handlers as $handler) {
            foreach ($handler->commands as $case) {
                if (isset($this->handlers[$case->value])) {
                    throw new \LogicException(sprintf(
                        "Command '%s' is claimed by both %s and %s",
                        $case->value,
                        $this->handlers[$case->value]::class,
                        $handler::class,
                    ));
                }
                $this->handlers[$case->value] = $handler;
            }
        }

        foreach (DbgpCommand::cases() as $case) {
            if (!isset($this->handlers[$case->value])) {
                throw new \LogicException(
                    "Command '{$case->value}' is advertised by DbgpCommand but no handler serves it",
                );
            }
        }
    }

    public function dispatch(Command $command): DispatchResult
    {
        try {
            $handler = $this->handlers[$command->name] ?? null;
            if ($handler === null) {
                return DispatchResult::reply($this->xml->error(
                    $command->name,
                    $command->transactionId,
                    ErrorCode::Unimplemented,
                    "Command '{$command->name}' is not implemented",
                ));
            }

            return $handler->handle($command);
        } catch (\Throwable $error) {
            return DispatchResult::reply(
                $this->xml->error($command->name, $command->transactionId, ErrorCode::InternalException, $error->getMessage()),
            );
        }
    }
}
