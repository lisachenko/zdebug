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

namespace ZDebug\Session\Handler;

use ZDebug\Protocol\Command;
use ZDebug\Protocol\DbgpCommand;
use ZDebug\Session\DispatchResult;

/**
 * One DBGp command (or one tightly-coupled family of them), as an object
 *
 * Adding a command to the engine is: an enum case in DbgpCommand, a class implementing
 * this interface, and a registration in CommandDispatcherFactory - no existing handler
 * is touched. CommandDispatcher verifies at construction that the registered handlers
 * cover the enum exactly, so the set feature_get advertises and the set that actually
 * dispatches cannot drift apart (the invariant the old match statement provided by
 * being a single expression).
 *
 * A handler may serve several enum cases only when they are one operation on the wire:
 * property_value IS property_get without the data clamp, run/step_* differ only in the
 * ResumeMode they record. Anything less identical gets its own class.
 *
 * Implementations back $commands with a plain `private(set)` property carrying a
 * constant default, NOT a computed `get` hook. Deliberately so, and the reason is an
 * engine bug rather than taste: with hooks here, PHP 8.5.9 segfaults the test process
 * ~9 runs in 10 by jumping into the opcache/JIT mapping at bytes that are not code.
 * Measured on this tree - hooks 8/8 crashes, plain properties 0/8; every opcache.jit
 * mode and level reproduces it (function JIT at 1201 included, so it is not a tracing
 * or trace-buffer problem), jit=off never does, and the trigger appears between 19 and
 * 20 hooked classes rather than at any single one. Only the DEBUGGEE needs JIT off -
 * phpunit and IDE-side tooling are ordinary PHP processes where JIT is legitimately
 * on, so these classes have to survive it.
 *
 * Not yet minimized to a standalone script: synthetic replicas of this shape do not
 * crash, so something about the surrounding process is required too. Restore the hooks
 * only against a PHP that has been shown to survive them.
 */
interface CommandHandler
{
    /** @var non-empty-list<DbgpCommand> The commands this handler answers */
    public array $commands { get; }

    public function handle(Command $command): DispatchResult;
}
