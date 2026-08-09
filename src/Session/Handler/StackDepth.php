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
use ZDebug\Protocol\DbgpCommand;
use ZDebug\Session\DispatchResult;
use ZDebug\Session\SuspendedState;

/**
 * stack_depth: how many frames stack_get would report
 *
 * Clients call it to size their call-stack panel before fetching frames, and while
 * nothing is suspended the honest answer is zero rather than an error.
 */
final class StackDepth implements CommandHandler
{
    public private(set) array $commands = [DbgpCommand::StackDepth];

    public function __construct(
        private readonly SuspendedState $state,
        private readonly Responses $respond,
    ) {}

    public function handle(Command $command): DispatchResult
    {
        return $this->respond->reply($command, ['depth' => (string) count($this->state->suspendedStack)]);
    }
}
