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
 * End-to-end DBGp session: this process plays the IDE, a child process runs the
 * instrumented debuggee, and a real breakpoint is hit with inspectable locals.
 *
 * The stepping counterpart of this test lives in SteppingSessionTest; the process
 * plumbing both share is in DbgpIntegrationTestCase.
 */
#[Group('integration')]
final class DbgpSessionTest extends DbgpIntegrationTestCase
{
    public function testFullBreakpointSession(): void
    {
        $ide     = new FakeIde(timeoutSeconds: 10.0);
        $appPath = $this->fixture('app.php');

        $this->spawnChild($this->fixture('entry.php'), $ide->port());
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
        $this->finishChild('RESULT=35', 'APP DONE');
    }

    public function testEvalReturnsThePropertyForAnExpressionOverFrameLocals(): void
    {
        $ide     = new FakeIde(timeoutSeconds: 10.0);
        $appPath = realpath(__DIR__ . '/fixtures/app.php');
        $this->assertIsString($appPath);

        $this->spawnChild($this->fixture('entry.php'), $ide->port());
        $ide->accept();
        $ide->receive(); // <init>

        // Nothing is suspended yet: eval falls back to an empty scope, so a constant
        // expression still answers (the documented `starting`-state behaviour)
        $constant = $this->evalExpression($ide, '6 * 7');
        $this->assertSame('1', (string) $constant['success']);
        $this->assertSame('42', $this->propertyValue($constant));

        $this->command($ide, 'feature_set -n max_depth -v 2');

        $bpLine = $this->lineOf($appPath, '$result  = $doubled + $tripled;');
        $set    = $this->command($ide, "breakpoint_set -t line -f file://{$appPath} -n {$bpLine}");
        $break  = $this->command($ide, 'run');
        $this->assertSame('break', (string) $break['status']);

        // Arithmetic over two locals of the suspended frame
        $arithmetic = $this->evalExpression($ide, '$seed * 10 + $doubled');
        $this->assertSame('int', (string) $arithmetic->property['type']);
        $this->assertSame('84', $this->propertyValue($arithmetic));

        // String result, and the expression is echoed back as the property name/fullname
        $concatenated = $this->evalExpression($ide, "'seed=' . \$seed");
        $this->assertSame('string', (string) $concatenated->property['type']);
        $this->assertSame("'seed=' . \$seed", (string) $concatenated->property['name']);
        $this->assertSame('seed=7', $this->propertyValue($concatenated));

        // Containers are serialized like any other property, children included
        $array = $this->evalExpression($ide, '[$doubled, $tripled]');
        $this->assertSame('array', (string) $array->property['type']);
        $this->assertSame('2', (string) $array->property['numchildren']);
        $this->assertSame('21', base64_decode((string) $array->property->property[1]));

        // -d selects the frame: depth 1 is {main}, where the frame locals differ
        $caller = $this->evalExpression($ide, 'isset($answer)', depth: 1);
        $this->assertSame('bool', (string) $caller->property['type']);
        $this->assertSame('0', $this->propertyValue($caller));

        $this->command($ide, "breakpoint_remove -d {$set['id']}");
        $this->assertSame('stopping', (string) $this->command($ide, 'run')['status']);
        $this->command($ide, 'stop');

        $ide->close();
        $this->finishChild('RESULT=35', 'APP DONE');
    }

