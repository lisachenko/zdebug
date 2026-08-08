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

namespace ZDebug\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that zdebug registers itself as a real engine module, so standard tooling
 * (php -m, get_loaded_extensions, extension_loaded, phpinfo) reports it like a compiled
 * extension. Runs in a child process because module registration needs the FFI engine.
 */
#[Group('integration')]
final class ModuleRegistrationTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('ffi')) {
            $this->markTestSkipped('ext-ffi is required to register a runtime module');
        }
        if (!in_array(PHP_OS_FAMILY, ['Linux', 'Darwin'], true) || PHP_INT_SIZE !== 8) {
            $this->markTestSkipped('z-engine ships definitions for 64-bit Linux and macOS only');
        }
    }

    public function testDebuggerRegistersItselfAsAnEngineModule(): void
    {
        $command = [
            PHP_BINARY,
            '-d', 'ffi.enable=1',
            // Keep opcache out of the child: its optimizer constant-folds
            // extension_loaded() at compile time, before the debugger can register
            // the runtime module, and cached oplines predate instrumentation
            '-d', 'opcache.enable_cli=0',
            '-d', 'opcache.jit=off',
            __DIR__ . '/fixtures/module-check.php',
        ];

        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);

        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        $report = "STDOUT:\n{$stdout}\nSTDERR:\n{$stderr}";
        $this->assertSame(0, $exit, $report);
        $this->assertStringContainsString('MODULE CHECK DONE', $stdout, $report);
        $this->assertStringContainsString('extension_loaded=yes', $stdout, $report);
        $this->assertStringContainsString('in_list=yes', $stdout, $report);
        $this->assertStringContainsString('version=0.1.0', $stdout, $report);
        $this->assertStringContainsString('protocol=DBGp (Xdebug-compatible)', $stdout, $report);
    }
}
