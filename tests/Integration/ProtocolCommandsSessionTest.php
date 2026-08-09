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
 * End-to-end coverage for the remaining core DBGp commands
 *
 * stack_depth, typemap_get, source and breakpoint_update are each small enough to look
 * obviously right in a unit test and each depend on wiring a unit test cannot see: the
 * live stack, the property serializer's own type names, the debugger's path filter and
 * the registry's line index. This is where that wiring is pinned.
 */
#[Group('integration')]
final class ProtocolCommandsSessionTest extends DbgpIntegrationTestCase
{
    public function testStackDepthTypemapAndFacetsOfASuspendedFrame(): void
    {
        $ide     = new FakeIde(timeoutSeconds: 10.0);
        $appPath = $this->fixture('object-app.php');

        $this->spawnChild($this->fixture('object-entry.php'), $ide->port());
        $ide->accept();
        $ide->receive(); // <init>

        $bpLine = $this->lineOf($appPath, '$step = $this->counter * $this->weight;');
        $set    = $this->command($ide, "breakpoint_set -t line -f file://{$appPath} -n {$bpLine}");
        $this->assertSame('break', (string) $this->command($ide, 'run')['status']);

        // tick(), the app's top level and the entry script that required it - and the
        // count must be exactly what stack_get then hands over, whatever the number is
        $frames = $this->command($ide, 'stack_get')->stack;
        $this->assertCount(3, $frames);
        $this->assertSame('3', (string) $this->command($ide, 'stack_depth')['depth']);

        // Every type the map promises is a type a property may actually carry
        $mapped = [];
        foreach ($this->command($ide, 'typemap_get')->map as $map) {
            $mapped[(string) $map['name']] = (string) $map['type'];
        }
        $this->assertSame('int', $mapped['int'] ?? null);
        $this->assertSame('object', $mapped['object'] ?? null);

        // The visibility of each property of $this, as the IDE draws it
        $facets = [];
        foreach ($this->command($ide, 'property_get -d 0 -c 0 -n $this')->property->property as $child) {
            $facets[(string) $child['name']] = (string) $child['facet'];
        }
        $this->assertSame([
            'counter' => 'private',
            'label'   => 'protected',
            'weight'  => 'public',
        ], $facets);

        $this->command($ide, "breakpoint_remove -d {$set['id']}");
        $this->assertSame('stopping', (string) $this->command($ide, 'run')['status']);
        $this->command($ide, 'stop');

        $ide->close();
        $this->finishChild('TOTAL=60', 'OBJECT DONE');
    }

    /**
     * source serves the code the debuggee is running - and only the code the debugger was
     * configured to observe
     */
    public function testSourceServesDebuggeeCodeWithinThePathFilterOnly(): void
    {
        $ide     = new FakeIde(timeoutSeconds: 10.0);
        $appPath = $this->fixture('object-app.php');

        $this->spawnChild($this->fixture('object-entry.php'), $ide->port());
        $ide->accept();
        $ide->receive(); // <init>

        $bpLine = $this->lineOf($appPath, '$step = $this->counter * $this->weight;');
        $set    = $this->command($ide, "breakpoint_set -t line -f file://{$appPath} -n {$bpLine}");
        $this->assertSame('break', (string) $this->command($ide, 'run')['status']);

        // No -f: the file of the frame the IDE is looking at
        $whereIAm = $this->command($ide, 'source -d 0');
        $this->assertSame('1', (string) $whereIAm['success']);
        $this->assertStringContainsString('class Counter', base64_decode((string) $whereIAm));

        // -b / -e select an inclusive line range, so the IDE can fetch just the frame
        $oneLine = $this->command($ide, "source -f file://{$appPath} -b {$bpLine} -e {$bpLine}");
        $this->assertSame(
            '$step = $this->counter * $this->weight;',
            trim(base64_decode((string) $oneLine)),
        );

        // ZDEBUG_PATH_FILTER is the fixtures directory: this very test file is outside it,
        // and a DBGp connection must not be a way to read it
        $this->assertSame('100', (string) $this->command($ide, 'source -f file://' . __FILE__)->error['code']);
        $this->assertSame('100', (string) $this->command($ide, 'source -f file:///etc/hostname')->error['code']);

        $this->command($ide, "breakpoint_remove -d {$set['id']}");
        $this->assertSame('stopping', (string) $this->command($ide, 'run')['status']);
        $this->command($ide, 'stop');

        $ide->close();
        $this->finishChild('TOTAL=60', 'OBJECT DONE');
    }

    /**
     * A disabled breakpoint keeps its id and its hit count, and stops suspending: that is
     * the whole reason an IDE sends breakpoint_update instead of remove + set
     */
    public function testBreakpointUpdateDisablesWithoutLosingTheBreakpoint(): void
    {
        $ide     = new FakeIde(timeoutSeconds: 10.0);
        $appPath = $this->fixture('object-app.php');

        $this->spawnChild($this->fixture('object-entry.php'), $ide->port());
        $ide->accept();
        $ide->receive(); // <init>

        $bpLine = $this->lineOf($appPath, '$step = $this->counter * $this->weight;');
        $set    = $this->command($ide, "breakpoint_set -t line -f file://{$appPath} -n {$bpLine}");
        $id     = (string) $set['id'];
        $this->assertSame('break', (string) $this->command($ide, 'run')['status']);

        $updated = $this->command($ide, "breakpoint_update -d {$id} -s disabled");
        $this->assertSame($id, (string) $updated->breakpoint['id'], 'the id survives');
        $this->assertSame('disabled', (string) $updated->breakpoint['state']);
        $this->assertSame('1', (string) $updated->breakpoint['hit_count'], 'and so does the hit count');

        // tick() is called twice more, and neither call may suspend now
        $this->assertSame('stopping', (string) $this->command($ide, 'run')['status']);
        $this->command($ide, 'stop');

        $ide->close();
        $this->finishChild('TOTAL=60', 'OBJECT DONE');
    }
}
