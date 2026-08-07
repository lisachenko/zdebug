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
final class ErrorCode
{
    // 000 range: command parsing errors
    public const int PARSE_ERROR           = 1;
    public const int DUPLICATE_ARGUMENTS   = 2;
    public const int INVALID_OPTIONS       = 3;
    public const int UNIMPLEMENTED         = 4;
    public const int COMMAND_NOT_AVAILABLE = 5;

    // 100 range: file/data errors
    public const int CANNOT_OPEN_FILE       = 100;
    public const int STREAM_REDIRECT_FAILED = 101;

    // 200 range: breakpoint / eval errors
    public const int BREAKPOINT_TYPE_UNSUPPORTED = 201;
    public const int BREAKPOINT_INVALID          = 202;
    public const int BREAKPOINT_NO_CODE          = 203;
    public const int BREAKPOINT_STATE_INVALID    = 204;
    public const int BREAKPOINT_DOES_NOT_EXIST   = 205;
    public const int EVAL_FAILED                 = 206;
    public const int INVALID_EXPRESSION          = 207;

    // 300 range: data / property errors
    public const int PROPERTY_DOES_NOT_EXIST = 300;
    public const int STACK_DEPTH_INVALID     = 301;
    public const int CONTEXT_INVALID         = 302;

    // 900 range: protocol errors
    public const int ENCODING_UNSUPPORTED = 998;
    public const int INTERNAL_ERROR       = 999;
}
