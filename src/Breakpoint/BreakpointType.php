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

namespace ZDebug\Breakpoint;

/**
 * DBGp breakpoint types (the subset zdebug supports), as sent in breakpoint_set -t
 *
 * @see https://xdebug.org/docs/dbgp#breakpoints
 */
enum BreakpointType: string
{
    case Line        = 'line';
    case Conditional = 'conditional';
    case Exception   = 'exception';
}
