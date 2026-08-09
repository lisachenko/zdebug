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
 * End-to-end coverage for DBGp call and return breakpoints
 *
 * The two ride completely different machinery - a call breakpoint is the first EXT_STMT
 * of an op_array, a return breakpoint is the RETURN opline - so what these tests pin is
 * that each stops in the right frame at the right line: entry stops before the body has
 * run, return stops with the body's locals still readable and the frame still on the
 * stack. Both are matched by the `-m` name, which a client may spell with or without the
 * class.
 */
#[Group('integration')]
final class FunctionBreakpointSessionTest extends DbgpIntegrationTestCase
{
    public function testACallBreakpointStopsOnEntryOfEveryCall(): void
    {
        $ide     = new FakeIde(timeoutSeconds: 10.0);
        $appPath = $this->fixture('functions-app.php');

        $this->spawnChild($this->fixture('functions-entry.php'), $ide->port());
        $ide->accept();
        $ide->receive(); // <init>

        $entryLine = $this->lineOf($appPath, '$doubled = $value * 2; // handle entry');
        $set       = $this->command($ide, 'breakpoint_set -t call -m handle');
        $this->assertSame('resolved', (string) $set['resolved']);

        // handle() is called twice, so the breakpoint fires twice - once per entry
        foreach ([1, 2] as $expectedArgument) {
            $break = $this->command($ide, 'run');
            $this->assertSame('break', (string) $break['status']);
            $this->assertSame($entryLine, $this->breakLocation($break)['lineno']);

            $top = $this->command($ide, 'stack_get')->stack[0];
            $this->assertStringContainsString('handle', (string) $top['where']);

            // Stopped BEFORE the first statement ran: the argument is there, its result is not
            $locals = $this->properties($this->command($ide, 'context_get -c 0 -d 0'));
            $this->assertSame((string) $expectedArgument, $locals['$value'] ?? null);
            $this->assertArrayNotHasKey('$doubled', $locals);
        }

        // The registry counted both entries under the one breakpoint id
        $this->assertSame('2', (string) $this->command($ide, "breakpoint_get -d {$set['id']}")->breakpoint['hit_count']);

        $this->command($ide, "breakpoint_remove -d {$set['id']}");
        $this->assertSame('stopping', (string) $this->command($ide, 'run')['status']);
        $this->command($ide, 'stop');

        $ide->close();
        $this->finishChild('CALLS=12', 'FUNCTIONS APP DONE');
    }

    public function testAReturnBreakpointStopsOnTheReturnWithTheFrameStillReadable(): void
    {
        $ide     = new FakeIde(timeoutSeconds: 10.0);
        $appPath = $this->fixture('functions-app.php');

        $this->spawnChild($this->fixture('functions-entry.php'), $ide->port());
        $ide->accept();
        $ide->receive(); // <init>

        $returnLine = $this->lineOf($appPath, 'return $doubled; // handle return');
        // The class may be part of the -m name; matching it against the bound object is
        // what keeps a "Service::handle" breakpoint off some other handle()
        $set = $this->command($ide, 'breakpoint_set -t return -m Service::handle');

        $break = $this->command($ide, 'run');
        $this->assertSame('break', (string) $break['status']);
        $this->assertSame($returnLine, $this->breakLocation($break)['lineno']);

        // The returning frame is still current: its locals, including the value about to
        // be returned, are exactly what makes stopping here worth anything
        $locals = $this->properties($this->command($ide, 'context_get -c 0 -d 0'));
        $this->assertSame('2', $locals['$doubled'] ?? null);
        $this->assertSame('{main}', (string) $this->command($ide, 'stack_get')->stack[1]['where']);

        $this->assertSame('break', (string) $this->command($ide, 'run')['status'], 'the second call returns too');
        $this->assertSame('4', $this->properties($this->command($ide, 'context_get -c 0 -d 0'))['$doubled'] ?? null);

        $this->command($ide, "breakpoint_remove -d {$set['id']}");
        $this->assertSame('stopping', (string) $this->command($ide, 'run')['status']);
        $this->command($ide, 'stop');

        $ide->close();
        $this->finishChild('CALLS=12', 'FUNCTIONS APP DONE');
    }

