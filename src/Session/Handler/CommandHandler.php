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
 * constant default, NOT a computed get hook. Deliberately so: a hooked property read
 * polymorphically across every handler class is a megamorphic hook site, which PHP
 * 8.5's tracing JIT miscompiles (the full test suite segfaults inside JIT-emitted
 * code with opcache.jit=tracing; plain properties are immune). Only the DEBUGGEE
 * needs JIT off - phpunit and any IDE-side tooling are ordinary PHP processes where
 * JIT is legitimately on, so the handlers must survive it.
 */
interface CommandHandler
{
    /** @var non-empty-list<DbgpCommand> The commands this handler answers */
    public array $commands { get; }

    public function handle(Command $command): DispatchResult;
}
