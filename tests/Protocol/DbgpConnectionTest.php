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

namespace ZDebug\Tests\Protocol;

use PHPUnit\Framework\TestCase;
use ZDebug\Protocol\DbgpConnection;

/**
 * Transport-level behaviour of the DBGp socket, driven over a real socket pair
 *
 * The peer end plays the IDE: it writes NUL-terminated command lines and reads the
 * `<length> NUL <payload> NUL` frames back, which is exactly what FakeIde does in the
 * integration suite - only here the interesting failure modes (silence, half-open
 * peers, oversized commands) can be produced on demand.
 */
final class DbgpConnectionTest extends TestCase
{
    /** @var resource */
    private $peer;

    private DbgpConnection $connection;

    protected function tearDown(): void
    {
        if (is_resource($this->peer)) {
            fclose($this->peer);
        }
        $this->connection->close();
    }

    public function testReceiveReturnsOneCommandPerNulTerminatedLine(): void
    {
        $this->connect();
        // Pipelined in a single write, as a fast IDE may well send them
        fwrite($this->peer, "status -i 1\0stack_get -i 2 -d 0\0");

        $this->assertSame('status -i 1', $this->connection->receive());
        $this->assertSame('stack_get -i 2 -d 0', $this->connection->receive());
    }

    public function testReceiveKeepsPayloadBytesIntactIncludingWhitespaceAndBase64(): void
    {
        $this->connect();
        $command = 'eval -i 7 -- ' . base64_encode('$seed * 10 + $doubled');
        fwrite($this->peer, $command . "\0");

        $this->assertSame($command, $this->connection->receive());
    }

    public function testReceiveReassemblesACommandLongerThanOneRead(): void
    {
        $this->connect();
        // Comfortably past the 8 KiB read granularity, so the line spans several reads
        $expression = str_repeat('x', 40_000);
        $command    = 'eval -i 1 -- ' . base64_encode("'{$expression}'");
        fwrite($this->peer, $command . "\0");

        $received = $this->connection->receive();
        $this->assertSame($command, $received);
        $this->assertGreaterThan(40_000, strlen((string) $received));
    }

    public function testAnEmptyCommandLineIsNotMistakenForADroppedPeer(): void
    {
        $this->connect();
        fwrite($this->peer, "\0run -i 1\0");

        // '' is a malformed command the session logs and skips - null would mean "gone"
        $this->assertSame('', $this->connection->receive());
        $this->assertSame('run -i 1', $this->connection->receive());
    }

    public function testReceiveReportsThePeerAsGoneWhenTheIdeCloses(): void
    {
        $this->connect();
        fclose($this->peer);

        $this->assertNull($this->connection->receive());
        $this->assertFalse($this->connection->isConnected());
    }

    public function testAnUnterminatedTailIsStillDeliveredBeforeTheDrop(): void
    {
        $this->connect();
        fwrite($this->peer, 'detach -i 9');
        fclose($this->peer);

        $this->assertSame('detach -i 9', $this->connection->receive());
        $this->assertNull($this->connection->receive());
    }

    public function testASilentPeerIsTreatedAsGoneOnceTheReadTimeoutElapses(): void
    {
        // The peer stays open and simply never answers: without a bounded read this
        // blocks forever, inside the opcode handler, with the debuggee suspended
        $this->connect(readTimeoutMs: 250);

        $started = microtime(true);
        $this->assertNull($this->connection->receive(), 'a silent IDE reads as a gone IDE');
        $elapsed = microtime(true) - $started;

        $this->assertGreaterThanOrEqual(0.2, $elapsed, 'the read waited for the timeout');
        $this->assertLessThan(5.0, $elapsed, 'the read did not wait forever');
        // Gone is gone: nothing further is attempted on that socket
        $this->assertFalse($this->connection->isConnected());
        $this->assertNull($this->connection->receive());
    }

    public function testAPartialCommandThatNeverFinishesTimesOutInsteadOfBlocking(): void
    {
        $this->connect(readTimeoutMs: 250);
        fwrite($this->peer, 'eval -i 1 -- dHJ1bmNhdGVk');

        $this->assertNull($this->connection->receive(), 'half a command is no command');
    }

    public function testSendFramesThePayloadWithItsLength(): void
    {
        $this->connect();
        $this->connection->send('<response/>');

        $this->assertSame("11\0<response/>\0", (string) fread($this->peer, 64));
    }

    public function testSendingToAClosedPeerDropsTheConnectionInsteadOfThrowing(): void
    {
        $this->connect();
        fclose($this->peer);

        // Writing into a peerless socket raises SIGPIPE/EPIPE; the session must survive it
        $this->connection->send(str_repeat('<response/>', 10_000));
        $this->connection->send('<response/>');

        $this->assertFalse($this->connection->isConnected());
    }

    /**
     * Wires a socket pair: $peer plays the IDE, the connection plays the debugger
     */
    private function connect(int $readTimeoutMs = 0): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $this->assertIsArray($pair);
        [$debugger, $peer] = $pair;

        $this->peer       = $peer;
        $this->connection = DbgpConnection::fromStream($debugger, $readTimeoutMs);
    }
}
