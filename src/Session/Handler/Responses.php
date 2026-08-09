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

namespace ZDebug\Session\Handler;

use ZDebug\Protocol\Command;
use ZDebug\Protocol\ErrorCode;
use ZDebug\Protocol\ResponseBuilder;
use ZDebug\Session\DispatchResult;

/**
 * Shapes the three response forms every handler answers with
 *
 * Handlers depend on this instead of on ResponseBuilder so that echoing the command
 * name and transaction id - which DBGp requires on every single response - is written
 * once, here, and cannot be forgotten by a new handler.
 */
final class Responses
{
    public function __construct(private readonly ResponseBuilder $xml) {}

    /**
     * A <response> carrying attributes and (optionally) child elements
     *
     * @param array<string, string> $attributes
     */
    public function reply(Command $command, array $attributes = [], string $body = ''): DispatchResult
    {
        return DispatchResult::reply($this->xml->response($command->name, $command->transactionId, $attributes, $body));
    }

    /**
     * The final <response> of a session; the command loop tears down after sending it
     *
     * @param array<string, string> $attributes
     */
    public function terminate(Command $command, array $attributes): DispatchResult
    {
        return DispatchResult::terminate($this->xml->response($command->name, $command->transactionId, $attributes));
    }

    public function error(Command $command, ErrorCode $code, string $message): DispatchResult
    {
        return DispatchResult::reply($this->xml->error($command->name, $command->transactionId, $code, $message));
    }
}
