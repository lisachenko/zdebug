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
use ZDebug\Config\Settings;
use ZDebug\Config\XdebugCompat;

final class XdebugCompatTest extends TestCase
{
    /**
     * @param array<string, string> $ini
     * @param array<string, string> $env
     */
    private function compat(array $ini, array $env = []): XdebugCompat
    {
        return new XdebugCompat(
            static fn(string $name): string|false => $ini[$name] ?? false,
            static fn(string $name): string|false => $env[$name] ?? false,
        );
    }

    public function testReadsHostPortIdeKeyFromIni(): void
    {
        $settings = $this->compat([
            'xdebug.mode'        => 'debug',
            'xdebug.client_host' => '192.168.0.10',
            'xdebug.client_port' => '9004',
            'xdebug.idekey'      => 'PHPSTORM',
        ])->settings();

        $this->assertSame('192.168.0.10', $settings->get(Settings::CLIENT_HOST));
        $this->assertSame(9004, $settings->get(Settings::CLIENT_PORT));
        $this->assertSame('PHPSTORM', $settings->get(Settings::IDE_KEY));
    }

    public function testXdebugConfigEnvOverridesIni(): void
    {
        $settings = $this->compat(
            ['xdebug.mode' => 'debug', 'xdebug.client_port' => '9004'],
            ['XDEBUG_CONFIG' => 'client_host=10.0.0.1 client_port=9005 idekey=VSCODE'],
        )->settings();

        $this->assertSame('10.0.0.1', $settings->get(Settings::CLIENT_HOST));
        $this->assertSame(9005, $settings->get(Settings::CLIENT_PORT));
        $this->assertSame('VSCODE', $settings->get(Settings::IDE_KEY));
    }

    public function testXdebugModeEnvOverridesIniMode(): void
    {
        $settings = $this->compat(['xdebug.mode' => 'off'], ['XDEBUG_MODE' => 'debug'])->settings();
        $this->assertSame('debug', $settings->get(Settings::MODE));
    }

    public function testModeWithoutDebugIsOff(): void
    {
        $settings = $this->compat(['xdebug.mode' => 'develop,profile'])->settings();
        $this->assertSame('off', $settings->get(Settings::MODE));
    }

    public function testModeDebugWithStartYesIsOn(): void
    {
        $settings = $this->compat([
            'xdebug.mode'               => 'debug',
            'xdebug.start_with_request' => 'yes',
        ])->settings();
        $this->assertSame('debug', $settings->get(Settings::MODE));
    }

    public function testStartNoDisablesEvenInDebugMode(): void
    {
        $settings = $this->compat([
            'xdebug.mode'               => 'debug',
            'xdebug.start_with_request' => 'no',
        ])->settings();
        $this->assertSame('off', $settings->get(Settings::MODE));
    }

    public function testTriggerModeRequiresTriggerPresence(): void
    {
        $ini = ['xdebug.mode' => 'debug', 'xdebug.start_with_request' => 'trigger'];

        $absent = $this->compat($ini)->settings();
        $this->assertSame('off', $absent->get(Settings::MODE));

        $present = $this->compat($ini, ['XDEBUG_TRIGGER' => '1'])->settings();
        $this->assertSame('debug', $present->get(Settings::MODE));
    }

    public function testTriggerValueMustMatchWhenConfigured(): void
    {
        $ini = [
            'xdebug.mode'               => 'debug',
            'xdebug.start_with_request' => 'trigger',
            'xdebug.trigger_value'      => 'secret',
        ];

        $wrong = $this->compat($ini, ['XDEBUG_TRIGGER' => 'nope'])->settings();
        $this->assertSame('off', $wrong->get(Settings::MODE));

        $right = $this->compat($ini, ['XDEBUG_TRIGGER' => 'secret'])->settings();
        $this->assertSame('debug', $right->get(Settings::MODE));
    }

    public function testXdebugSessionEnvActsAsTriggerAndIdeKey(): void
    {
        $settings = $this->compat(
            ['xdebug.mode' => 'debug', 'xdebug.start_with_request' => 'trigger'],
            ['XDEBUG_SESSION' => 'mysession'],
        )->settings();

        $this->assertSame('debug', $settings->get(Settings::MODE));
        $this->assertSame('mysession', $settings->get(Settings::IDE_KEY));
    }

    public function testNoXdebugConfigContributesNoMode(): void
    {
        // Nothing configured: XdebugCompat must not force a mode (the default layer wins)
        $settings = $this->compat([])->settings();
        $this->assertFalse($settings->has(Settings::MODE));
        $this->assertFalse($settings->has(Settings::CLIENT_HOST));
    }
}
