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
 *
 * The blocking read is bounded: a suspended debuggee sits inside an opcode handler, so
 * an IDE that stops answering (wedged process, dropped network - anything short of a
 * clean FIN, which surfaces as EOF) would otherwise hang the host application forever.
 * Once the read timeout elapses the peer is treated as gone, exactly like a closed
 * socket, and the script is allowed to run to completion undebugged.
 */
final class DbgpConnection
{
    /**
     * Read granularity; a command longer than this is reassembled across reads
     */
    private const int READ_CHUNK_BYTES = 8192;

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
     *
     * @param int $timeoutMs     Connect timeout
     * @param int $readTimeoutMs How long a read may block before the IDE counts as gone;
     *                           0 or less leaves the stream unbounded (waits forever)
     */
    public static function connect(string $host, int $port, int $timeoutMs, int $readTimeoutMs = 0): ?self
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

        return self::fromStream($stream, $readTimeoutMs);
    }

    /**
     * Wraps an already-connected stream (used by tests and by pre-established sockets)
     *
     * @param resource $stream
     * @param int      $readTimeoutMs See connect(); 0 or less leaves the stream unbounded
     */
    public static function fromStream($stream, int $readTimeoutMs = 0): self
    {
        stream_set_blocking($stream, true);
        if ($readTimeoutMs > 0) {
            // php.ini's default_socket_timeout (60s by default) would otherwise apply, and
            // the old read loop simply span on it - an unbounded wait in all but name
            stream_set_timeout($stream, intdiv($readTimeoutMs, 1000), ($readTimeoutMs % 1000) * 1000);
        }

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
     * Blocks for the next NUL-terminated command line, or null when the peer is gone
     *
     * "Gone" covers both a closed socket and a read that outlived the stream timeout: the
     * caller reacts to null the same way in either case - stop debugging and let the
     * script run - so an unresponsive IDE degrades exactly like a disconnected one.
     */
    public function receive(): ?string
    {
        if ($this->stream === null) {
            return null;
        }
        $buffer = '';
        while (true) {
            // Reads up to (and consuming) the NUL terminator, so a batch of pipelined
            // commands is split correctly and only one command is taken per call
            $chunk = @stream_get_line($this->stream, self::READ_CHUNK_BYTES, "\0");
            if ($chunk === false) {
                // EOF, or the timeout elapsed with no terminator in sight: either way the
                // IDE is not talking to us any more, and a half-read command is worthless
                $this->close();

                return null;
            }
            $buffer .= $chunk;
            if (strlen($chunk) < self::READ_CHUNK_BYTES) {
                // A short read means the terminator (or EOF) was reached
                return $buffer;
            }
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
