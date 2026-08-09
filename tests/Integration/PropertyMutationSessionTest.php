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
 * End-to-end coverage for property_set: editing the debuggee from the variables panel
 *
 * Every other command in zdebug describes the program; this one changes it, so a response
 * of success="1" proves nothing on its own - the debugger could have written a
 * materialized copy and reported it happily. What is asserted here is the debuggee's OWN
 * output after it resumes: the values the program itself reads back are the only evidence
 * a write reached the engine's zvals.
 */
#[Group('integration')]
final class PropertyMutationSessionTest extends DbgpIntegrationTestCase
{
    public function testWritesLandInTheRunningProgram(): void
    {
        $ide     = new FakeIde(timeoutSeconds: 10.0);
        $appPath = $this->fixture('mutation-app.php');

        $this->spawnChild($this->fixture('mutation-entry.php'), $ide->port());
        $ide->accept();
        $ide->receive(); // <init>

        $bpLine = $this->lineOf($appPath, '$stop = true; // inspection point');
        $set    = $this->command($ide, "breakpoint_set -t line -f file://{$appPath} -n {$bpLine}");
        $this->assertSame('break', (string) $this->command($ide, 'run')['status']);

        // Scalars: -t names the type, and the value arrives base64 in the data part
        $this->assertTrue($this->write($ide, '$counter', '42', 'int'));
        $this->assertTrue($this->write($ide, '$flag', '1', 'bool'));
        $this->assertTrue($this->write($ide, '$ratio', '2.5', 'float'));
        // No -t: the type already at that path is kept, so a string stays a string
        $this->assertTrue($this->write($ide, '$label', 'after'));

        // Nested array elements, addressed by the same fullname property_get hands out
        $this->assertTrue($this->write($ide, "\$rows['a']['n']", '99', 'int'));
        $this->assertTrue($this->write($ide, '$rows[list][1]', '77', 'int'));

        // Object properties of every visibility, including a private array one step deeper
        $this->assertTrue($this->write($ide, '$holder->open', '5', 'int'));
        $this->assertTrue($this->write($ide, '$holder->shared', 'P'));
        $this->assertTrue($this->write($ide, "\$holder->bag['k']", 'V'));

        // A null local can be given a value; a declared-but-unset one has a CV slot too
        $this->assertTrue($this->write($ide, '$later', 'given', 'string'));
        $this->assertTrue($this->write($ide, '$stop', '1', 'bool'));

        // property_get sees the new value immediately, from the same live frame
        $this->assertSame('42', $this->read($ide, '$counter'));
        $this->assertSame('after', $this->read($ide, '$label'));
        $this->assertSame('99', $this->read($ide, "\$rows['a']['n']"));
        $this->assertSame('V', $this->read($ide, "\$holder->bag['k']"));

        $this->command($ide, "breakpoint_remove -d {$set['id']}");
        $this->assertSame('stopping', (string) $this->command($ide, 'run')['status']);
        $this->command($ide, 'stop');

        $ide->close();
        // The debuggee reads every one of them back itself, after the debugger let go
        $this->finishChild('MUTATED=42|after|true|2.5|99|77|5|PV|locked|given', 'MUTATION APP DONE');
    }

    /**
     * A write the engine refuses is reported as success="0", not as a broken session
     */
    public function testARefusedWriteIsReportedWithoutBreakingTheSession(): void
    {
        $ide     = new FakeIde(timeoutSeconds: 10.0);
        $appPath = $this->fixture('mutation-app.php');

        $this->spawnChild($this->fixture('mutation-entry.php'), $ide->port());
        $ide->accept();
        $ide->receive(); // <init>

        $bpLine = $this->lineOf($appPath, '$stop = true; // inspection point');
        $set    = $this->command($ide, "breakpoint_set -t line -f file://{$appPath} -n {$bpLine}");
        $this->assertSame('break', (string) $this->command($ide, 'run')['status']);

        // An initialized readonly property cannot be reassigned, by anybody
        $this->assertFalse($this->write($ide, '$holder->sealed', 'forced'));

        // ... and a path that addresses nothing is a client error, not a failed write
        foreach (['$nowhere', '$holder->nowhere', "\$rows['nope']", '$this'] as $fullName) {
            $response = $this->command($ide, "property_set -d 0 -c 0 -n {$fullName} -- " . base64_encode('x'));
            $this->assertSame('300', (string) $response->error['code'], "{$fullName} must not be writable");
        }

        // The session is still usable and the debuggee still intact
        $this->assertSame('before', $this->read($ide, '$label'));

        $this->command($ide, "breakpoint_remove -d {$set['id']}");
        $this->assertSame('stopping', (string) $this->command($ide, 'run')['status']);
        $this->command($ide, 'stop');

        $ide->close();
        $this->finishChild('MUTATED=1|before|false|1.5|1|20|1|pv|locked|', 'MUTATION APP DONE');
    }

    /**
     * Sends a property_set, returning whether the engine accepted the write
     */
    private function write(FakeIde $ide, string $fullName, string $value, ?string $type = null): bool
    {
        $typeArgument = $type !== null ? " -t {$type}" : '';
        $response     = $this->command(
            $ide,
            "property_set -d 0 -c 0{$typeArgument} -n {$fullName} -- " . base64_encode($value),
        );
        $this->assertFalse(isset($response->error), "property_set {$fullName} answered an error");

        return (string) $response['success'] === '1';
    }

    private function read(FakeIde $ide, string $fullName): string
    {
        $response = $this->command($ide, "property_get -d 0 -c 0 -n {$fullName}");
        $this->assertFalse(isset($response->error), "property_get {$fullName} answered an error");

        return base64_decode((string) $response->property);
    }
}
