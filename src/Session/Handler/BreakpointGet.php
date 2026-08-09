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

use ZDebug\Breakpoint\BreakpointRegistry;
use ZDebug\Protocol\Command;
use ZDebug\Protocol\DbgpCommand;
use ZDebug\Protocol\ErrorCode;
use ZDebug\Protocol\ResponseBuilder;
use ZDebug\Session\DispatchResult;

/**
 * breakpoint_get: renders the one breakpoint the -d id names
 */
final class BreakpointGet implements CommandHandler
{
    public private(set) array $commands = [DbgpCommand::BreakpointGet];

    public function __construct(
        private readonly BreakpointRegistry $breakpoints,
        private readonly Responses $respond,
    ) {}

    public function handle(Command $command): DispatchResult
    {
        $id         = $command->intArgument('d');
        $breakpoint = $id !== null ? $this->breakpoints->get($id) : null;
        if ($breakpoint === null) {
            return $this->respond->error($command, ErrorCode::BreakpointDoesNotExist, 'No such breakpoint');
        }

        return $this->respond->reply($command, [], ResponseBuilder::breakpoint($breakpoint));
    }
}
