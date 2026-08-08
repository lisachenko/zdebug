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
 * End-to-end coverage for one-shot breakpoints (DBGp `breakpoint_set -r 1`)
 *
 * The loop fixture passes the breakpoint line four times, so a breakpoint that is not
 * spent by its first break would suspend the debuggee again - and the second `run` here
 * would answer `break` instead of `stopping`.
 */
#[Group('integration')]
final class TemporaryBreakpointSessionTest extends DbgpIntegrationTestCase
{
    public function testATemporaryBreakpointSuspendsExactlyOnce(): void
    {
        $ide      = new FakeIde(timeoutSeconds: 10.0);
        $loopPath = $this->fixture('loop.php');

        $this->spawnChild($this->fixture('loop-entry.php'), $ide->port());
        $ide->accept();
        $ide->receive(); // <init>

        $bpLine = $this->lineOf($loopPath, '$step  = $i * 10;');
        $set    = $this->command($ide, "breakpoint_set -t line -f file://{$loopPath} -n {$bpLine} -r 1");
        $this->assertSame('resolved', (string) $set['resolved']);

        // First pass through the loop body suspends
        $break = $this->command($ide, 'run');
        $this->assertSame('break', (string) $break['status']);
        $locals = $this->properties($this->command($ide, 'context_get -c 0 -d 0'));
        $this->assertSame('1', $locals['$i'] ?? null);

        // ... and spends the breakpoint: it is already gone while the debuggee is stopped
        $list = $this->command($ide, 'breakpoint_list');
        $this->assertFalse(isset($list->breakpoint), 'a one-shot breakpoint is removed when it breaks');
        $this->assertSame(
            205,
            (int) $this->command($ide, "breakpoint_get -d {$set['id']}")->error['code'],
            'breakpoint_get no longer knows the id',
        );

        // The remaining three iterations must run through without suspending again
        $this->assertSame('stopping', (string) $this->command($ide, 'run')['status']);
        $this->command($ide, 'stop');

        $ide->close();
        $this->finishChild('LOOP DONE', 'SUM=100');
    }

    public function testAnOrdinaryBreakpointOnTheSameLineKeepsBreaking(): void
    {
        $ide      = new FakeIde(timeoutSeconds: 10.0);
        $loopPath = $this->fixture('loop.php');

        $this->spawnChild($this->fixture('loop-entry.php'), $ide->port());
        $ide->accept();
        $ide->receive(); // <init>

        // Same line, one one-shot and one permanent: removing the first must not disturb
        // the second, which still owns the line bucket
        $bpLine    = $this->lineOf($loopPath, '$step  = $i * 10;');
        $temporary = $this->command($ide, "breakpoint_set -t line -f file://{$loopPath} -n {$bpLine} -r 1");
        $permanent = $this->command($ide, "breakpoint_set -t line -f file://{$loopPath} -n {$bpLine}");

        $this->assertSame('break', (string) $this->command($ide, 'run')['status']);
        $this->assertSame('1', $this->properties($this->command($ide, 'context_get -c 0 -d 0'))['$i'] ?? null);

        $list = $this->command($ide, 'breakpoint_list');
        $this->assertCount(1, $list->breakpoint);
        $this->assertSame((string) $permanent['id'], (string) $list->breakpoint[0]['id']);
        $this->assertNotSame((string) $temporary['id'], (string) $permanent['id']);

        // The permanent one keeps firing on the following iterations
        $this->assertSame('break', (string) $this->command($ide, 'run')['status']);
        $this->assertSame('2', $this->properties($this->command($ide, 'context_get -c 0 -d 0'))['$i'] ?? null);

        $this->command($ide, "breakpoint_remove -d {$permanent['id']}");
        $this->assertSame('stopping', (string) $this->command($ide, 'run')['status']);
        $this->command($ide, 'stop');

        $ide->close();
        $this->finishChild('LOOP DONE', 'SUM=100');
    }
}
