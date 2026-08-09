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

use ZDebug\Context\ContextProvider;
use ZDebug\Protocol\Command;
use ZDebug\Protocol\DbgpCommand;
use ZDebug\Protocol\ResponseBuilder;
use ZDebug\Session\DispatchResult;

/**
 * context_names: the variable-panel sections this engine serves, with their -c ids
 */
final class ContextNames implements CommandHandler
{
    public private(set) array $commands = [DbgpCommand::ContextNames];

    public function __construct(private readonly Responses $respond) {}

    public function handle(Command $command): DispatchResult
    {
        return $this->respond->reply($command, [], ResponseBuilder::contextNames([
            'Locals'       => ContextProvider::CONTEXT_LOCALS,
            'Superglobals' => ContextProvider::CONTEXT_SUPERGLOBALS,
        ]));
    }
}
