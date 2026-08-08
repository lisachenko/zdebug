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
 * End-to-end coverage for suspending inside an instance method
 *
 * The bound object of a frame is not one of its compiled variables: it lives in the
 * execute_data's This slot, whose type_info doubles as the frame's ZEND_CALL_* flags, so
 * a frame with no object scope carries something there that merely looks like a zval.
 * Reading it without checking ZEND_CALL_HAS_THIS made `$this` vanish from context_get -
 * and, worse, made every breakpoint condition mentioning `$this` unsatisfiable, since the
 * evaluator binds its closure to whatever `$this` the collected scope contains.
 *
 * These tests pin both halves of that: the variable is reported, and a condition over it
 * decides where the debuggee stops.
 */
#[Group('integration')]
final class ObjectScopeSessionTest extends DbgpIntegrationTestCase
{
    public function testThisIsReportedInTheContextOfAMethodFrame(): void
    {
        $ide     = new FakeIde(timeoutSeconds: 10.0);
        $appPath = $this->fixture('object-app.php');

        $this->spawnChild($this->fixture('object-entry.php'), $ide->port());
        $ide->accept();
        $ide->receive(); // <init>

        $bpLine = $this->lineOf($appPath, '$step = $this->counter * $this->weight;');
        $set    = $this->command($ide, "breakpoint_set -t line -f file://{$appPath} -n {$bpLine}");
        $this->assertSame('break', (string) $this->command($ide, 'run')['status']);

        $top = $this->command($ide, 'stack_get')->stack[0];
        $this->assertStringContainsString('tick', (string) $top['where']);

        $context = $this->command($ide, 'context_get -c 0 -d 0');
        $bound   = $this->propertyNamed($context, '$this');
        $this->assertSame('object', (string) $bound['type']);
        $this->assertSame('Counter', (string) $bound['classname']);

        // Every visibility is reported, not just the public one
        $this->assertSame([
            'counter' => '1',
            'label'   => 'ticker',
            'weight'  => '10',
        ], $this->childValues($bound));

        // The evaluator binds to the very same object, so private state is reachable
        $evaluated = $this->command($ide, 'eval -- ' . base64_encode('$this->counter * 100'));
        $this->assertSame('100', base64_decode((string) $evaluated->property));

        // {main} has no bound object: `$this` must not appear there
        $outer = $this->properties($this->command($ide, 'context_get -c 0 -d 1'));
        $this->assertArrayNotHasKey('$this', $outer);
        $this->assertSame('1', $outer['$pass'] ?? null);

        $this->command($ide, "breakpoint_remove -d {$set['id']}");
        $this->assertSame('stopping', (string) $this->command($ide, 'run')['status']);
        $this->command($ide, 'stop');

        $ide->close();
        $this->finishChild('TOTAL=60', 'OBJECT DONE');
    }

    public function testAConditionOverThisBreaksOnTheMatchingCall(): void
    {
        $ide     = new FakeIde(timeoutSeconds: 10.0);
        $appPath = $this->fixture('object-app.php');

        $this->spawnChild($this->fixture('object-entry.php'), $ide->port());
        $ide->accept();
        $ide->receive(); // <init>

        // The counter is private: only a condition evaluated in the object's own scope
        // can read it, and the second of the three calls is the one that must suspend
        $bpLine = $this->lineOf($appPath, '$step = $this->counter * $this->weight;');
        $set    = $this->command(
            $ide,
            "breakpoint_set -t conditional -f file://{$appPath} -n {$bpLine} -- " . base64_encode('$this->counter === 2'),
        );
        $this->assertSame('resolved', (string) $set['resolved']);

        $this->assertSame('break', (string) $this->command($ide, 'run')['status']);

        $context = $this->command($ide, 'context_get -c 0 -d 0');
        $this->assertSame('2', $this->childValues($this->propertyNamed($context, '$this'))['counter'] ?? null);
        // The caller's loop confirms it: the second pass, not the first
        $this->assertSame('2', $this->properties($this->command($ide, 'context_get -c 0 -d 1'))['$pass'] ?? null);

        // Only the matching call counts as a hit
        $reported = $this->command($ide, "breakpoint_get -d {$set['id']}");
        $this->assertSame('1', (string) $reported->breakpoint['hit_count']);

        // ... and the third call must not suspend again
        $this->assertSame('stopping', (string) $this->command($ide, 'run')['status']);
        $this->command($ide, 'stop');

        $ide->close();
        $this->finishChild('TOTAL=60', 'OBJECT DONE');
    }

    /**
     * The named direct <property> child of a context_get response
     */
    private function propertyNamed(\SimpleXMLElement $context, string $name): \SimpleXMLElement
    {
        foreach ($context->property as $property) {
            if ((string) $property['name'] === $name) {
                return $property;
            }
        }
        $this->fail("The context carries no {$name} property");
    }

    /**
     * The children of a container <property>, as name => decoded value
     *
     * @return array<string, string>
     */
    private function childValues(\SimpleXMLElement $property): array
    {
        $values = [];
        foreach ($property->property as $child) {
            $values[(string) $child['name']] = base64_decode((string) $child);
        }

        return $values;
    }
}
