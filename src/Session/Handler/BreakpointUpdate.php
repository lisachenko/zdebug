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

use ZDebug\Breakpoint\Breakpoint;
use ZDebug\Breakpoint\BreakpointRegistry;
use ZDebug\Protocol\Command;
use ZDebug\Protocol\DbgpCommand;
use ZDebug\Protocol\ErrorCode;
use ZDebug\Protocol\ResponseBuilder;
use ZDebug\Session\DispatchResult;

/**
 * breakpoint_update: changes a registered breakpoint in place, keeping its id
 *
 * The IDE sends this instead of remove+set when the user toggles a breakpoint, drags
 * it to another line or edits its hit condition, and expects the id (and with it the
 * accumulated hit count) to survive. Only the four fields DBGp allows are writable;
 * `-n` goes through the registry because the line is an index key, not just a field.
 */
final class BreakpointUpdate implements CommandHandler
{
    public private(set) array $commands = [DbgpCommand::BreakpointUpdate];

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

        $state = $command->argument('s');
        if ($state !== null) {
            if ($state !== 'enabled' && $state !== 'disabled') {
                return $this->respond->error($command, ErrorCode::BreakpointStateInvalid, "Unsupported breakpoint state '{$state}'");
            }
            $breakpoint->enabled = $state === 'enabled';
        }

        $hitCondition = $command->argument('o');
        if ($hitCondition !== null) {
            if (!in_array($hitCondition, Breakpoint::HIT_CONDITIONS, true)) {
                return $this->respond->error($command, ErrorCode::BreakpointInvalid, "Unsupported hit condition '{$hitCondition}'");
            }
            $breakpoint->hitCondition = $hitCondition;
        }

        $hitValue = $command->intArgument('h');
        if ($hitValue !== null) {
            $breakpoint->hitValue = max(0, $hitValue);
        }

        $line = $command->intArgument('n');
        if ($line !== null) {
            $this->breakpoints->relocate($breakpoint, $line);
        }

        return $this->respond->reply($command, [], ResponseBuilder::breakpoint($breakpoint));
    }
}