    public function testEvalOfAFailingExpressionReturnsError206AndKeepsTheSessionUsable(): void
    {
        $ide     = new FakeIde(timeoutSeconds: 10.0);
        $appPath = realpath(__DIR__ . '/fixtures/app.php');
        $this->assertIsString($appPath);

        $this->spawnChild($this->fixture('entry.php'), $ide->port());
        $ide->accept();
        $ide->receive(); // <init>

        $bpLine = $this->lineOf($appPath, '$result  = $doubled + $tripled;');
        $set    = $this->command($ide, "breakpoint_set -t line -f file://{$appPath} -n {$bpLine}");
        $this->assertSame('break', (string) $this->command($ide, 'run')['status']);

        // An expression that throws: DBGp error 206, and the debuggee stays suspended
        $throwing = $this->evalExpression($ide, 'intdiv($seed, 0)');
        $this->assertSame(206, $this->errorCode($throwing));

        // An expression that does not even parse fails the same way
        $malformed = $this->evalExpression($ide, '$seed +');
        $this->assertSame(206, $this->errorCode($malformed));

        // A missing expression is a client error, not an evaluation failure
        $ide->send('eval');
        $this->assertSame(207, $this->errorCode($ide->receive()));

        // The session is still fully usable afterwards
        $locals = $this->properties($this->command($ide, 'context_get -c 0 -d 0'));
        $this->assertSame('7', $locals['$seed'] ?? null);

        $recovered = $this->evalExpression($ide, '$doubled + 1');
        $this->assertSame('15', $this->propertyValue($recovered));

        $this->command($ide, "breakpoint_remove -d {$set['id']}");
        $this->assertSame('stopping', (string) $this->command($ide, 'run')['status']);
        $this->command($ide, 'stop');

        $ide->close();
        $this->finishChild('RESULT=35', 'APP DONE');
    }

    public function testAConditionalBreakpointWhoseConditionIsFalseNeverBreaks(): void
    {
        $ide      = new FakeIde(timeoutSeconds: 10.0);
        $loopPath = realpath(__DIR__ . '/fixtures/loop.php');
        $this->assertIsString($loopPath);

        $this->spawnChild($this->fixture('loop-entry.php'), $ide->port());
        $ide->accept();
        $ide->receive(); // <init>

        // $i only ever runs 1..4, so this condition can never hold
        $bpLine = $this->lineOf($loopPath, '$step  = $i * 10;');
        $set    = $this->conditionalBreakpoint($ide, $loopPath, $bpLine, '$i === 99');
        $this->assertSame('resolved', (string) $set['resolved']);

        // ... and the debuggee must run straight to the end instead of suspending
        $this->assertSame('stopping', (string) $this->command($ide, 'run')['status']);

        $list = $this->command($ide, 'breakpoint_list');
        $this->assertSame('conditional', (string) $list->breakpoint[0]['type']);
        $this->assertSame('0', (string) $list->breakpoint[0]['hit_count'], 'a false condition is not a hit');
        $this->assertSame('$i === 99', base64_decode((string) $list->breakpoint[0]->expression));

        $this->command($ide, 'stop');
        $ide->close();
        $this->finishChild('LOOP DONE', 'SUM=100');
    }

    public function testAConditionalBreakpointBreaksOnTheIterationWhereTheConditionHolds(): void
    {
        $ide      = new FakeIde(timeoutSeconds: 10.0);
        $loopPath = realpath(__DIR__ . '/fixtures/loop.php');
        $this->assertIsString($loopPath);

        $this->spawnChild($this->fixture('loop-entry.php'), $ide->port());
        $ide->accept();
        $ide->receive(); // <init>

        $bpLine = $this->lineOf($loopPath, '$step  = $i * 10;');
        $set    = $this->conditionalBreakpoint($ide, $loopPath, $bpLine, '$i === 3');

        $break = $this->command($ide, 'run');
        $this->assertSame('break', (string) $break['status']);

        $top = $this->command($ide, 'stack_get')->stack[0];
        $this->assertSame((string) $bpLine, (string) $top['lineno']);
        $this->assertStringContainsString('accumulate', (string) $top['where']);

        // Suspended on the third pass: $total already carries 10 + 20
        $locals = $this->properties($this->command($ide, 'context_get -c 0 -d 0'));
        $this->assertSame('3', $locals['$i'] ?? null);
        $this->assertSame('30', $locals['$total'] ?? null);

        // Only the matching iteration counts as a hit
        $reported = $this->command($ide, "breakpoint_get -d {$set['id']}");
        $this->assertSame('1', (string) $reported->breakpoint['hit_count']);
        $this->assertSame('$i === 3', base64_decode((string) $reported->breakpoint->expression));

        $this->command($ide, "breakpoint_remove -d {$set['id']}");
        $this->assertSame('stopping', (string) $this->command($ide, 'run')['status']);
        $this->command($ide, 'stop');

        $ide->close();
        $this->finishChild('LOOP DONE', 'SUM=100');
    }

