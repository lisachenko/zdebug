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

/**
 * Debugger lifecycle in a real process: booting must never break the application, and
 * detaching must actually undo what booting did
 *
 * Both ends matter because attach() runs from auto_prepend_file, before the application's
 * first statement: a boot that throws would take the app down with it, and a detach that
 * only pretends to tear down leaves the engine compiling instrumented op_arrays into a
 * process with nobody listening.
 */
#[Group('integration')]
final class LifecycleTest extends DbgpIntegrationTestCase
{
    /**
     * The realistic fatal-by-default failure: an engine the debugger cannot arm (FFI
     * disabled by policy, an unsupported build). Spawning is done locally rather than
     * through spawnChild(), which (rightly) hard-codes the ini a working session needs.
     */
    public function testAnUnarmableEngineLeavesTheApplicationRunningAndUndebugged(): void
    {
        [$exit, $stdout, $stderr] = $this->runUndebuggable($this->fixture('unusable-engine-entry.php'));

        $report = "STDOUT:\n{$stdout}\nSTDERR:\n{$stderr}";
        $this->assertSame(0, $exit, "A debugger that cannot boot must not fail the app\n{$report}");
        $this->assertStringNotContainsStringIgnoringCase('Fatal error', $stderr, $report);

        // attach() still returns, but a boot that failed registers no instance: that null
        // is what keeps the rest of the process out of the debugger's hooks
        $this->assertStringContainsString('ATTACH RETURNED', $stdout, $report);
        $this->assertStringContainsString('INSTANCE=none', $stdout, $report);

        // ... and the application itself ran to completion exactly as if zdebug were absent
        $this->assertStringContainsString('RESULT=35', $stdout, $report);
        $this->assertStringContainsString('APP DONE', $stdout, $report);
    }

    public function testDetachRestoresTheCompilerOptionsAndClosesTheIdeConnection(): void
    {
        $ide = new FakeIde(timeoutSeconds: 10.0);
        $this->spawnChild($this->fixture('detach-entry.php'), $ide->port());
        $ide->accept();

        $this->assertSame('init', $ide->receive()->getName());
        // Resuming returns control to the fixture, which detaches straight away
        $ide->send('run');

        // The IDE end sees the socket go away: detach() closed the session's connection
        // instead of merely forgetting about it. The fixture then lingers for a second,
        // so an EOF this prompt can only have come from detach(), not from process exit.
        $started = microtime(true);
        try {
            $ide->receive();
            $this->fail('detach() left the IDE connection open');
        } catch (\RuntimeException $expected) {
            $this->assertStringContainsString('closed', $expected->getMessage());
        }
        $this->assertLessThan(0.5, microtime(true) - $started, 'the socket outlived detach()');

        $ide->close();
        $this->finishChild(
            'ARMED=yes',
            'COMPILER RESTORED=yes',
            'INSTANCE=none',
            // The application still runs - just uninstrumented, like before attach()
            'RESULT=35',
            'APP DONE',
        );
    }

    /**
     * Runs an entry script with FFI switched off, returning [exit code, stdout, stderr]
     *
     * @return array{int, string, string}
     */
    private function runUndebuggable(string $entryScript): array
    {
        $command = [
            PHP_BINARY,
            '-d', 'ffi.enable=0',
            '-d', 'opcache.enable_cli=0',
            '-d', 'opcache.jit=off',
            $entryScript,
        ];
        $env = [
            'ZDEBUG_MODE'               => 'debug',
            'ZDEBUG_CLIENT_HOST'        => '127.0.0.1',
            'ZDEBUG_CLIENT_PORT'        => (string) $this->reserveFreePort(),
            'ZDEBUG_CONNECT_TIMEOUT_MS' => '100',
        ];
        foreach (['PATH', 'HOME', 'PHPRC'] as $name) {
            $value = getenv($name);
            if ($value !== false) {
                $env[$name] = $value;
            }
        }

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process     = proc_open($command, $descriptors, $pipes, null, $env);
        $this->assertIsResource($process, 'Unable to spawn the debuggee');

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }

        return [proc_close($process), $stdout, $stderr];
    }
}
