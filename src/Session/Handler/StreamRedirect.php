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

/**
 * stdout / stderr: stream redirection, acknowledged as unsupported rather than refused
 *
 * DBGp lets a client ask for the debuggee's output to be copied or redirected onto the
 * debug socket; answering success="0" tells it to keep reading the streams where they
 * are, which every IDE handles gracefully - unlike error 4, which reads as "broken".
 */
final class StreamRedirect implements CommandHandler
{
    public private(set) array $commands = [DbgpCommand::Stdout, DbgpCommand::Stderr];

    public function __construct(private readonly Responses $respond) {}

    public function handle(Command $command): DispatchResult
    {
        return $this->respond->reply($command, ['success' => '0']);
    }
}
