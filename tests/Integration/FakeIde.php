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

/**
 * A minimal DBGp client that plays the role of the IDE in the integration test
 *
 * Listens on an ephemeral port, accepts the debugger's outbound connection, then reads
 * length-prefixed XML packets and writes NUL-terminated command lines. Every read is
 * bounded by a timeout so a hung debuggee fails the test instead of blocking forever.
 */
final class FakeIde
{
    /** @var resource */
    private $server;

    /** @var resource|null */
    private $client = null;

    private int $transaction = 0;

    public function __construct(private readonly float $timeoutSeconds = 10.0)
    {
        $errno  = 0;
        $errstr = '';
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($server === false) {
            throw new \RuntimeException("Cannot open listening socket: {$errstr}");
        }
        $this->server = $server;
    }

    public function port(): int
    {
        $name = stream_socket_get_name($this->server, false);
        if ($name === false) {
            throw new \RuntimeException('Cannot resolve listening port');
        }

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }

    /**
     * Blocks until the debuggee connects (or the timeout elapses)
     */
    public function accept(): void
    {
        $client = @stream_socket_accept($this->server, $this->timeoutSeconds);
        if ($client === false) {
            throw new \RuntimeException('Debuggee did not connect within the timeout');
        }
        stream_set_timeout($client, (int) $this->timeoutSeconds);
        $this->client = $client;
    }

    /**
     * Sends a command, returning the transaction id used
     */
    public function send(string $command): int
    {
        $transactionId = ++$this->transaction;
        // Insert -i right after the command verb so the remaining options stay valid
        $parts = explode(' ', $command, 2);
        $line  = $parts[0] . ' -i ' . $transactionId . (isset($parts[1]) ? ' ' . $parts[1] : '');
        fwrite($this->requireClient(), $line . "\0");

        return $transactionId;
    }

    /**
     * Reads the next `<length> NUL <xml> NUL` packet and returns it parsed
     */
    public function receive(): \SimpleXMLElement
    {
        $client    = $this->requireClient();
        $length    = $this->readUntilNul($client);
        $payload   = '';
        $remaining = (int) $length;
        while (strlen($payload) < $remaining) {
            $want  = $remaining - strlen($payload);
            $chunk = fread($client, max($want, 1));
            if ($chunk === false || $chunk === '') {
                if (feof($client)) {
                    throw new \RuntimeException('Connection closed mid-packet');
                }
                continue;
            }
            $payload .= $chunk;
        }
        // Consume the trailing NUL
        fread($client, 1);

        $xml = simplexml_load_string($payload);
        if ($xml === false) {
            throw new \RuntimeException("Malformed XML packet: {$payload}");
        }

        return $xml;
    }

    public function close(): void
    {
        if ($this->client !== null) {
            @fclose($this->client);
            $this->client = null;
        }
        @fclose($this->server);
    }

    /**
     * @param resource $client
     */
    private function readUntilNul($client): string
    {
        $buffer = '';
        while (true) {
            $char = fread($client, 1);
            if ($char === false || $char === '') {
                if (feof($client)) {
                    throw new \RuntimeException('Connection closed before packet length');
                }
                $meta = stream_get_meta_data($client);
                if ($meta['timed_out'] === true) {
                    throw new \RuntimeException('Timed out waiting for a packet');
                }
                continue;
            }
            if ($char === "\0") {
                return $buffer;
            }
            $buffer .= $char;
        }
    }

    /**
     * @return resource
     */
    private function requireClient()
    {
        if ($this->client === null) {
            throw new \RuntimeException('No client connection; call accept() first');
        }

        return $this->client;
    }
}
