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

/**
 * One resolved DBGp fullname: the value it points at, plus the two names a response needs
 *
 * `fullname` is the path the IDE sent (echoed back so it can key its variable tree on it),
 * `name` is only the last step of that path - "message" for "$error->message" - which is
 * what a variables panel prints in its label column.
 */
final class PropertyReference
{
    public function __construct(
        public readonly string $name,
        public readonly string $fullName,
        public readonly mixed $value,
    ) {}
}
