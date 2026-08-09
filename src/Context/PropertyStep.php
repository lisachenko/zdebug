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
 * One step of a DBGp fullname: an array subscript or an object property
 *
 * `$rows['first']->id` is two of these. The distinction is not cosmetic - a subscript
 * only ever addresses an array and a property only ever an object, and refusing the
 * mismatch is what keeps a malformed fullname from resolving to something plausible.
 */
final class PropertyStep
{
    private function __construct(
        public readonly bool $isIndex,
        public readonly string $key,
    ) {}

    public static function index(string $key): self
    {
        return new self(true, $key);
    }

    public static function property(string $name): self
    {
        return new self(false, $name);
    }
}
