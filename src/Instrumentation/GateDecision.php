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

namespace ZDebug\Instrumentation;

/**
 * The memoized observation decision for one op_array
 *
 * $address is the op_array's stable entry address - the key this decision is cached
 * under, carried along so a caller can tell two frames' code apart without going back
 * to the engine for it.
 */
final class GateDecision
{
    public function __construct(
        public readonly bool $observed,
        public readonly string $file,
        public readonly string $functionName,
        public readonly int $address = 0,
    ) {}

    public static function notObserved(): self
    {
        return new self(false, '', '');
    }
}
