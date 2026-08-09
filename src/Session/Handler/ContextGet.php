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
use ZDebug\Protocol\ErrorCode;
use ZDebug\Session\DispatchResult;
use ZDebug\Session\SuspendedState;

/**
 * context_get: the variables of one context of one frame, one level deep
 *
 * Depth is what makes this affordable and property_get necessary: every node the IDE
 * then expands comes back as a property_get for that node's fullname.
 */
final class ContextGet implements CommandHandler
{
    public private(set) array $commands = [DbgpCommand::ContextGet];

    public function __construct(
        private readonly SuspendedState $state,
        private readonly ContextReader $reader,
        private readonly Responses $respond,
    ) {}

    public function handle(Command $command): DispatchResult
    {
        $contextId = $command->intArgument('c', ContextProvider::CONTEXT_LOCALS) ?? ContextProvider::CONTEXT_LOCALS;
        $depth     = $command->intArgument('d', 0)                               ?? 0;
        $frame     = $this->state->frameAtLevel($depth);
        if ($frame === null) {
            return $this->respond->error($command, ErrorCode::StackDepthInvalid, "No stack frame at depth {$depth}");
        }

        $serializer = $this->reader->serializer();
        $body       = '';
        foreach ($this->reader->variables($frame, $contextId, $depth) as $name => $value) {
            $body .= $serializer->serialize($name, $name, $value, 0, ContextReader::facetOf($name));
        }

        return $this->respond->reply($command, ['context' => (string) $contextId], $body);
    }
}
