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
use ZDebug\Protocol\ResponseBuilder;
use ZDebug\Session\DispatchResult;

/**
 * breakpoint_list: renders every registered breakpoint
 */
final class BreakpointList implements CommandHandler
{
    public private(set) array $commands = [DbgpCommand::BreakpointList];

    public function __construct(
        private readonly BreakpointRegistry $breakpoints,
        private readonly Responses $respond,
    ) {}

    public function handle(Command $command): DispatchResult
    {
        $body = '';
        foreach ($this->breakpoints->all() as $breakpoint) {
            $body .= ResponseBuilder::breakpoint($breakpoint);
        }

        return $this->respond->reply($command, [], $body);
    }
}
