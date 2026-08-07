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

namespace ZDebug\Config;

/**
 * A partial, un-typed bag of debugger settings produced by one configuration source
 *
 * Each source (Xdebug ini/env, the ZDEBUG_* environment, an explicit array) contributes
 * only the keys it can determine; ConfigResolver merges them in precedence order and
 * turns the result into an immutable Config. Keeping a source's output as a sparse map
 * (present key = "this source has an opinion") is what makes layered precedence clean.
 */
final class Settings
{
    public const string CLIENT_HOST        = 'client_host';
    public const string CLIENT_PORT        = 'client_port';
    public const string IDE_KEY            = 'idekey';
    public const string MODE               = 'mode';
    public const string PATH_FILTER        = 'path_filter';
    public const string CONNECT_TIMEOUT_MS = 'connect_timeout_ms';
    public const string LOG                = 'log';

    /**
     * @param array<string, mixed> $values
     */
    public function __construct(private array $values = []) {}

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values) && $this->values[$key] !== null;
    }

    public function get(string $key): mixed
    {
        return $this->values[$key] ?? null;
    }

    public function set(string $key, mixed $value): void
    {
        if ($value !== null) {
            $this->values[$key] = $value;
        }
    }

    /**
     * Overlays another settings bag on top of this one; present keys in $other win
     */
    public function merge(self $other): self
    {
        $merged = $this->values;
        foreach ($other->values as $key => $value) {
            if ($value !== null) {
                $merged[$key] = $value;
            }
        }

        return new self($merged);
    }

    public function string(string $key, string $default): string
    {
        $value = $this->values[$key] ?? null;

        return is_scalar($value) ? (string) $value : $default;
    }

    public function int(string $key, int $default): int
    {
        $value = $this->values[$key] ?? null;

        return is_numeric($value) ? (int) $value : $default;
    }

    public function stringOrNull(string $key): ?string
    {
        $value = $this->values[$key] ?? null;

        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * @return list<string>
     */
    public function stringList(string $key): array
    {
        $value = $this->values[$key] ?? null;
        if (!is_array($value)) {
            return [];
        }
        $result = [];
        foreach ($value as $item) {
            if (is_string($item)) {
                $result[] = $item;
            }
        }

        return $result;
    }
}
