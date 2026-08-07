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

namespace ZDebug\Context;

use ZEngine\System\ExecutionData;

/**
 * One entry of a suspended call stack
 *
 * Keeps a BORROWED reference to the live ExecutionData: it is only valid while the
 * debuggee is suspended in the opcode handler, which is exactly when the debug loop
 * reads it. $level 0 is the innermost (currently executing) frame.
 */
final class StackFrame
{
    public function __construct(
        public readonly int $level,
        public readonly string $file,
        public readonly int $line,
        public readonly string $where,
        public readonly ExecutionData $execution,
    ) {}
}
