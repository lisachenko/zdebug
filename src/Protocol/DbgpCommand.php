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

namespace ZDebug\Protocol;

/**
 * The DBGp commands zdebug implements - the single source of truth for dispatch
 *
 * CommandDispatcher matches on these cases, and feature_get answers "is this command
 * supported" from the very same set, so what the engine advertises and what it can
 * actually execute are equal by construction. A command absent here is answered with
 * error 4 (unimplemented), which is exactly what an IDE probing capabilities expects.
 *
 * @see https://xdebug.org/docs/dbgp#core-commands
 */
enum DbgpCommand: string
{
    case Status = 'status';

    case FeatureGet = 'feature_get';
    case FeatureSet = 'feature_set';

    case BreakpointSet    = 'breakpoint_set';
    case BreakpointGet    = 'breakpoint_get';
    case BreakpointUpdate = 'breakpoint_update';
    case BreakpointRemove = 'breakpoint_remove';
    case BreakpointList   = 'breakpoint_list';

    case StackDepth   = 'stack_depth';
    case StackGet     = 'stack_get';
    case ContextNames = 'context_names';
    case ContextGet   = 'context_get';
    case TypemapGet   = 'typemap_get';
    case Source       = 'source';
    case Eval         = 'eval';

    case PropertyGet   = 'property_get';
    case PropertySet   = 'property_set';
    case PropertyValue = 'property_value';

    case Run      = 'run';
    case StepInto = 'step_into';
    case StepOver = 'step_over';
    case StepOut  = 'step_out';
    case Stop     = 'stop';
    case Detach   = 'detach';

    /** Stream redirection is acknowledged as unsupported rather than refused */
    case Stdout = 'stdout';
    case Stderr = 'stderr';

    /**
     * Whether a command name is one the dispatcher can actually execute
     */
    public static function isSupported(string $name): bool
    {
        return self::tryFrom($name) !== null;
    }
}
