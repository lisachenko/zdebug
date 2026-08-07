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
 * The outcome of evaluating a user expression
 *
 * Evaluation happens on paths that must never throw (the FFI statement callback and the
 * command loop nested inside it), so failure is data, not an exception: $ok tells the
 * caller which of $value / $error is meaningful.
 */
final class EvaluationResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly mixed $value,
        public readonly ?string $error,
    ) {}

    public static function success(mixed $value): self
    {
        return new self(true, $value, null);
    }

    public static function failure(string $error): self
    {
        return new self(false, null, $error);
    }

    /**
     * Whether the expression both evaluated and yielded a truthy value
     *
     * A failed evaluation is never truthy: a broken breakpoint condition must not suspend
     * the debuggee.
     */
    public function isTruthy(): bool
    {
        return $this->ok && (bool) $this->value;
    }
}
