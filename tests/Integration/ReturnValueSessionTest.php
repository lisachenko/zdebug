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
use ZDebug\Protocol\ResponseBuilder;

/**
 * End-to-end coverage for return-value debugging, as Xdebug 3.2 defined it
 *
 * `return $this->compute();` stores its result nowhere the debugger can name, so an IDE
 * stepping through it has nothing to show. The protocol answer is an extra stop on the
 * way out of the function, with the value both attached to the break response and
 * readable from the context under a virtual variable.
 *
 * The exchange asserted here is the one PhpStorm performs: enable
 * breakpoint_include_return_value, step, and read $__RETURN_VALUE.
 */
#[Group('integration')]
final class ReturnValueSessionTest extends DbgpIntegrationTestCase
{
    private const string RETURN_VARIABLE = '$__RETURN_VALUE';

    public function testSteppingOffAReturnStopsAgainWithTheValue(): void
    {
        $ide     = new FakeIde(timeoutSeconds: 10.0);
        $appPath = $this->fixture('return-app.php');

        $this->spawnChild($this->fixture('return-entry.php'), $ide->port());
        $ide->accept();
        $ide->receive(); // <init>

        // The capability probe an IDE runs before offering the feature at all
        $probe = $this->command($ide, 'feature_get -n breakpoint_include_return_value');
        $this->assertSame('1', (string) $probe['supported'], 'the engine must admit it can do this');
        $this->assertSame('0', (string) $probe, 'and it must be off until asked for');
        $this->assertSame('1', (string) $this->command($ide, 'feature_set -n breakpoint_include_return_value -v 1')['success']);

        $returnLine = $this->lineOf($appPath, 'return array_sum($numbers) * 2; // total return');
        $set        = $this->command($ide, "breakpoint_set -t line -f file://{$appPath} -n {$returnLine}");
        $this->assertSame('break', (string) $this->command($ide, 'run')['status']);

        // Stopped ON the return statement: it has not run, so there is no value yet
        $this->assertArrayNotHasKey(
            self::RETURN_VARIABLE,
            $this->properties($this->command($ide, 'context_get -c 0 -d 0')),
        );

        // Stepping off it stops one extra time, on the way out, with the value attached
        $stepped = $this->command($ide, 'step_into');
        $this->assertSame('break', (string) $stepped['status']);
        $this->assertSame('12', base64_decode((string) $this->returnValueProperty($stepped)), 'array_sum([1,2,3]) * 2');

        // ... and the same value is in the context, under the name Xdebug 3.2 defined
        $locals = $this->properties($this->command($ide, 'context_get -c 0 -d 0'));
        $this->assertSame('12', $locals[self::RETURN_VARIABLE] ?? null);
        $this->assertSame('12', base64_decode((string) $this->command(
            $ide,
            'property_get -d 0 -c 0 -n ' . self::RETURN_VARIABLE,
        )->property));

        // It is marked virtual, so an IDE does not render it as a variable of the frame
        $facets = [];
        foreach ($this->command($ide, 'context_get -c 0 -d 0')->property as $property) {
            $facets[(string) $property['name']] = (string) $property['facet'];
        }
        $this->assertSame('virtual return_value', $facets[self::RETURN_VARIABLE] ?? null);

        // The frame is still the returning one: its own locals are readable alongside
        $this->assertArrayHasKey('$numbers', $locals);

        $this->command($ide, "breakpoint_remove -d {$set['id']}");
        $this->assertSame('stopping', (string) $this->command($ide, 'run')['status']);
        $this->command($ide, 'stop');

        $ide->close();
        $this->finishChild('RETURNED=12/true/3', 'RETURN APP DONE');
    }

    /**
     * The extra stop is a cost the IDE opted into; without the feature there is none
     */
    public function testWithoutTheFeatureSteppingOffAReturnLandsInTheCaller(): void
    {
        $ide     = new FakeIde(timeoutSeconds: 10.0);
        $appPath = $this->fixture('return-app.php');

        $this->spawnChild($this->fixture('return-entry.php'), $ide->port());
        $ide->accept();
        $ide->receive(); // <init>

        $returnLine = $this->lineOf($appPath, 'return array_sum($numbers) * 2; // total return');
        $callLine   = $this->lineOf($appPath, '$sum = total([1, 2, 3]);');
        $set        = $this->command($ide, "breakpoint_set -t line -f file://{$appPath} -n {$returnLine}");
        $this->assertSame('break', (string) $this->command($ide, 'run')['status']);

        // Straight past the return and into the caller's next statement - no extra stop
        $stepped = $this->command($ide, 'step_into');
        $this->assertGreaterThan($callLine, $this->breakLocation($stepped)['lineno']);
        $this->assertNull($this->returnValueOf($stepped));
        $this->assertArrayNotHasKey(
            self::RETURN_VARIABLE,
            $this->properties($this->command($ide, 'context_get -c 0 -d 0')),
        );

        $this->command($ide, "breakpoint_remove -d {$set['id']}");
        $this->assertSame('stopping', (string) $this->command($ide, 'run')['status']);
        $this->command($ide, 'stop');

        $ide->close();
        $this->finishChild('RETURNED=12/true/3', 'RETURN APP DONE');
    }

