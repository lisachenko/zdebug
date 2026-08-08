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
 * An IDE that stops answering must not hold the debuggee hostage
 *
 * A suspended debuggee blocks on the socket from inside an opcode handler, so a peer
 * that keeps the connection open but never sends another command (wedged IDE, dead
 * network - anything that is not a clean FIN) used to hang the host application forever.
 * The bounded read turns that into the same silent degradation as an unreachable IDE.
 */
#[Group('integration')]
final class SilentIdeTest extends DbgpIntegrationTestCase
{
    public function testTheDebuggeeGivesUpOnASilentIdeAndRunsToCompletion(): void
    {
        // The fixture pins read_timeout_ms to 1s; everything else comes from the env
        $ide = new FakeIde(timeoutSeconds: 10.0);
        $this->spawnChild($this->fixture('silent-ide-entry.php'), $ide->port());
        $ide->accept();

        // The session opens normally: <init> arrives and the debuggee waits for commands
        $init = $ide->receive();
        $this->assertSame('init', $init->getName());

        // ... and then this "IDE" says nothing at all, ever. Once the read timeout
        // elapses the debuggee must treat the peer as gone and run the script anyway.
        $started = microtime(true);
        $this->finishChild('RESULT=35', 'APP DONE');
        $elapsed = microtime(true) - $started;

        $this->assertLessThan(10.0, $elapsed, 'the debuggee must give up quickly, not hang');
        $ide->close();
    }
}
