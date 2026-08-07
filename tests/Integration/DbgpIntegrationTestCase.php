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

use PHPUnit\Framework\TestCase;
use ZDebug\Protocol\ResponseBuilder;

/**
 * Shared plumbing for the end-to-end DBGp tests: this process plays the IDE while a
 * child process runs the instrumented debuggee
 *
 * The child is spawned only when FFI is usable on this platform; otherwise the tests
 * self-skip (the CI `test:integration` step fails on skips so a broken FFI setup is
 * caught rather than passing silently).
 */
abstract class DbgpIntegrationTestCase extends TestCase
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

    /**
     * Absolute path of a file in the fixtures directory
     */
    protected function fixture(string $name): string
    {
        $path = realpath(__DIR__ . '/fixtures/' . $name);
        $this->assertIsString($path, "Missing fixture {$name}");

        return $path;
    }

    /**
     * Spawns the debuggee, pointed at an IDE listening on $port
     *
     * JIT must be off and ffi.enable on for the statement hook to see anything, and both
     * have to come from the command line - see AGENTS.md.
     */
    protected function spawnChild(string $entryScript, int $port, int $connectTimeoutMs = 2000): void
    {
        $command = [
            PHP_BINARY,
            '-d', 'ffi.enable=1',
            '-d', 'zend.assertions=1',
            '-d', 'opcache.enable_cli=0',
            '-d', 'opcache.jit=off',
            $entryScript,
        ];
        $env = [
            // Explicit mode: a host Xdebug config (CI images ship xdebug.mode=off)
            // must not switch the debuggee off through the compat fallback
            'ZDEBUG_MODE'               => 'debug',
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

    /**
     * Waits for the debuggee to exit and asserts it finished normally, having printed
     * every expected marker and no fatal error
     */
    protected function finishChild(string ...$expectedOutput): void
    {
        $stdout = $this->drain($this->pipes[1]);
        $stderr = $this->drain($this->pipes[2]);
        $this->assertIsResource($this->process);
        $exit          = proc_close($this->process);
        $this->process = null;

        $report = "STDOUT:\n{$stdout}\nSTDERR:\n{$stderr}";
        $this->assertSame(0, $exit, "Child exited non-zero\n{$report}");
        foreach ($expectedOutput as $expected) {
            $this->assertStringContainsString($expected, $stdout, $report);
        }
        $this->assertStringNotContainsStringIgnoringCase('Fatal error', $stderr, $report);
    }

    protected function command(FakeIde $ide, string $command): \SimpleXMLElement
    {
        $ide->send($command);

        return $ide->receive();
    }

    /**
     * The <xdebug:message> an IDE moves its cursor with, as filename + lineno
     *
     * XPath rather than ->children(NS): SimpleXML resolves attribute lookups on a
     * namespaced child against that same namespace, and these attributes carry none.
     *
     * @return array{filename: string, lineno: int}
     */
    protected function breakLocation(\SimpleXMLElement $response): array
    {
        $response->registerXPathNamespace('xdebug', ResponseBuilder::NS_XDEBUG);
        $messages = $response->xpath('//xdebug:message');
        $this->assertIsArray($messages);
        $this->assertCount(1, $messages, 'Continuation response carries no <xdebug:message>');

        return [
            'filename' => (string) $messages[0]['filename'],
            'lineno'   => (int) (string) $messages[0]['lineno'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function properties(\SimpleXMLElement $context): array
    {
        $values = [];
        foreach ($context->property as $property) {
            $name          = (string) $property['name'];
            $values[$name] = base64_decode((string) $property);
        }

        return $values;
    }

    protected function lineOf(string $file, string $needle): int
    {
        $lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];
        foreach ($lines as $index => $line) {
            if (str_contains($line, $needle)) {
                return $index + 1;
            }
        }
        $this->fail("Could not find '{$needle}' in {$file}");
    }

    protected function reserveFreePort(): int
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
