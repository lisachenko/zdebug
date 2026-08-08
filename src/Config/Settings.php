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
    /**
     * @param array<string, mixed> $values Keyed by Setting backing values
     */
    public function __construct(private array $values = []) {}

    public function has(Setting $key): bool
    {
        return array_key_exists($key->value, $this->values) && $this->values[$key->value] !== null;
    }

    public function get(Setting $key): mixed
    {
        return $this->values[$key->value] ?? null;
    }

    public function set(Setting $key, mixed $value): void
    {
        if ($value !== null) {
            $this->values[$key->value] = $value;
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

    public function string(Setting $key, string $default): string
    {
        $value = $this->get($key);

        return is_scalar($value) ? (string) $value : $default;
    }

    public function int(Setting $key, int $default): int
    {
        $value = $this->get($key);

        return is_numeric($value) ? (int) $value : $default;
    }

    public function stringOrNull(Setting $key): ?string
    {
        $value = $this->get($key);

        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * @return list<string>
     */
    public function stringList(Setting $key): array
    {
        $value = $this->get($key);
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
