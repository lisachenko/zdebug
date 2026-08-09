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
 *
 * The promoted-property defaults below are the single source of truth for the built-in
 * defaults: ConfigResolver reads them off a default-constructed instance instead of
 * restating the literals, so `new Config()` and "nothing configured anywhere" agree by
 * construction.
 */
final class Config
{
    public function __construct(
        /** @var string Host the IDE listens on (the debugger connects OUT to it) */
        public readonly string $clientHost = '127.0.0.1',
        /** @var int Port the IDE listens on (Xdebug 3 default: 9003) */
        public readonly int $clientPort = 9003,
        /** @var string Session key echoed to the IDE in the <init> packet */
        public readonly string $ideKey = 'zdebug',
        /** @var list<string> Realpath prefixes whose code is observed; empty = observe everything */
        public readonly array $pathFilter = [],
        /** @var int Connect timeout in milliseconds; on failure the app runs undebugged */
        public readonly int $connectTimeoutMs = 200,
        /**
         * @var int How long a suspended debuggee waits for the next IDE command before
         *          treating the IDE as gone and running on undebugged; 0 (or less) waits
         *          forever, as Xdebug does
         */
        public readonly int $readTimeoutMs = 300_000,
        /** @var string 'debug' to arm the debugger, 'off' to become a no-op */
        public readonly string $mode = 'debug',
        /** @var string|null Absolute path for the diagnostics log, or null to disable */
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

    /** Whether the debugger is armed (mode !== 'off') */
    public bool $isEnabled {
        get => $this->mode !== 'off';
    }
}
