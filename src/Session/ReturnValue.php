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
 * The value a function is returning, captured for the break that stops on its return
 *
 * A `return $this->compute();` stores its result nowhere the debugger could name, so
 * without this the one value the user was stepping towards is the one value they cannot
 * see. Xdebug 3.2 solved it by showing the returning value under a virtual variable, and
 * an IDE that knows that spelling (PhpStorm calls it "return function value debugging")
 * finds it in the variables panel without being taught anything new.
 *
 * The value is materialized at capture time and lives only as long as the suspension:
 * unlike ExceptionBreak, which deliberately keeps nothing of the throwable, the whole
 * point here IS the value - but the frame it came from is being torn down, so holding
 * the engine's zval past the break would be holding a corpse.
 */
final class ReturnValue
{
    /**
     * The variable the value is shown under, spelled exactly as Xdebug 3.2 spells it
     *
     * IDEs match on this name to render the value specially, so it is a wire constant,
     * not a label to improve.
     */
    public const string VARIABLE = '$__RETURN_VALUE';

    /**
     * DBGp facets marking it as neither a real local nor an ordinary property
     */
    public const string FACET = 'virtual return_value';

    public function __construct(public readonly mixed $value) {}
}