    /**
     * A container return is serialized like any other property, children and all; a void
     * function returns a genuine null rather than nothing at all
     */
    public function testContainerAndVoidReturns(): void
    {
        $ide     = new FakeIde(timeoutSeconds: 10.0);
        $appPath = $this->fixture('return-app.php');

        $this->spawnChild($this->fixture('return-entry.php'), $ide->port());
        $ide->accept();
        $ide->receive(); // <init>

        $this->command($ide, 'feature_set -n breakpoint_include_return_value -v 1');

        // A bare `return;` in a void function
        $voidReturn = $this->lineOf($appPath, 'return; // nothing return');
        $voidSet    = $this->command($ide, "breakpoint_set -t line -f file://{$appPath} -n {$voidReturn}");
        $this->assertSame('break', (string) $this->command($ide, 'run')['status']);

        $stepped = $this->command($ide, 'step_into');
        $this->assertSame('null', (string) $this->returnValueProperty($stepped)['type']);
        $this->command($ide, "breakpoint_remove -d {$voidSet['id']}");

        // ... and an array return, expanded like the container it is
        $arrayReturn = $this->lineOf($appPath, "return ['ok' => true, 'n' => 3]; // describe return");
        $arraySet    = $this->command($ide, "breakpoint_set -t line -f file://{$appPath} -n {$arrayReturn}");
        $this->assertSame('break', (string) $this->command($ide, 'run')['status']);

        $property = $this->returnValueProperty($this->command($ide, 'step_into'));
        $this->assertSame('array', (string) $property['type']);
        $this->assertSame('2', (string) $property['numchildren']);
        $this->assertSame('3', base64_decode((string) $property->property[1]));

        $this->command($ide, "breakpoint_remove -d {$arraySet['id']}");
        $this->assertSame('stopping', (string) $this->command($ide, 'run')['status']);
        $this->command($ide, 'stop');

        $ide->close();
        $this->finishChild('RETURNED=12/true/3', 'RETURN APP DONE');
    }

    /**
     * A return breakpoint carries the value too - an IDE that asked for return values
     * wants them wherever it stops on a return, not only when it stepped there
     */
    public function testAReturnBreakpointCarriesTheValueWhenTheFeatureIsOn(): void
    {
        $ide = new FakeIde(timeoutSeconds: 10.0);

        $this->spawnChild($this->fixture('return-entry.php'), $ide->port());
        $ide->accept();
        $ide->receive(); // <init>

        $this->command($ide, 'feature_set -n breakpoint_include_return_value -v 1');
        $set = $this->command($ide, 'breakpoint_set -t return -m describe');

        $break = $this->command($ide, 'run');
        $this->assertSame('break', (string) $break['status']);
        $this->assertSame('array', (string) $this->returnValueProperty($break)['type']);

        $this->command($ide, "breakpoint_remove -d {$set['id']}");
        $this->assertSame('stopping', (string) $this->command($ide, 'run')['status']);
        $this->command($ide, 'stop');

        $ide->close();
        $this->finishChild('RETURNED=12/true/3', 'RETURN APP DONE');
    }

    /**
     * The base64 payload of the <xdebug:return_value> element, or null when there is none
     */
    private function returnValueOf(\SimpleXMLElement $response): ?string
    {
        $property = $this->findReturnValue($response);

        return $property !== null ? (string) $property : null;
    }

    private function returnValueProperty(\SimpleXMLElement $response): \SimpleXMLElement
    {
        $property = $this->findReturnValue($response);
        $this->assertNotNull($property, 'the break response carries no <xdebug:return_value>');

        return $property;
    }

    /**
     * XPath rather than ->children(NS): the element is namespaced but its <property> child
     * is not, and SimpleXML resolves child lookups against the parent's namespace
     */
    private function findReturnValue(\SimpleXMLElement $response): ?\SimpleXMLElement
    {
        $response->registerXPathNamespace('xdebug', ResponseBuilder::NS_XDEBUG);
        $found = $response->xpath('//xdebug:return_value/*');

        return is_array($found) && $found !== [] ? $found[0] : null;
    }
}
