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

namespace ZDebug\Tests\Session;

/**
 * Object scope for ConditionEvaluatorTest: exercises $this binding (including private
 * state) and an expression that throws
 */
final class ConditionEvaluatorFixture
{
    public function __construct(private readonly int $hidden) {}

    public function visible(): int
    {
        return $this->hidden;
    }

    public function explode(): never
    {
        throw new \RuntimeException('boom');
    }
}
