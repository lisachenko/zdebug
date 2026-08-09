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
 * status: reports which state of the DBGp status machine the session is in
 */
final class Status implements CommandHandler
{
    public private(set) array $commands = [DbgpCommand::Status];

    public function __construct(
        private readonly SuspendedState $state,
        private readonly Responses $respond,
    ) {}

    public function handle(Command $command): DispatchResult
    {
        return $this->respond->reply($command, [
            'status' => $this->state->status->value,
            'reason' => 'ok',
        ]);
    }
}
