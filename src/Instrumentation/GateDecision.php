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
 */
final class GateDecision
{
    public function __construct(
        public readonly bool $observed,
        public readonly string $file,
        public readonly string $functionName,
    ) {}

    public static function notObserved(): self
    {
        return new self(false, '', '');
    }
}
