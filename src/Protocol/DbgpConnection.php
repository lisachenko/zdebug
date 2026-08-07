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

namespace ZDebug\Protocol;

/**
 * The DBGp transport: a blocking TCP stream the debugger opens OUT to the IDE
 *
 * DBGp inverts the usual client/server roles - the debugger engine connects to the
 * IDE, which is listening. That is ideal for an in-process debugger: no accept loop,
 * no second thread, just a socket the suspended opcode handler blocks on. Commands
 * arrive NUL-terminated; responses are framed as `<decimal length> NUL <xml> NUL`.
 */
final class DbgpConnection
{
    /** @var resource|null */
    private $stream;

    /**
     * @param resource $stream A connected, blocking stream socket
     */
    private function __construct($stream)
    {
        $this->stream = $stream;
    }

    /**
     * Connects to the IDE, returning null when it is not listening (silent degradation)
     */
    public static function connect(string $host, int $port, int $timeoutMs): ?self
    {
        $errno   = 0;
        $errstr  = '';
        $address = sprintf('tcp://%s:%d', $host, $port);
        $stream  = @stream_socket_client(
            $address,
            $errno,
            $errstr,
            max($timeoutMs / 1000, 0.001),
            STREAM_CLIENT_CONNECT,
        );
        if ($stream === false) {
            return null;
        }
        stream_set_blocking($stream, true);

        return new self($stream);
    }

    /**
     * Wraps an already-connected stream (used by tests and by pre-established sockets)
     *
     * @param resource $stream
     */
    public static function fromStream($stream): self
    {
        stream_set_blocking($stream, true);

        return new self($stream);
    }

    public function isConnected(): bool
    {
        return $this->stream !== null && !feof($this->stream);
    }

    /**
     * Sends one XML payload framed as `<length> NUL <payload> NUL`
     */
    public function send(string $payload): void
    {
        if ($this->stream === null) {
            return;
        }
        $frame = strlen($payload) . "\0" . $payload . "\0";
        $this->writeAll($frame);
    }

    /**
     * Blocks for the next NUL-terminated command line, or null when the peer closed
     */
    public function receive(): ?string
    {
        if ($this->stream === null) {
            return null;
        }
        $buffer = '';
        while (true) {
            $chunk = fread($this->stream, 1);
            if ($chunk === false || $chunk === '') {
                if (feof($this->stream)) {
                    return $buffer === '' ? null : $buffer;
                }
                continue;
            }
            if ($chunk === "\0") {
                return $buffer;
            }
            $buffer .= $chunk;
        }
    }

    public function close(): void
    {
        if ($this->stream !== null) {
            @fclose($this->stream);
            $this->stream = null;
        }
    }

    private function writeAll(string $data): void
    {
        if ($this->stream === null) {
            return;
        }
        $offset = 0;
        $length = strlen($data);
        while ($offset < $length) {
            $written = @fwrite($this->stream, substr($data, $offset));
            if ($written === false || $written === 0) {
                // Peer closed mid-write: drop the connection, never block or throw in a hook
                $this->close();

                return;
            }
            $offset += $written;
        }
    }
}
