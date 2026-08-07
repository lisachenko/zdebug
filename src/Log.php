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

namespace ZDebug;

/**
 * Append-only diagnostics log, a no-op unless a log file is configured
 *
 * The debugger runs inside FFI callbacks where a thrown error is fatal, so handlers
 * catch everything and route it here instead. Writing is best-effort: a failed write
 * must never propagate.
 */
final class Log
{
    public function __construct(private readonly ?string $file = null) {}

    public function debug(string $message): void
    {
        $this->write('DEBUG', $message);
    }

    public function error(string $message): void
    {
        $this->write('ERROR', $message);
    }

    /**
     * Records a caught throwable (class, message, origin) without ever rethrowing
     */
    public function exception(\Throwable $error): void
    {
        $this->write('ERROR', sprintf(
            '%s: %s @ %s:%d',
            $error::class,
            $error->getMessage(),
            $error->getFile(),
            $error->getLine(),
        ));
    }

    private function write(string $level, string $message): void
    {
        if ($this->file === null) {
            return;
        }
        // Suppress and swallow: logging must not perturb the debuggee or throw in a hook
        @file_put_contents(
            $this->file,
            sprintf("[%s] %s: %s\n", date('H:i:s'), $level, $message),
            FILE_APPEND,
        );
    }
}
