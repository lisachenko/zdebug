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
use ZDebug\Protocol\FileUri;
use ZDebug\Protocol\ResponseBuilder;
use ZDebug\Session\DispatchResult;
use ZDebug\Session\SuspendedState;

/**
 * stack_get: renders the suspended call stack, innermost frame first; -d selects one frame
 */
final class StackGet implements CommandHandler
{
    public private(set) array $commands = [DbgpCommand::StackGet];

    public function __construct(
        private readonly SuspendedState $state,
        private readonly Responses $respond,
    ) {}

    public function handle(Command $command): DispatchResult
    {
        $requested = $command->intArgument('d');
        $body      = '';
        foreach ($this->state->suspendedStack as $frame) {
            if ($requested !== null && $frame->level !== $requested) {
                continue;
            }
            $body .= ResponseBuilder::stackFrame(
                $frame->level,
                $frame->where,
                FileUri::fromPath($frame->file),
                $frame->line,
            );
        }

        return $this->respond->reply($command, [], $body);
    }
}
