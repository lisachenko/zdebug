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
 * A DBGp fullname split into the variable it starts at and the steps taken from there
 *
 * Reading and writing a property need the same split but not the same walk: property_get
 * follows the steps through materialized copies, property_set has to reach the live frame
 * slot the base variable occupies and rebuild the path from there. Parsing once, here,
 * is what keeps the two from drifting into two dialects of the same grammar.
 */
final class ParsedPath
{
    public function __construct(
        /** @var string The base variable, as written ('$rows' or 'rows') */
        public readonly string $base,
        /** @var list<PropertyStep> The subscripts and properties applied to it, in order */
        public readonly array $steps,
    ) {}

    /** The base variable name without its sigil, i.e. as the engine names a CV slot */
    public string $baseName {
        get => ltrim($this->base, '$');
    }
}
