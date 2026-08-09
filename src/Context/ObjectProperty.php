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
 * One slot of an object's property table: its value and the visibility it was declared under
 *
 * The visibility is DBGp's `facet` attribute, which is how an IDE draws the padlock next
 * to a private property. It cannot be recovered later from the plain name - the (array)
 * cast's mangled key is the only place it is recorded - so it travels with the value.
 */
final class ObjectProperty
{
    public function __construct(
        public readonly mixed $value,
        public readonly string $facet,
    ) {}
}
