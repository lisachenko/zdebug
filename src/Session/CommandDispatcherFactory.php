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

namespace ZDebug\Session;

use ZDebug\Breakpoint\BreakpointRegistry;
use ZDebug\Context\ContextProvider;
use ZDebug\Context\SourceReader;
use ZDebug\Instrumentation\FileFilter;
use ZDebug\Protocol\ResponseBuilder;

/**
 * Assembles the CommandDispatcher a session serves its commands with
 *
 * The dispatcher needs a suspended-state view that does not exist until the session
 * does, so the wiring is deferred to create() instead of happening inside the session's
 * constructor: DebugSession hands over `$this` as a SuspendedState and never learns
 * which collaborators a dispatcher is made of.
 */
final class CommandDispatcherFactory
{
    public function __construct(
        private readonly Features $features,
        private readonly BreakpointRegistry $breakpoints,
        private readonly ContextProvider $context,
        private readonly ResponseBuilder $xml,
        private readonly ConditionEvaluator $evaluator = new ConditionEvaluator(),
        private readonly SourceReader $source = new SourceReader(new FileFilter([])),
    ) {}

    public function create(SuspendedState $state): CommandDispatcher
    {
        return new CommandDispatcher(
            $state,
            $this->features,
            $this->breakpoints,
            $this->context,
            $this->xml,
            $this->evaluator,
            $this->source,
        );
    }
}
