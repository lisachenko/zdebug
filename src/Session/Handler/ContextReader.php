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
use ZDebug\Context\PropertySerializer;
use ZDebug\Context\StackFrame;
use ZDebug\Session\Features;
use ZDebug\Session\ReturnValue;
use ZDebug\Session\SuspendedState;

/**
 * The one view of a suspended frame's variables that every inspecting command shares
 *
 * context_get, property_get, property_set and eval must all see the same variable set -
 * including the virtual $__RETURN_VALUE grafted onto the locals of depth 0 during a
 * return stop - and must all honor the same max_* features when rendering. Centralizing
 * both here is what keeps four handlers from growing four slightly different opinions
 * about what a frame contains.
 */
final class ContextReader
{
    public function __construct(
        private readonly SuspendedState $state,
        private readonly ContextProvider $context,
        private readonly Features $features,
    ) {}

    /**
     * A context's variables, with the virtual return value added where it belongs
     *
     * The returning value is not a variable of the frame - it is the frame's result, and
     * it exists only for the break that stopped on the return - so it is grafted on here,
     * once, rather than inside ContextProvider: every reader then sees the same set, and
     * none of them has to know it is virtual.
     *
     * Only the locals of depth 0: the returning frame is the innermost one, and a caller
     * further up the stack is not returning anything yet.
     *
     * @return array<string, mixed>
     */
    public function variables(StackFrame $frame, int $contextId, int $depth): array
    {
        $variables = $this->context->variables($frame, $contextId);

        $returned = $this->state->returnValue;
        if ($returned !== null && $contextId === ContextProvider::CONTEXT_LOCALS && $depth === 0) {
            $variables[ReturnValue::VARIABLE] = $returned->value;
        }

        return $variables;
    }

    /**
     * Builds the serializer for one response, honoring the IDE's current max_* features
     *
     * $maxData overrides the feature for commands that carry their own limit: the -m
     * argument of property_get, or property_value, which is defined as the unclamped read.
     */
    public function serializer(?int $maxData = null): PropertySerializer
    {
        [$maxDepth, $maxChildren, $featureMaxData] = $this->features->propertyLimits;

        return new PropertySerializer($maxDepth, $maxChildren, $maxData ?? $featureMaxData);
    }

    /**
     * The DBGp facet a context variable is reported under, or null for an ordinary one
     */
    public static function facetOf(string $name): ?string
    {
        return $name === ReturnValue::VARIABLE ? ReturnValue::FACET : null;
    }
}
