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

namespace ZDebug\Stepping;

/**
 * How execution resumes after a break, driving where the next suspend happens
 *
 * There is deliberately no "stopping" mode: a terminated session never resumes the
 * debuggee through the stepper, it closes the connection and lets the script run out.
 */
enum ResumeMode
{
    /** Run until a breakpoint is hit */
    case Run;

    /** Break on the very next statement, anywhere (step into calls) */
    case StepInto;

    /** Break on the next statement at the same or a shallower depth (skip called frames) */
    case StepOver;

    /** Break on the next statement shallower than the current frame (run to return) */
    case StepOut;
}