    public function testAHitConditionSkipsTheFirstPassThroughTheLine(): void
    {
        $ide      = new FakeIde(timeoutSeconds: 10.0);
        $loopPath = realpath(__DIR__ . '/fixtures/loop.php');
        $this->assertIsString($loopPath);

        $this->spawnChild($this->fixture('loop-entry.php'), $ide->port());
        $ide->accept();
        $ide->receive(); // <init>

        $bpLine = $this->lineOf($loopPath, '$step  = $i * 10;');
        $set    = $this->command($ide, "breakpoint_set -t line -f file://{$loopPath} -n {$bpLine} -h 2 -o >=");

        // First pass is counted but not broken on; the second one suspends
        $this->assertSame('break', (string) $this->command($ide, 'run')['status']);
        $locals = $this->properties($this->command($ide, 'context_get -c 0 -d 0'));
        $this->assertSame('2', $locals['$i'] ?? null, 'the first iteration must not break');

        $reported = $this->command($ide, "breakpoint_get -d {$set['id']}");
        $this->assertSame('2', (string) $reported->breakpoint['hit_count']);
        $this->assertSame('2', (string) $reported->breakpoint['hit_value']);
        $this->assertSame('>=', (string) $reported->breakpoint['hit_condition']);

        // ">=" keeps breaking on every later pass
        $this->assertSame('break', (string) $this->command($ide, 'run')['status']);
        $locals = $this->properties($this->command($ide, 'context_get -c 0 -d 0'));
        $this->assertSame('3', $locals['$i'] ?? null);

        $this->command($ide, "breakpoint_remove -d {$set['id']}");
        $this->assertSame('stopping', (string) $this->command($ide, 'run')['status']);
        $this->command($ide, 'stop');

        $ide->close();
        $this->finishChild('LOOP DONE', 'SUM=100');
    }

    public function testRunsUndebuggedWhenIdeIsUnreachable(): void
    {
        // No FakeIde listening: the debuggee must degrade silently and finish normally
        $freePort = $this->reserveFreePort();
        $this->spawnChild($this->fixture('entry.php'), $freePort, connectTimeoutMs: 150);

        $this->finishChild('RESULT=35', 'APP DONE');
    }

    /**
     * Sends `eval [-d depth] -- base64(expression)` and returns the response
     */
    private function evalExpression(FakeIde $ide, string $expression, ?int $depth = null): \SimpleXMLElement
    {
        $arguments = $depth !== null ? "-d {$depth} " : '';

        return $this->command($ide, 'eval ' . $arguments . '-- ' . base64_encode($expression));
    }

    /**
     * Sets a conditional line breakpoint (the condition travels in the data part)
     */
    private function conditionalBreakpoint(FakeIde $ide, string $file, int $line, string $condition): \SimpleXMLElement
    {
        return $this->command(
            $ide,
            "breakpoint_set -t conditional -f file://{$file} -n {$line} -- " . base64_encode($condition),
        );
    }

    /**
     * Decodes the single <property> of an eval response
     */
    private function propertyValue(\SimpleXMLElement $response): string
    {
        $this->assertTrue(isset($response->property), 'the response carries a <property>');

        return base64_decode((string) $response->property);
    }

    /**
     * Returns the DBGp error code of a response, or null when it is not an error
     */
    private function errorCode(\SimpleXMLElement $response): ?int
    {
        return isset($response->error) ? (int) $response->error['code'] : null;
    }
}
