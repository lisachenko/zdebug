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

namespace ZDebug\Protocol;

/**
 * A single parsed DBGp command
 *
 * DBGp commands are ASCII lines of the form `name -i TXN [-a value ...] [-- base64data]`.
 * Arguments are exposed as a `-a => value` map; the optional trailing data part (after
 * `--`) is base64-decoded into $data.
 */
final class Command
{
    /**
     * @param string                $name          The command verb (e.g. "breakpoint_set")
     * @param string                $transactionId The -i transaction id echoed back in the response
     * @param array<string, string> $arguments     Option letter => value (without the leading '-')
     * @param string|null           $data          Decoded trailing data (the part after '--'), or null
     */
    public function __construct(
        public readonly string $name,
        public readonly string $transactionId,
        public readonly array $arguments = [],
        public readonly ?string $data = null,
    ) {}

    /**
     * Returns the value of an option argument, or a default when absent
     */
    public function argument(string $option, ?string $default = null): ?string
    {
        return $this->arguments[$option] ?? $default;
    }

    /**
     * Returns an option argument as an int, or a default when absent/non-numeric
     */
    public function intArgument(string $option, ?int $default = null): ?int
    {
        $value = $this->arguments[$option] ?? null;

        return $value !== null && is_numeric($value) ? (int) $value : $default;
    }

    /**
     * Whether the option argument is present
     */
    public function has(string $option): bool
    {
        return isset($this->arguments[$option]);
    }
}
