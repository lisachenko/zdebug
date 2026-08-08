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

use ZDebug\Config;

/**
 * Builds the effective Config by layering the configuration sources in precedence order
 *
 * Only a source's *actual* opinions are layered - Xdebug's ini/env first, then zdebug's
 * own ZDEBUG_* environment, then an explicit array passed to Debugger::attach(). Because
 * each layer overrides only the keys it sets, Xdebug is a pure per-key fallback: it fills
 * in only what your own ZDEBUG_* values leave unspecified, and never overrides them. The
 * built-in defaults are applied last of all, when no source had an opinion at all - and
 * they are not restated here: they are read off a default-constructed Config, whose
 * promoted-property defaults are their single source of truth.
 */
final class ConfigResolver
{
    public function __construct(
        private readonly XdebugCompat $xdebug = new XdebugCompat(),
    ) {}

    /**
     * @param array<string, mixed> $overrides Explicit settings (highest precedence)
     */
    public function resolve(array $overrides = []): Config
    {
        // Xdebug (fallback) < ZDEBUG_* (own) < explicit array. No defaults layer competes
        // here: an unset key simply falls through to the corresponding Config default.
        $settings = $this->xdebug->settings()
            ->merge($this->zdebugEnvironment())
            ->merge(new Settings($overrides));

        $defaults = new Config();

        return new Config(
            clientHost: $settings->string(Setting::ClientHost, $defaults->clientHost),
            clientPort: $settings->int(Setting::ClientPort, $defaults->clientPort),
            ideKey: $settings->string(Setting::IdeKey, $defaults->ideKey),
            pathFilter: $settings->stringList(Setting::PathFilter, $defaults->pathFilter),
            connectTimeoutMs: $settings->int(Setting::ConnectTimeoutMs, $defaults->connectTimeoutMs),
            readTimeoutMs: $settings->int(Setting::ReadTimeoutMs, $defaults->readTimeoutMs),
            mode: $settings->string(Setting::Mode, $defaults->mode),
            logFile: $settings->stringOrNull(Setting::Log) ?? $defaults->logFile,
        );
    }

    /**
     * Convenience: resolve using only the ambient configuration sources
     */
    public static function fromEnvironment(): Config
    {
        return (new self())->resolve();
    }

    /**
     * Reads zdebug's native ZDEBUG_* environment variables
     */
    private function zdebugEnvironment(): Settings
    {
        $settings = new Settings();

        $settings->set(Setting::ClientHost, self::env('ZDEBUG_CLIENT_HOST'));
        $port = self::env('ZDEBUG_CLIENT_PORT');
        $settings->set(Setting::ClientPort, $port !== null ? (int) $port : null);
        $settings->set(Setting::IdeKey, self::env('ZDEBUG_IDEKEY') ?? self::env('DBGP_IDEKEY'));
        $timeout = self::env('ZDEBUG_CONNECT_TIMEOUT_MS');
        $settings->set(Setting::ConnectTimeoutMs, $timeout !== null ? (int) $timeout : null);
        $readTimeout = self::env('ZDEBUG_READ_TIMEOUT_MS');
        $settings->set(Setting::ReadTimeoutMs, $readTimeout !== null ? (int) $readTimeout : null);
        $settings->set(Setting::Mode, self::env('ZDEBUG_MODE'));
        $settings->set(Setting::Log, self::env('ZDEBUG_LOG'));

        $pathFilter = self::env('ZDEBUG_PATH_FILTER');
        if ($pathFilter !== null && $pathFilter !== '') {
            $prefixes = [];
            foreach (explode(PATH_SEPARATOR, $pathFilter) as $prefix) {
                $normalized = self::normalizePrefix($prefix);
                if ($normalized !== null) {
                    $prefixes[] = $normalized;
                }
            }
            $settings->set(Setting::PathFilter, $prefixes);
        }

        return $settings;
    }

    private static function env(string $name): ?string
    {
        $value = getenv($name);

        return $value === false || $value === '' ? null : $value;
    }

    private static function normalizePrefix(string $prefix): ?string
    {
        $prefix = trim($prefix);
        if ($prefix === '') {
            return null;
        }
        $real = realpath($prefix);

        return $real !== false ? $real : $prefix;
    }
}
