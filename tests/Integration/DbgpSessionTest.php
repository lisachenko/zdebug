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

    public function testRunsUndebuggedWhenIdeIsUnreachable(): void
    {
        // No FakeIde listening: the debuggee must degrade silently and finish normally
        $freePort = $this->reserveFreePort();
        $this->spawnChild($this->fixture('entry.php'), $freePort, connectTimeoutMs: 150);

        $this->finishChild('RESULT=35', 'APP DONE');
    }
}
