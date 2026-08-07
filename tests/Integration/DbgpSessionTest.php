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
 * End-to-end DBGp session: this process plays the IDE, a child process runs the
 * instrumented debuggee, and a real breakpoint is hit with inspectable locals.
 *
 * The child is spawned only when FFI is usable on this platform; otherwise the test
 * self-skips (the CI `test:integration` step fails on skips so a broken FFI setup is
 * caught rather than passing silently).
 */
#[Group('integration')]
final class DbgpSessionTest extends TestCase
{
    /** @var resource|null */
    private $process = null;

    /** @var array<int, resource> */
    private array $pipes = [];

    protected function setUp(): void
    {
        if (!extension_loaded('ffi')) {
            $this->markTestSkipped('ext-ffi is required for the integration session');
        }
        if (PHP_OS_FAMILY !== 'Linux' || PHP_INT_SIZE !== 8) {
            $this->markTestSkipped('z-engine ships definitions for linux-x64 only');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                @fclose($pipe);
            }
        }
        if (is_resource($this->process)) {
            @proc_terminate($this->process);
            @proc_close($this->process);
        }
    }

    public function testFullBreakpointSession(): void
    {
        $ide     = new FakeIde(timeoutSeconds: 10.0);
        $appPath = realpath(__DIR__ . '/fixtures/app.php');
        $this->assertIsString($appPath);

        $this->spawnChild($ide->port());
        $ide->accept();

        // 1. init packet
        $init = $ide->receive();
        $this->assertSame('init', $init->getName());
        $this->assertSame('PHP', (string) $init['language']);
        $this->assertSame('1.0', (string) $init['protocol_version']);
        $this->assertSame('phpunit', (string) $init['idekey']);
        $this->assertStringEndsWith('entry.php', (string) $init['fileuri']);

        // 2. feature negotiation + status in the starting state
        $feature = $this->command($ide, 'feature_set -n max_depth -v 2');
        $this->assertSame('1', (string) $feature['success']);

        $status = $this->command($ide, 'status');
        $this->assertSame('starting', (string) $status['status']);

        // 3. set a line breakpoint on the `$result = $doubled + $tripled;` statement
        $bpLine = $this->lineOf($appPath, '$result  = $doubled + $tripled;');
        $set    = $this->command($ide, "breakpoint_set -t line -f file://{$appPath} -n {$bpLine}");
        $this->assertNotSame('', (string) $set['id']);
        $this->assertSame('resolved', (string) $set['resolved']);

        // 4. run -> the debuggee executes until the breakpoint, then reports break
        $break = $this->command($ide, 'run');
        $this->assertSame('break', (string) $break['status']);

        // 5. stack_get: innermost frame is compute() at the breakpoint line
        $stack  = $this->command($ide, 'stack_get');
        $frames = $stack->children();
        $this->assertNotNull($frames);
        $top = $stack->stack[0];
        $this->assertSame((string) $bpLine, (string) $top['lineno']);
        $this->assertStringContainsString('compute', (string) $top['where']);
        $this->assertStringEndsWith('app.php', (string) $top['filename']);

        // 6. context_get: locals show the values assigned so far
        $context = $this->command($ide, 'context_get -c 0 -d 0');
        $locals  = $this->properties($context);
        $this->assertSame('7', $locals['$seed'] ?? null, 'seed argument visible');
        $this->assertSame('14', $locals['$doubled'] ?? null, 'doubled computed before the breakpoint');
        $this->assertSame('21', $locals['$tripled'] ?? null, 'tripled computed before the breakpoint');
        // $result is not yet assigned at this statement
        $this->assertArrayNotHasKey('$result', $locals);

        // 7. remove the breakpoint and run to completion
        $this->command($ide, "breakpoint_remove -d {$set['id']}");
        $end = $this->command($ide, 'run');
        $this->assertSame('stopping', (string) $end['status']);

        $stop = $this->command($ide, 'stop');
        $this->assertSame('stopped', (string) $stop['status']);

        $ide->close();
        $this->assertChildFinishedCleanly();
    }

    public function testRunsUndebuggedWhenIdeIsUnreachable(): void
    {
        // No FakeIde listening: the debuggee must degrade silently and finish normally
        $freePort = $this->reserveFreePort();
        $this->spawnChild($freePort, connectTimeoutMs: 150);

        $this->assertChildFinishedCleanly();
    }

    private function spawnChild(int $port, int $connectTimeoutMs = 2000): void
    {
        $command = [
            PHP_BINARY,
            '-d', 'ffi.enable=1',
            '-d', 'zend.assertions=1',
            '-d', 'opcache.jit=off',
            __DIR__ . '/fixtures/entry.php',
        ];
        $env = [
            'ZDEBUG_CLIENT_HOST'        => '127.0.0.1',
            'ZDEBUG_CLIENT_PORT'        => (string) $port,
            'ZDEBUG_IDEKEY'             => 'phpunit',
            'ZDEBUG_PATH_FILTER'        => __DIR__ . '/fixtures',
            'ZDEBUG_CONNECT_TIMEOUT_MS' => (string) $connectTimeoutMs,
        ] + $this->inheritedEnv();

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process     = proc_open($command, $descriptors, $pipes, null, $env);
        $this->assertIsResource($process, 'Unable to spawn the debuggee');
        $this->process = $process;
        $this->pipes   = $pipes;
    }

    private function assertChildFinishedCleanly(): void
    {
        $stdout = $this->drain($this->pipes[1]);
        $stderr = $this->drain($this->pipes[2]);
        $this->assertIsResource($this->process);
        $exit          = proc_close($this->process);
        $this->process = null;

        $report = "STDOUT:\n{$stdout}\nSTDERR:\n{$stderr}";
        $this->assertSame(0, $exit, "Child exited non-zero\n{$report}");
        $this->assertStringContainsString('APP DONE', $stdout, $report);
        $this->assertStringContainsString('RESULT=35', $stdout, $report);
        $this->assertStringNotContainsStringIgnoringCase('Fatal error', $stderr, $report);
    }

    private function command(FakeIde $ide, string $command): \SimpleXMLElement
    {
        $ide->send($command);

        return $ide->receive();
    }

    /**
     * @return array<string, string>
     */
    private function properties(\SimpleXMLElement $context): array
    {
        $values = [];
        foreach ($context->property as $property) {
            $name          = (string) $property['name'];
            $values[$name] = base64_decode((string) $property);
        }

        return $values;
    }

    private function lineOf(string $file, string $needle): int
    {
        $lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];
        foreach ($lines as $index => $line) {
            if (str_contains($line, $needle)) {
                return $index + 1;
            }
        }
        $this->fail("Could not find '{$needle}' in {$file}");
    }

    /**
     * @param resource $pipe
     */
    private function drain($pipe): string
    {
        if (!is_resource($pipe)) {
            return '';
        }
        stream_set_blocking($pipe, true);

        return (string) stream_get_contents($pipe);
    }

    private function reserveFreePort(): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        if ($server === false) {
            $this->fail('Cannot reserve a free port');
        }
        $name = (string) stream_socket_get_name($server, false);
        fclose($server);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }

    /**
     * @return array<string, string>
     */
    private function inheritedEnv(): array
    {
        $keep = [];
        foreach (['PATH', 'HOME', 'PHPRC'] as $name) {
            $value = getenv($name);
            if ($value !== false) {
                $keep[$name] = $value;
            }
        }

        return $keep;
    }
}