    /**
     * A function whose only statement is its return is both entry and exit; the two
     * breakpoint types must still be told apart by which opcode they ride
     */
    public function testCallAndReturnAreIndependentOnASingleStatementFunction(): void
    {
        $ide     = new FakeIde(timeoutSeconds: 10.0);
        $appPath = $this->fixture('functions-app.php');

        $this->spawnChild($this->fixture('functions-entry.php'), $ide->port());
        $ide->accept();
        $ide->receive(); // <init>

        $line = $this->lineOf($appPath, 'return $value + 1; // helper entry and return');
        $call = $this->command($ide, 'breakpoint_set -t call -m helper');
        $exit = $this->command($ide, 'breakpoint_set -t return -m helper');

        // Entry first: the statement has not executed yet
        $break = $this->command($ide, 'run');
        $this->assertSame($line, $this->breakLocation($break)['lineno']);
        $this->assertSame('1', (string) $this->command($ide, "breakpoint_get -d {$call['id']}")->breakpoint['hit_count']);
        $this->assertSame('0', (string) $this->command($ide, "breakpoint_get -d {$exit['id']}")->breakpoint['hit_count']);

        // ... then the return of the same call
        $this->assertSame('break', (string) $this->command($ide, 'run')['status']);
        $this->assertSame('1', (string) $this->command($ide, "breakpoint_get -d {$exit['id']}")->breakpoint['hit_count']);

        // Both are reported with the function they watch, so an IDE can render them
        $listed = [];
        foreach ($this->command($ide, 'breakpoint_list')->breakpoint as $breakpoint) {
            $listed[(string) $breakpoint['type']] = (string) $breakpoint['function'];
        }
        $this->assertSame(['call' => 'helper', 'return' => 'helper'], $listed);

        $this->command($ide, "breakpoint_remove -d {$call['id']}");
        $this->command($ide, "breakpoint_remove -d {$exit['id']}");
        $this->assertSame('stopping', (string) $this->command($ide, 'run')['status']);
        $this->command($ide, 'stop');

        $ide->close();
        $this->finishChild('CALLS=12', 'FUNCTIONS APP DONE');
    }

    /**
     * A hit condition filters entries the same way it filters line-breakpoint hits
     */
    public function testAHitConditionSkipsTheFirstCall(): void
    {
        $ide     = new FakeIde(timeoutSeconds: 10.0);
        $appPath = $this->fixture('functions-app.php');

        $this->spawnChild($this->fixture('functions-entry.php'), $ide->port());
        $ide->accept();
        $ide->receive(); // <init>

        $set = $this->command($ide, 'breakpoint_set -t call -m handle -h 2 -o ==');
        $this->assertSame('break', (string) $this->command($ide, 'run')['status']);

        // The second call, not the first: $value is 2
        $this->assertSame('2', $this->properties($this->command($ide, 'context_get -c 0 -d 0'))['$value'] ?? null);

        $this->command($ide, "breakpoint_remove -d {$set['id']}");
        $this->assertSame('stopping', (string) $this->command($ide, 'run')['status']);
        $this->command($ide, 'stop');

        $ide->close();
        $this->finishChild('CALLS=12', 'FUNCTIONS APP DONE');
    }

    /**
     * A call/return breakpoint without -m is a malformed command, not a breakpoint that
     * silently watches everything
     */
    public function testAFunctionBreakpointWithoutANameIsRejected(): void
    {
        $ide = new FakeIde(timeoutSeconds: 10.0);

        $this->spawnChild($this->fixture('functions-entry.php'), $ide->port());
        $ide->accept();
        $ide->receive(); // <init>

        $this->assertSame('202', (string) $this->command($ide, 'breakpoint_set -t call')->error['code']);
        $this->assertSame('202', (string) $this->command($ide, 'breakpoint_set -t return')->error['code']);

        $this->assertSame('stopping', (string) $this->command($ide, 'run')['status']);
        $this->command($ide, 'stop');

        $ide->close();
        $this->finishChild('CALLS=12', 'FUNCTIONS APP DONE');
    }
}
