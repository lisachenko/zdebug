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
use ZDebug\Session\DispatchResult;

/**
 * breakpoint_remove: drops the breakpoint the -d id names
 */
final class BreakpointRemove implements CommandHandler
{
    public private(set) array $commands = [DbgpCommand::BreakpointRemove];

    public function __construct(
        private readonly BreakpointRegistry $breakpoints,
        private readonly Responses $respond,
    ) {}

    public function handle(Command $command): DispatchResult
    {
        $id = $command->intArgument('d');
        if ($id === null || !$this->breakpoints->remove($id)) {
            return $this->respond->error($command, ErrorCode::BreakpointDoesNotExist, 'No such breakpoint');
        }

        return $this->respond->reply($command);
    }
}
