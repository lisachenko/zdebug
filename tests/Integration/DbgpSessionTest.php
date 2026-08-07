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

    public function testExceptionBreakpointFiresBeforeTheThrow(): void
    {
        $ide     = new FakeIde(timeoutSeconds: 10.0);
        $appPath = realpath(__DIR__ . '/fixtures/throwing-app.php');
        $this->assertIsString($appPath);

        $this->spawnChild($this->fixture('throwing-entry.php'), $ide->port());
        $ide->accept();

        $init = $ide->receive();
        $this->assertSame('init', $init->getName());

        // 1. an exception breakpoint on DomainException only
        $set = $this->command($ide, 'breakpoint_set -t exception -x DomainException');
        $this->assertNotSame('', (string) $set['id']);
        $this->assertSame('enabled', (string) $set['state']);

        // 2. run -> the LengthException thrown first must NOT break; the DomainException must
        $break = $this->command($ide, 'run');
        $this->assertSame('break', (string) $break['status']);
        $this->assertSame('exception', (string) $break['reason']);

        $throwLine  = $this->lineOf($appPath, "throw new DomainException('matched throw');");
        $message    = $this->breakMessage($break);
        $attributes = $this->attributesOf($message);
        $this->assertSame('DomainException', $attributes['exception'] ?? null);
        $this->assertSame((string) $throwLine, $attributes['lineno'] ?? null);
        $this->assertStringEndsWith('throwing-app.php', $attributes['filename'] ?? '');
        $this->assertSame('matched throw', trim((string) $message));

        // 3. the frame is suspended ON the throw statement, before the exception exists
        $stack = $this->command($ide, 'stack_get');
        $top   = $stack->stack[0];
        $this->assertSame((string) $throwLine, (string) $top['lineno']);
        $this->assertStringContainsString('raiseMatched', (string) $top['where']);
        $this->assertStringEndsWith('throwing-app.php', (string) $top['filename']);

        // 4. resuming lets the throw proceed: it is caught in the fixture and the script ends
        $end = $this->command($ide, 'run');
        $this->assertSame('stopping', (string) $end['status']);

        $stop = $this->command($ide, 'stop');
        $this->assertSame('stopped', (string) $stop['status']);

        $ide->close();
        $this->finishChild('UNMATCHED=unmatched throw', 'MATCHED=matched throw', 'THROWING APP DONE');
    }

    public function testNonMatchingExceptionClassDoesNotBreak(): void
    {
        $ide = new FakeIde(timeoutSeconds: 10.0);

        $this->spawnChild($this->fixture('throwing-entry.php'), $ide->port());
        $ide->accept();
        $ide->receive();

        // Neither thrown class is (or extends) InvalidArgumentException: nothing may suspend
        $this->command($ide, 'breakpoint_set -t exception -x InvalidArgumentException');

        $end = $this->command($ide, 'run');
        $this->assertSame('stopping', (string) $end['status'], 'A non-matching class must not break');

        $stop = $this->command($ide, 'stop');
        $this->assertSame('stopped', (string) $stop['status']);

        $ide->close();
        $this->finishChild('MATCHED=matched throw', 'THROWING APP DONE');
    }

    public function testRunsUndebuggedWhenIdeIsUnreachable(): void
    {
        // No FakeIde listening: the debuggee must degrade silently and finish normally
        $freePort = $this->reserveFreePort();
        $this->spawnChild($this->fixture('entry.php'), $freePort, connectTimeoutMs: 150);

        $this->finishChild('RESULT=35', 'APP DONE');
    }

    /**
     * Returns the <xdebug:message> element an IDE reads to move its cursor on a break
     *
     * The base class' breakLocation() covers filename/lineno only; exception breaks
     * also carry the exception class attribute and the message text.
     */
    private function breakMessage(\SimpleXMLElement $response): \SimpleXMLElement
    {
        $message = $response->children('https://xdebug.org/dbgp/xdebug')->message;
        if (!$message instanceof \SimpleXMLElement) {
            $this->fail('The break response carries no <xdebug:message> element');
        }

        return $message;
    }

    /**
     * Reads the (non-namespaced) attributes of an element into a plain map
     *
     * SimpleXML scopes `$element['name']` to the namespace the element was reached
     * through, so attributes of an `xdebug:`-prefixed element are only visible here.
     *
     * @return array<string, string>
     */
    private function attributesOf(\SimpleXMLElement $element): array
    {
        $attributes = [];
        foreach ($element->attributes() ?? [] as $name => $value) {
            $attributes[(string) $name] = (string) $value;
        }

        return $attributes;
    }
}
