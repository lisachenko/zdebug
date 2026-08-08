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

namespace ZDebug\Tests;

use PHPUnit\Framework\TestCase;
use ZDebug\Config;

final class ConfigTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $backup = [];

    private const array VARS = [
        'ZDEBUG_CLIENT_HOST', 'ZDEBUG_CLIENT_PORT', 'ZDEBUG_IDEKEY', 'DBGP_IDEKEY',
        'ZDEBUG_PATH_FILTER', 'ZDEBUG_CONNECT_TIMEOUT_MS', 'ZDEBUG_READ_TIMEOUT_MS',
        'ZDEBUG_MODE', 'ZDEBUG_LOG',
        // Cleared so an Xdebug-configured host environment cannot leak into these tests
        'XDEBUG_MODE', 'XDEBUG_CONFIG', 'XDEBUG_SESSION', 'XDEBUG_TRIGGER',
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

    public function testDefaultsMatchXdebug3Conventions(): void
    {
        // Muted readers: the built-in defaults must not depend on the host php.ini,
        // where an ambient Xdebug config (CI images ship xdebug.mode=off) leaks in
        // through get_cfg_var()
        $mute   = static fn(string $name): false => false;
        $config = (new Config\ConfigResolver(new Config\XdebugCompat($mute, $mute)))->resolve();
        $this->assertSame('127.0.0.1', $config->clientHost);
        $this->assertSame(9003, $config->clientPort);
        $this->assertSame('zdebug', $config->ideKey);
        $this->assertSame([], $config->pathFilter);
        $this->assertTrue($config->isEnabled());
    }

    public function testReadsEnvironmentOverrides(): void
    {
        putenv('ZDEBUG_CLIENT_HOST=10.0.0.5');
        putenv('ZDEBUG_CLIENT_PORT=9009');
        putenv('ZDEBUG_IDEKEY=phpstorm');
        putenv('ZDEBUG_CONNECT_TIMEOUT_MS=500');
        putenv('ZDEBUG_READ_TIMEOUT_MS=30000');

        $config = Config::fromEnvironment();
        $this->assertSame('10.0.0.5', $config->clientHost);
        $this->assertSame(9009, $config->clientPort);
        $this->assertSame('phpstorm', $config->ideKey);
        $this->assertSame(500, $config->connectTimeoutMs);
        $this->assertSame(30000, $config->readTimeoutMs);
    }

    public function testIdeKeyFallsBackToDbgpConvention(): void
    {
        putenv('DBGP_IDEKEY=fromdbgp');
        $this->assertSame('fromdbgp', Config::fromEnvironment()->ideKey);
    }

    public function testModeOffDisablesTheDebugger(): void
    {
        putenv('ZDEBUG_MODE=off');
        $this->assertFalse(Config::fromEnvironment()->isEnabled());
    }

    public function testPathFilterSplitsOnPathSeparatorAndRealpaths(): void
    {
        $dir = sys_get_temp_dir();
        putenv('ZDEBUG_PATH_FILTER=' . $dir . PATH_SEPARATOR . '/nonexistent-xyz');

        $config = Config::fromEnvironment();
        $this->assertContains(realpath($dir), $config->pathFilter);
        // A non-resolvable prefix is kept verbatim rather than dropped
        $this->assertContains('/nonexistent-xyz', $config->pathFilter);
    }
}
