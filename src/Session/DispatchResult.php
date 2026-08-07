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

use ZDebug\Stepping\ResumeMode;

/**
 * The outcome of dispatching one DBGp command
 *
 * Most commands produce an immediate $response. Continuation commands (run, step_*)
 * are answered LATER, at the next break, so they carry a $resume mode and no immediate
 * response - the command loop records them as pending and unblocks the debuggee.
 */
final class DispatchResult
{
    private function __construct(
        public readonly ?string $response,
        public readonly ?ResumeMode $resume,
        public readonly bool $terminate,
    ) {}

    /**
     * A normal command answered right now
     */
    public static function reply(string $response): self
    {
        return new self($response, null, false);
    }

    /**
     * A continuation command: unblock the debuggee, answer at the next break
     */
    public static function continuation(ResumeMode $mode): self
    {
        return new self(null, $mode, false);
    }

    /**
     * Terminate the session after sending a final response
     */
    public static function terminate(string $response): self
    {
        return new self($response, ResumeMode::Stopping, true);
    }
}
