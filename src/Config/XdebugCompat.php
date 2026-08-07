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
 * Reads Xdebug's own ini directives and XDEBUG_* environment so an existing Xdebug
 * setup drives zdebug unchanged
 *
 * Mirrors Xdebug 3 semantics: `xdebug.mode` must contain `debug` for step debugging to
 * be active, `xdebug.start_with_request` (with XDEBUG_TRIGGER / XDEBUG_SESSION) decides
 * whether to actually connect, and `XDEBUG_CONFIG` overrides host/port/idekey. The ini
 * and environment readers are injectable so the mapping is unit-testable without a real
 * Xdebug present.
 *
 * @see https://xdebug.org/docs/all_settings
 */
final class XdebugCompat
{
    /** @var callable(string): (string|false) */
    private $ini;

    /** @var callable(string): (string|false) */
    private $env;

    /**
     * @param (callable(string): (string|false))|null $iniReader
     * @param (callable(string): (string|false))|null $envReader
     */
    public function __construct(?callable $iniReader = null, ?callable $envReader = null)
    {
        // get_cfg_var(), NOT ini_get(): zdebug replaces Xdebug, so the `xdebug.*`
        // directives belong to an extension that is not loaded. ini_get() returns false
        // for such unregistered directives, but get_cfg_var() reads them straight from
        // php.ini / -d - which is exactly where an Xdebug config lives.
        $this->ini = $iniReader ?? static function (string $name): string|false {
            $value = get_cfg_var($name);

            return is_string($value) ? $value : false;
        };
        $this->env = $envReader ?? static fn(string $name): string|false => getenv($name);
    }

    /**
     * Produces the settings implied by the current Xdebug configuration
     */
    public function settings(): Settings
    {
        $settings = new Settings();

        $xdebugConfig = $this->parseXdebugConfig();

        $host = $xdebugConfig['client_host'] ?? $this->iniString('xdebug.client_host');
        $port = $xdebugConfig['client_port'] ?? $this->iniString('xdebug.client_port');
        $log  = $xdebugConfig['log']         ?? $this->iniString('xdebug.log');

        $settings->set(Settings::CLIENT_HOST, $host);
        $settings->set(Settings::CLIENT_PORT, $port !== null ? (int) $port : null);
        $settings->set(Settings::IDE_KEY, $this->resolveIdeKey($xdebugConfig));
        $settings->set(Settings::LOG, $log);
        $settings->set(Settings::MODE, $this->resolveMode($xdebugConfig));

        return $settings;
    }

    /**
     * Resolves the effective debugging mode ('debug' or 'off')
     *
     * Step debugging runs only when the mode contains `debug` AND start_with_request
     * (evaluated against the request trigger) says to begin.
     *
     * @param array<string, string> $xdebugConfig
     */
    private function resolveMode(array $xdebugConfig): ?string
    {
        $modeValue = $this->envString('XDEBUG_MODE')
            ?? ($xdebugConfig['mode'] ?? null)
            ?? $this->iniString('xdebug.mode');
        if ($modeValue === null) {
            return null; // Xdebug is not configured here; contribute no opinion
        }

        $modes = array_map('trim', explode(',', $modeValue));
        if (!in_array('debug', $modes, true)) {
            return 'off';
        }

        return $this->shouldStart() ? 'debug' : 'off';
    }

    /**
     * Evaluates xdebug.start_with_request against the request trigger
     *
     * Matches Xdebug 3: `yes` and the `default` default both start a session with the
     * request; only `trigger` gates on the XDEBUG_TRIGGER / XDEBUG_SESSION trigger; `no`
     * never auto-starts.
     */
    private function shouldStart(): bool
    {
        $start = $this->iniString('xdebug.start_with_request') ?? 'default';

        return match ($start) {
            'no'      => false,
            'trigger' => $this->triggerPresent(),
            default   => true, // 'yes', 'default', and any unrecognized value
        };
    }

    /**
     * Whether a debug trigger (XDEBUG_TRIGGER / XDEBUG_SESSION) is present and, when a
     * trigger value is configured, matches it
     */
    private function triggerPresent(): bool
    {
        $trigger = $this->envString('XDEBUG_TRIGGER') ?? $this->envString('XDEBUG_SESSION');
        if ($trigger === null) {
            return false;
        }
        $expected = $this->iniString('xdebug.trigger_value');
        if ($expected === null || $expected === '') {
            return true;
        }

        return $trigger === $expected;
    }

    /**
     * @param array<string, string> $xdebugConfig
     */
    private function resolveIdeKey(array $xdebugConfig): ?string
    {
        return $xdebugConfig['idekey']
            ?? $this->envString('XDEBUG_SESSION')
            ?? $this->iniString('xdebug.idekey');
    }

    /**
     * Parses the XDEBUG_CONFIG environment variable (space-separated key=value pairs)
     *
     * @return array<string, string>
     */
    private function parseXdebugConfig(): array
    {
        $raw = $this->envString('XDEBUG_CONFIG');
        if ($raw === null) {
            return [];
        }
        $config = [];
        foreach (preg_split('/\s+/', trim($raw)) ?: [] as $pair) {
            if ($pair === '' || !str_contains($pair, '=')) {
                continue;
            }
            [$key, $value]                  = explode('=', $pair, 2);
            $config[strtolower(trim($key))] = trim($value);
        }

        return $config;
    }

    private function iniString(string $name): ?string
    {
        $value = ($this->ini)($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function envString(string $name): ?string
    {
        $value = ($this->env)($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
