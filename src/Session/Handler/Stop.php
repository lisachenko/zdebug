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
use ZDebug\Session\SessionStatus;

/**
 * stop: ends the session AND the script - the debuggee never runs another statement
 */
final class Stop implements CommandHandler
{
    public private(set) array $commands = [DbgpCommand::Stop];

    public function __construct(private readonly Responses $respond) {}

    public function handle(Command $command): DispatchResult
    {
        return $this->respond->terminate($command, [
            'status' => SessionStatus::Stopped->value,
            'reason' => 'ok',
        ]);
    }
}
