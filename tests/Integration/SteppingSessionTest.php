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
 * End-to-end proof that step_into / step_over / step_out drive a real debuggee
 *
 * Each test plays the IDE against a freshly spawned child running stepping-app.php and
 * checks the two things an IDE actually consumes: the <xdebug:message> location the
 * continuation answers with (where the cursor lands) and the frame that context_get
 * then serves. Depth is never asserted numerically - it is asserted behaviourally, by
 * which line the debuggee stops on, which is what the depth bookkeeping exists for.
 */
#[Group('integration')]
final class SteppingSessionTest extends DbgpIntegrationTestCase
{
    private const string ENTRY = 'stepping-entry.php';
    private const string APP   = 'stepping-app.php';

    /**
     * step_over on a plain statement advances one line and the assignment becomes visible
     */
    public function testStepOverAdvancesToTheNextStatementAndRevealsItsAssignment(): void
    {
        $ide = new FakeIde(timeoutSeconds: 10.0);
        $app = $this->beginSession($ide);

        $this->breakAtStatement($ide, '$base * 2');
        $before = $this->properties($this->command($ide, 'context_get -c 0 -d 0'));
        $this->assertSame('4', $before['$base'] ?? null);
        $this->assertArrayNotHasKey('$doubled', $before, 'the stepped statement has not run yet');

        $stepped = $this->command($ide, 'step_over');
        $this->assertStoppedAt($stepped, $this->lineOf($app, 'stepInner($doubled)'), 'step_over moves one statement on');

        $after = $this->properties($this->command($ide, 'context_get -c 0 -d 0'));
        $this->assertSame('8', $after['$doubled'] ?? null, 'the stepped-over statement did execute');

        $this->finishSession($ide);
    }

    /**
     * step_into lands on the callee's first statement; step_out returns past the call site
     */
    public function testStepIntoEntersTheCalleeAndStepOutReturnsToTheCaller(): void
    {
        $ide      = new FakeIde(timeoutSeconds: 10.0);
        $app      = $this->beginSession($ide);
        $callSite = $this->breakAtStatement($ide, 'stepInner($doubled)');

        $entered = $this->command($ide, 'step_into');
        $this->assertStoppedAt($entered, $this->lineOf($app, '$value * 3'), 'step_into lands on the callee first statement');

        $inside = $this->stackFrames($ide);
        $this->assertSame('stepInner', $inside[0]['where'], 'the innermost frame is the callee');
        $this->assertSame('{main}', $inside[1]['where'], 'the caller is still below it');
        $this->assertSame($callSite, $inside[1]['lineno'], 'the caller frame reports the call site');

        $locals = $this->properties($this->command($ide, 'context_get -c 0 -d 0'));
        $this->assertSame('8', $locals['$value'] ?? null, 'context_get serves the callee frame');
        $this->assertArrayNotHasKey('$scaled', $locals, 'stopped before the first statement ran');

        $returned = $this->command($ide, 'step_out');
        $this->assertStoppedAt($returned, $this->lineOf($app, 'stepGuarded($base)'), 'step_out resumes after the call site');
        $this->assertGreaterThan($callSite, $this->breakLocation($returned)['lineno']);

        $caller = $this->properties($this->command($ide, 'context_get -c 0 -d 0'));
        $this->assertSame('25', $caller['$fromInner'] ?? null, 'the callee ran to completion');

        $this->finishSession($ide);
    }

    /**
     * step_over still stops when the stepped statement throws and the frame is unwound
     *
     * The throwing frame never reaches another statement at its own depth, so an == depth
     * comparison would lose the stepper here; the <= comparison catches the resumption in
     * the catch block one frame up.
     */
    public function testStepOverStopsWhenTheSteppedStatementThrowsAndIsCaught(): void
    {
        $ide = new FakeIde(timeoutSeconds: 10.0);
        $app = $this->beginSession($ide);

        $this->breakAtStatement($ide, "RuntimeException('boom ");
        $throwing = $this->stackFrames($ide);
        $this->assertSame('stepThrower', $throwing[0]['where']);
        $this->assertSame('stepGuarded', $throwing[1]['where'], 'the catcher is the frame below');

        $recovered = $this->command($ide, 'step_over');
        $this->assertStoppedAt($recovered, $this->lineOf($app, '$recovered'), 'the unwind does not lose the stepper');

        $caught = $this->stackFrames($ide);
        $this->assertSame('stepGuarded', $caught[0]['where'], 'stopped in the catching frame');
        $this->assertNotContains('stepThrower', array_column($caught, 'where'), 'the throwing frame is gone');

        // Stepping keeps working after the unwind
        $next = $this->command($ide, 'step_over');
        $this->assertStoppedAt($next, $this->lineOf($app, 'return $recovered;'));

        $this->finishSession($ide);
    }

