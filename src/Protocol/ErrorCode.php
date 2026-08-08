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
 * DBGp error codes (a subset of the spec, the ones a line debugger returns)
 *
 * @see https://xdebug.org/docs/dbgp#error-codes
 */
enum ErrorCode: int
{
    // 000 range: command parsing errors
    case ParseError          = 1;
    case DuplicateArguments  = 2;
    case InvalidOptions      = 3;
    case Unimplemented       = 4;
    case CommandNotAvailable = 5;

    // 100 range: file/data errors
    case CannotOpenFile       = 100;
    case StreamRedirectFailed = 101;

    // 200 range: breakpoint / eval errors
    case BreakpointTypeUnsupported = 201;
    case BreakpointInvalid         = 202;
    case BreakpointNoCode          = 203;
    case BreakpointStateInvalid    = 204;
    case BreakpointDoesNotExist    = 205;
    case EvalFailed                = 206;
    case InvalidExpression         = 207;

    // 300 range: data / property errors
    case PropertyDoesNotExist = 300;
    case StackDepthInvalid    = 301;
    case ContextInvalid       = 302;

    // 900 range: protocol errors
    case EncodingUnsupported = 998;
    case InternalError       = 999;
}
