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
 * Immutable debugger configuration
 *
 * Mirrors the shape of Xdebug's XDEBUG_CONFIG/XDEBUG_MODE settings, namespaced under
 * ZDEBUG_* so the two can coexist in one environment. fromEnvironment() reads the
 * process environment; the constructor keeps the object trivially unit-testable.
 */
final class Config
{
    /**
     * @param string   $clientHost      Host the IDE listens on (the debugger connects OUT to it)
     * @param int      $clientPort      Port the IDE listens on (Xdebug 3 default: 9003)
     * @param string   $ideKey          Session key echoed to the IDE in the <init> packet
     * @param string[] $pathFilter      Realpath prefixes whose code is observed; empty = observe everything
     * @param int      $connectTimeoutMs Connect timeout in milliseconds; on failure the app runs undebugged
     * @param string   $mode            'debug' to arm the debugger, 'off' to become a no-op
     * @param string|null $logFile      Absolute path for the diagnostics log, or null to disable
     */
    public function __construct(
        public readonly string $clientHost = '127.0.0.1',
        public readonly int $clientPort = 9003,
        public readonly string $ideKey = 'zdebug',
        public readonly array $pathFilter = [],
        public readonly int $connectTimeoutMs = 200,
        public readonly string $mode = 'debug',
        public readonly ?string $logFile = null,
    ) {}

    /**
     * Builds a configuration from the ZDEBUG_* environment variables
     *
     * ZDEBUG_CLIENT_HOST, ZDEBUG_CLIENT_PORT, ZDEBUG_IDEKEY (falls back to the
     * DBGP_IDEKEY convention), ZDEBUG_PATH_FILTER (a PATH_SEPARATOR-separated list of
     * prefixes), ZDEBUG_CONNECT_TIMEOUT_MS, ZDEBUG_MODE, ZDEBUG_LOG.
     */
    public static function fromEnvironment(): self
    {
        $pathFilterRaw = self::env('ZDEBUG_PATH_FILTER');
        $pathFilter    = [];
        if ($pathFilterRaw !== null && $pathFilterRaw !== '') {
            foreach (explode(PATH_SEPARATOR, $pathFilterRaw) as $prefix) {
                $normalized = self::normalizePrefix($prefix);
                if ($normalized !== null) {
                    $pathFilter[] = $normalized;
                }
            }
        }

        $port    = self::env('ZDEBUG_CLIENT_PORT');
        $timeout = self::env('ZDEBUG_CONNECT_TIMEOUT_MS');
        $ideKey  = self::env('ZDEBUG_IDEKEY') ?? self::env('DBGP_IDEKEY') ?? 'zdebug';

        return new self(
            clientHost: self::env('ZDEBUG_CLIENT_HOST') ?? '127.0.0.1',
            clientPort: $port !== null ? (int) $port : 9003,
            ideKey: $ideKey,
            pathFilter: $pathFilter,
            connectTimeoutMs: $timeout !== null ? (int) $timeout : 200,
            mode: self::env('ZDEBUG_MODE') ?? 'debug',
            logFile: self::env('ZDEBUG_LOG'),
        );
    }

    /**
     * Whether the debugger is armed (mode !== 'off')
     */
    public function isEnabled(): bool
    {
        return $this->mode !== 'off';
    }

    private static function env(string $name): ?string
    {
        $value = getenv($name);

        return $value === false ? null : $value;
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