    /**
     * Depth sanity: the depth resume() captures is the suspended frame's own depth, so a
     * step_out issued inside a callee stops in its caller and nowhere else
     */
    public function testStepOutFromInsideACalleeStopsInTheCaller(): void
    {
        $ide      = new FakeIde(timeoutSeconds: 10.0);
        $app      = $this->beginSession($ide);
        $inCallee = $this->breakAtStatement($ide, '$scaled + 1');

        $frames = $this->stackFrames($ide);
        $this->assertSame('stepInner', $frames[0]['where'], 'suspended one frame deep');

        $returned = $this->command($ide, 'step_out');
        $this->assertStoppedAt($returned, $this->lineOf($app, 'stepGuarded($base)'), 'step_out stops in the caller');

        $caller = $this->stackFrames($ide);
        $this->assertSame('{main}', $caller[0]['where'], 'back at the top level of the debuggee');
        $this->assertNotContains('stepInner', array_column($caller, 'where'), 'the callee frame has returned');
        $this->assertLessThan($this->lineOf($app, 'stepGuarded($base)'), $inCallee, 'the callee body sits above the caller line');

        $this->finishSession($ide);
    }

    /**
     * Depth sanity, the other direction: a step_over issued at a call site in the
     * top-level frame stops on the very next top-level statement and never inside the
     * function that statement calls
     */
    public function testStepOverAtACallSiteDoesNotEnterTheCallee(): void
    {
        $ide = new FakeIde(timeoutSeconds: 10.0);
        $app = $this->beginSession($ide);

        $this->breakAtStatement($ide, 'stepInner($doubled)');
        $stepped = $this->command($ide, 'step_over');
        $this->assertStoppedAt($stepped, $this->lineOf($app, 'stepGuarded($base)'), 'the whole call is one step');

        $frames = $this->stackFrames($ide);
        $this->assertSame('{main}', $frames[0]['where'], 'never suspended inside stepInner()');
        $this->assertNotContains('stepInner', array_column($frames, 'where'));

        $locals = $this->properties($this->command($ide, 'context_get -c 0 -d 0'));
        $this->assertSame('25', $locals['$fromInner'] ?? null, 'the skipped call still produced its value');

        $this->finishSession($ide);
    }

    /**
     * A step_* is the first thing many IDEs send, while the session is still "starting"
     * and no frame is suspended: it must stop on the debuggee's first statement instead
     * of running the script to completion
     */
    public function testSteppingFromTheStartingStateStopsAtTheFirstStatement(): void
    {
        $ide = new FakeIde(timeoutSeconds: 10.0);
        $app = $this->beginSession($ide);

        $status = $this->command($ide, 'status');
        $this->assertSame('starting', (string) $status['status']);

        $stepped = $this->command($ide, 'step_over');
        $this->assertStoppedAt($stepped, $this->lineOf($app, '$base'), 'step_over from starting breaks immediately');

        $this->finishSession($ide);
    }

    /**
     * Spawns the debuggee, accepts the connection, consumes <init> and returns the app path
     */
    private function beginSession(FakeIde $ide): string
    {
        $this->spawnChild($this->fixture(self::ENTRY), $ide->port());
        $ide->accept();

        $init = $ide->receive();
        $this->assertSame('init', $init->getName());
        $this->assertStringEndsWith(self::ENTRY, (string) $init['fileuri']);

        return $this->fixture(self::APP);
    }

    /**
     * Runs to a one-shot line breakpoint on the statement containing $needle, drops the
     * breakpoint again (so only stepping can stop the debuggee afterwards) and returns
     * the line it stopped on
     */
    private function breakAtStatement(FakeIde $ide, string $needle): int
    {
        $app  = $this->fixture(self::APP);
        $line = $this->lineOf($app, $needle);

        $set = $this->command($ide, "breakpoint_set -t line -f file://{$app} -n {$line}");
        $this->assertSame('resolved', (string) $set['resolved']);

        $break = $this->command($ide, 'run');
        $this->assertStoppedAt($break, $line, "breakpoint on '{$needle}'");

        $removed = $this->command($ide, "breakpoint_remove -d {$set['id']}");
        $this->assertCount(0, $removed->children(), 'breakpoint_remove failed');

        return $line;
    }

    /**
     * Asserts the continuation reported a break at $line of the stepping fixture
     */
    private function assertStoppedAt(\SimpleXMLElement $response, int $line, string $message = ''): void
    {
        $this->assertSame('break', (string) $response['status'], $message);

        $location = $this->breakLocation($response);
        $this->assertStringEndsWith(self::APP, $location['filename'], $message);
        $this->assertSame($line, $location['lineno'], $message);
    }

    /**
     * The suspended stack as a list of frames, innermost first
     *
     * @return list<array{where: string, filename: string, lineno: int}>
     */
    private function stackFrames(FakeIde $ide): array
    {
        $frames = [];
        foreach ($this->command($ide, 'stack_get')->stack as $frame) {
            $frames[] = [
                'where'    => (string) $frame['where'],
                'filename' => (string) $frame['filename'],
                'lineno'   => (int) (string) $frame['lineno'],
            ];
        }
        $this->assertNotSame([], $frames, 'stack_get returned no frames');

        return $frames;
    }

    /**
     * Resumes to the end of the script and asserts the debuggee finished normally
     */
    private function finishSession(FakeIde $ide): void
    {
        $end = $this->command($ide, 'run');
        $this->assertSame('stopping', (string) $end['status']);

        $stop = $this->command($ide, 'stop');
        $this->assertSame('stopped', (string) $stop['status']);

        $ide->close();
        $this->finishChild('STEP RESULT=24', 'STEP DONE');
    }
}
