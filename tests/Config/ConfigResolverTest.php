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

namespace ZDebug\Tests\Config;

use PHPUnit\Framework\TestCase;
use ZDebug\Config;
use ZDebug\Config\ConfigResolver;
use ZDebug\Config\Setting;
use ZDebug\Config\XdebugCompat;

final class ConfigResolverTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $backup = [];

    private const array VARS = [
        'ZDEBUG_CLIENT_HOST', 'ZDEBUG_CLIENT_PORT', 'ZDEBUG_IDEKEY', 'DBGP_IDEKEY',
        'ZDEBUG_PATH_FILTER', 'ZDEBUG_CONNECT_TIMEOUT_MS', 'ZDEBUG_READ_TIMEOUT_MS',
        'ZDEBUG_MODE', 'ZDEBUG_LOG',
    ];

    protected function setUp(): void
    {
        foreach (self::VARS as $name) {
            $this->backup[$name] = getenv($name);
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->backup as $name => $value) {
            $value === false ? putenv($name) : putenv("{$name}={$value}");
        }
    }

    /**
     * A resolver whose Xdebug layer sees the given (simulated) ini + env, but whose
     * ZDEBUG_* layer reads the real (test-controlled) process environment
     *
     * @param array<string, string> $xdebugIni
     * @param array<string, string> $xdebugEnv
     */
    private function resolver(array $xdebugIni = [], array $xdebugEnv = []): ConfigResolver
    {
        return new ConfigResolver(new XdebugCompat(
            static fn(string $name): string|false => $xdebugIni[$name] ?? false,
            static fn(string $name): string|false => $xdebugEnv[$name] ?? false,
        ));
    }

    public function testDefaultsWhenNothingConfigured(): void
    {
        $config = $this->resolver()->resolve();
        $this->assertSame('127.0.0.1', $config->clientHost);
        $this->assertSame(9003, $config->clientPort);
        $this->assertSame('zdebug', $config->ideKey);
        $this->assertSame('debug', $config->mode);
    }

    public function testResolvingNothingYieldsExactlyADefaultConstructedConfig(): void
    {
        // The guard that keeps the defaults in one place: Config's promoted-property
        // defaults ARE the built-in defaults, and the resolver must not restate them.
        // A literal drifting out of sync in either file fails right here.
        $this->assertEquals(new Config(), $this->resolver()->resolve());
    }

    public function testReadTimeoutComesFromTheEnvironmentAndFallsBackToTheConfigDefault(): void
    {
        $this->assertSame((new Config())->readTimeoutMs, $this->resolver()->resolve()->readTimeoutMs);

        putenv('ZDEBUG_READ_TIMEOUT_MS=1500');
        $this->assertSame(1500, $this->resolver()->resolve()->readTimeoutMs);

        // 0 is a meaningful value (wait forever), not "unset"
        putenv('ZDEBUG_READ_TIMEOUT_MS=0');
        $this->assertSame(0, $this->resolver()->resolve()->readTimeoutMs);

        // ... and an explicit override still wins over the environment
        $config = $this->resolver()->resolve([Setting::ReadTimeoutMs->value => 250]);
        $this->assertSame(250, $config->readTimeoutMs);
    }

    public function testXdebugLayerFillsInWhenNoZdebugVars(): void
    {
        $config = $this->resolver([
            'xdebug.mode'        => 'debug',
            'xdebug.client_host' => '172.16.0.9',
            'xdebug.client_port' => '9007',
            'xdebug.idekey'      => 'IDE',
        ])->resolve();

        $this->assertSame('172.16.0.9', $config->clientHost);
        $this->assertSame(9007, $config->clientPort);
        $this->assertSame('IDE', $config->ideKey);
    }

    public function testZdebugEnvOverridesXdebug(): void
    {
        putenv('ZDEBUG_CLIENT_PORT=9999');
        putenv('ZDEBUG_IDEKEY=NATIVE');

        $config = $this->resolver([
            'xdebug.mode'        => 'debug',
            'xdebug.client_port' => '9007',
            'xdebug.idekey'      => 'IDE',
        ])->resolve();

        // ZDEBUG_* wins over Xdebug where both are present
        $this->assertSame(9999, $config->clientPort);
        $this->assertSame('NATIVE', $config->ideKey);
    }

    public function testExplicitOverridesWinOverEverything(): void
    {
        putenv('ZDEBUG_CLIENT_PORT=9999');

        $config = $this->resolver(['xdebug.mode' => 'debug', 'xdebug.client_port' => '9007'])
            ->resolve([Setting::ClientPort->value => 9001]);

        $this->assertSame(9001, $config->clientPort);
    }

    public function testXdebugDisabledByTriggerPropagatesToConfig(): void
    {
        // xdebug.mode=debug but start_with_request=trigger and no trigger present -> off
        $config = $this->resolver([
            'xdebug.mode'               => 'debug',
            'xdebug.start_with_request' => 'trigger',
        ])->resolve();

        $this->assertSame('off', $config->mode);
        $this->assertFalse($config->isEnabled);
    }

    public function testXdebugFillsOnlyTheGapsOwnValuesLeave(): void
    {
        // Own (ZDEBUG_*) specifies only the port; Xdebug supplies host + idekey; nothing
        // specifies the timeout, so the built-in default applies last.
        putenv('ZDEBUG_CLIENT_PORT=5000');

        $config = $this->resolver([
            'xdebug.mode'        => 'debug',
            'xdebug.client_host' => '10.9.9.9',
            'xdebug.client_port' => '9007',
            'xdebug.idekey'      => 'FROMXDEBUG',
        ])->resolve();

        $this->assertSame(5000, $config->clientPort, 'own value wins where specified');
        $this->assertSame('10.9.9.9', $config->clientHost, 'xdebug fills the gap');
        $this->assertSame('FROMXDEBUG', $config->ideKey, 'xdebug fills the gap');
        $this->assertSame(200, $config->connectTimeoutMs, 'hard default applies when nothing set it');
    }

    public function testZdebugModeOverridesXdebugOff(): void
    {
        putenv('ZDEBUG_MODE=debug');

        // Xdebug would say off (trigger absent), but the native ZDEBUG_MODE forces debug
        $config = $this->resolver([
            'xdebug.mode'               => 'debug',
            'xdebug.start_with_request' => 'trigger',
        ])->resolve();

        $this->assertSame('debug', $config->mode);
        $this->assertTrue($config->isEnabled);
    }
}
