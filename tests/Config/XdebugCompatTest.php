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
use ZDebug\Config\Setting;
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

        $this->assertSame('192.168.0.10', $settings->get(Setting::ClientHost));
        $this->assertSame(9004, $settings->get(Setting::ClientPort));
        $this->assertSame('PHPSTORM', $settings->get(Setting::IdeKey));
    }

    public function testXdebugConfigEnvOverridesIni(): void
    {
        $settings = $this->compat(
            ['xdebug.mode' => 'debug', 'xdebug.client_port' => '9004'],
            ['XDEBUG_CONFIG' => 'client_host=10.0.0.1 client_port=9005 idekey=VSCODE'],
        )->settings();

        $this->assertSame('10.0.0.1', $settings->get(Setting::ClientHost));
        $this->assertSame(9005, $settings->get(Setting::ClientPort));
        $this->assertSame('VSCODE', $settings->get(Setting::IdeKey));
    }

    public function testXdebugModeEnvOverridesIniMode(): void
    {
        $settings = $this->compat(['xdebug.mode' => 'off'], ['XDEBUG_MODE' => 'debug'])->settings();
        $this->assertSame('debug', $settings->get(Setting::Mode));
    }

    public function testModeWithoutDebugIsOff(): void
    {
        $settings = $this->compat(['xdebug.mode' => 'develop,profile'])->settings();
        $this->assertSame('off', $settings->get(Setting::Mode));
    }

    public function testModeDebugWithStartYesIsOn(): void
    {
        $settings = $this->compat([
            'xdebug.mode'               => 'debug',
            'xdebug.start_with_request' => 'yes',
        ])->settings();
        $this->assertSame('debug', $settings->get(Setting::Mode));
    }

    public function testStartNoDisablesEvenInDebugMode(): void
    {
        $settings = $this->compat([
            'xdebug.mode'               => 'debug',
            'xdebug.start_with_request' => 'no',
        ])->settings();
        $this->assertSame('off', $settings->get(Setting::Mode));
    }

    public function testTriggerModeRequiresTriggerPresence(): void
    {
        $ini = ['xdebug.mode' => 'debug', 'xdebug.start_with_request' => 'trigger'];

        $absent = $this->compat($ini)->settings();
        $this->assertSame('off', $absent->get(Setting::Mode));

        $present = $this->compat($ini, ['XDEBUG_TRIGGER' => '1'])->settings();
        $this->assertSame('debug', $present->get(Setting::Mode));
    }

    public function testTriggerValueMustMatchWhenConfigured(): void
    {
        $ini = [
            'xdebug.mode'               => 'debug',
            'xdebug.start_with_request' => 'trigger',
            'xdebug.trigger_value'      => 'secret',
        ];

        $wrong = $this->compat($ini, ['XDEBUG_TRIGGER' => 'nope'])->settings();
        $this->assertSame('off', $wrong->get(Setting::Mode));

        $right = $this->compat($ini, ['XDEBUG_TRIGGER' => 'secret'])->settings();
        $this->assertSame('debug', $right->get(Setting::Mode));
    }

    public function testXdebugSessionEnvActsAsTriggerAndIdeKey(): void
    {
        $settings = $this->compat(
            ['xdebug.mode' => 'debug', 'xdebug.start_with_request' => 'trigger'],
            ['XDEBUG_SESSION' => 'mysession'],
        )->settings();

        $this->assertSame('debug', $settings->get(Setting::Mode));
        $this->assertSame('mysession', $settings->get(Setting::IdeKey));
    }

    public function testNoXdebugConfigContributesNoMode(): void
    {
        // Nothing configured: XdebugCompat must not force a mode (the default layer wins)
        $settings = $this->compat([])->settings();
        $this->assertFalse($settings->has(Setting::Mode));
        $this->assertFalse($settings->has(Setting::ClientHost));
    }
}
