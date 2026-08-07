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
 * Immutable, fully-resolved debugger configuration
 *
 * The layering of configuration sources (Xdebug ini/env, the ZDEBUG_* environment,
 * explicit overrides) lives in ZDebug\Config\ConfigResolver; this class is just the
 * resolved result, which keeps it trivially unit-testable.
 */
final class Config
{
    /**
     * @param string       $clientHost       Host the IDE listens on (the debugger connects OUT to it)
     * @param int          $clientPort       Port the IDE listens on (Xdebug 3 default: 9003)
     * @param string       $ideKey           Session key echoed to the IDE in the <init> packet
     * @param list<string> $pathFilter       Realpath prefixes whose code is observed; empty = observe everything
     * @param int          $connectTimeoutMs Connect timeout in milliseconds; on failure the app runs undebugged
     * @param string       $mode             'debug' to arm the debugger, 'off' to become a no-op
     * @param string|null  $logFile          Absolute path for the diagnostics log, or null to disable
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
     * Builds a configuration from the ambient sources (Xdebug ini/env + ZDEBUG_*)
     *
     * Delegates to ConfigResolver, which layers Xdebug's own configuration under the
     * native ZDEBUG_* variables so an existing Xdebug setup drives zdebug unchanged.
     */
    public static function fromEnvironment(): self
    {
        return Config\ConfigResolver::fromEnvironment();
    }

    /**
     * Whether the debugger is armed (mode !== 'off')
     */
    public function isEnabled(): bool
    {
        return $this->mode !== 'off';
    }
}
