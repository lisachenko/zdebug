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
 * This is the composition root of the command surface: the one place that knows which
 * handler objects exist. Adding a DBGp command touches the DbgpCommand enum, one new
 * Handler class, and one line in create() - and forgetting the line is impossible to
 * ship, because CommandDispatcher's completeness check throws for the uncovered enum
 * case the first time any session (or test) is wired.
 *
 * The handlers need a suspended-state view that does not exist until the session does,
 * so the wiring is deferred to create() instead of happening inside the session's
 * constructor: DebugSession hands over `$this` as a SuspendedState and never learns
 * which objects a dispatcher is made of.
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
        $respond = new Handler\Responses($this->xml);
        $reader  = new Handler\ContextReader($state, $this->context, $this->features);

        return new CommandDispatcher($this->xml, [
            new Handler\Status($state, $respond),
            new Handler\FeatureGet($this->features, $this->xml),
            new Handler\FeatureSet($this->features, $respond),
            new Handler\BreakpointSet($this->breakpoints, $respond),
            new Handler\BreakpointGet($this->breakpoints, $respond),
            new Handler\BreakpointUpdate($this->breakpoints, $respond),
            new Handler\BreakpointRemove($this->breakpoints, $respond),
            new Handler\BreakpointList($this->breakpoints, $respond),
            new Handler\StackDepth($state, $respond),
            new Handler\StackGet($state, $respond),
            new Handler\ContextNames($respond),
            new Handler\ContextGet($state, $reader, $respond),
            new Handler\TypemapGet($respond),
            new Handler\Source($state, $this->source, $respond),
            new Handler\PropertyGet($state, $reader, $respond),
            new Handler\PropertySet($state, $this->context, $reader, $respond),
            new Handler\Evaluate($state, $reader, $this->evaluator, $respond),
            new Handler\Continuation(),
            new Handler\Stop($respond),
            new Handler\Detach($respond),
            new Handler\StreamRedirect($respond),
        ]);
    }
}
