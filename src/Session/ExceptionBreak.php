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

/**
 * Why a suspension happened, when it was an exception breakpoint rather than a line one
 *
 * Carries only what the DBGp continuation response needs, read once inside the THROW
 * handler: keeping a reference to the live throwable would outlive the frame it belongs
 * to and pull an arbitrary object graph into the debugger.
 */
final class ExceptionBreak
{
    public function __construct(
        public readonly string $className,
        public readonly string $message = '',
    ) {}
}
