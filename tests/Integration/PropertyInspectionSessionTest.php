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
 * End-to-end coverage for property_get: expanding a variable the way an IDE does
 *
 * context_get renders one level, so every node an IDE opens in its variables panel comes
 * back as a property_get for that node's `fullname`. Without the command the panel is
 * stuck at the first level and shows "Command property_get is not implemented" where an
 * exception's message, code and previous should be - which is what these tests pin, one
 * level at a time, against a real debuggee suspended in a catch block.
 */
#[Group('integration')]
final class PropertyInspectionSessionTest extends DbgpIntegrationTestCase
{
    public function testAnExceptionIsExpandedOneLevelAtATime(): void
    {
        $ide     = new FakeIde(timeoutSeconds: 10.0);
        $appPath = $this->fixture('property-app.php');

        $this->spawnChild($this->fixture('property-entry.php'), $ide->port());
        $ide->accept();
        $ide->receive(); // <init>

        $bpLine = $this->lineOf($appPath, 'return $error->getMessage(); // inspection point');
        $set    = $this->command($ide, "breakpoint_set -t line -f file://{$appPath} -n {$bpLine}");
        $this->assertSame('break', (string) $this->command($ide, 'run')['status']);

        // The context shows the exception as an object, but at max_depth 1 its children
        // are leaves: everything below them is what property_get is for
        $context   = $this->command($ide, 'context_get -c 0 -d 0');
        $exception = $this->propertyNamed($context, '$error');
        $this->assertSame('object', (string) $exception['type']);
        $this->assertSame('ConfigError', (string) $exception['classname']);

        // Expanding the exception: the structure an IDE prints in its variables panel
        $expanded = $this->property($ide, '$error');
        $this->assertSame('$error', (string) $expanded['fullname']);
        $this->assertSame('ConfigError', (string) $expanded['classname']);
        $children = $this->childValues($expanded);
        $this->assertSame('connection refused', $children['message'] ?? null);
        $this->assertSame('42', $children['code'] ?? null);
        $this->assertSame('database', $children['section'] ?? null);
        $throwLine = $this->lineOf($appPath, 'throw new ConfigError(');
        $this->assertSame((string) $throwLine, $children['line'] ?? null, 'the throw site, not the break site');
        $this->assertSame($appPath, $children['file'] ?? null);
        $this->assertArrayHasKey('trace', $children);
        $this->assertArrayHasKey('previous', $children);

        // A child's fullname is the path the IDE sends back to open the next level
        $previous = $this->childNamed($expanded, 'previous');
        $this->assertSame('$error->previous', (string) $previous['fullname']);
        $this->assertSame('1', (string) $previous['children'], 'the nested throwable is expandable');

        // ... and following it reaches the root cause, two steps from the base variable
        $nested = $this->property($ide, '$error->previous');
        $this->assertSame('LengthException', (string) $nested['classname']);
        $this->assertSame('root cause', $this->childValues($nested)['message'] ?? null);

        $message = $this->property($ide, '$error->previous->message');
        $this->assertSame('string', (string) $message['type']);
        $this->assertSame('message', (string) $message['name'], 'the label is the last step only');
        $this->assertSame('$error->previous->message', (string) $message['fullname']);
        $this->assertSame('root cause', base64_decode((string) $message));

        $this->command($ide, "breakpoint_remove -d {$set['id']}");
        $this->assertSame('stopping', (string) $this->command($ide, 'run')['status']);
        $this->command($ide, 'stop');

        $ide->close();
        $this->finishChild('REPORT=connection refused', 'PROPERTY APP DONE');
    }

    public function testArraysAreWalkedByKeyAndPagedByMaxChildren(): void
    {
        $ide     = new FakeIde(timeoutSeconds: 10.0);
        $appPath = $this->fixture('property-app.php');

        $this->spawnChild($this->fixture('property-entry.php'), $ide->port());
        $ide->accept();
        $ide->receive(); // <init>

        $bpLine = $this->lineOf($appPath, 'return $error->getMessage(); // inspection point');
        $set    = $this->command($ide, "breakpoint_set -t line -f file://{$appPath} -n {$bpLine}");
        $this->assertSame('break', (string) $this->command($ide, 'run')['status']);

        // Subscripts nest, and the key may arrive quoted or bare - the command tokenizer
        // strips the IDE's quoting before the name ever reaches the resolver
        $nested = $this->property($ide, "\$report['nested']['depth']");
        $this->assertSame('array', (string) $nested['type']);
        $this->assertSame('bottom', $this->childValues($nested)['leaf'] ?? null);
        $this->assertSame('bottom', base64_decode((string) $this->property($ide, '$report[nested][depth][leaf]')));

        // -p pages a container the IDE cannot show in one go
        $this->assertSame('1', (string) $this->command($ide, 'feature_set -n max_children -v 2')['success']);
        $firstPage = $this->property($ide, '$numbers');
        $this->assertSame('5', (string) $firstPage['numchildren'], 'the count is of the whole array');
        $this->assertSame('0', (string) $firstPage['page']);
        $this->assertSame(['0' => '10', '1' => '20'], $this->childValues($firstPage));

        $secondPage = $this->property($ide, '$numbers', '-p 1');
        $this->assertSame('1', (string) $secondPage['page']);
        $this->assertSame(['2' => '30', '3' => '40'], $this->childValues($secondPage));

        $lastPage = $this->property($ide, '$numbers', '-p 2');
        $this->assertSame(['4' => '50'], $this->childValues($lastPage));

        // An element addressed straight through its fullname needs no paging at all
        $this->assertSame('50', base64_decode((string) $this->property($ide, '$numbers[4]')));

        $this->command($ide, "breakpoint_remove -d {$set['id']}");
        $this->assertSame('stopping', (string) $this->command($ide, 'run')['status']);
        $this->command($ide, 'stop');

        $ide->close();
        $this->finishChild('REPORT=connection refused', 'PROPERTY APP DONE');
    }

    /**
     * property_value is the unclamped read: the same lookup, ignoring max_data
     */
    public function testPropertyValueReturnsAStringMaxDataWouldHaveTruncated(): void
    {
        $ide     = new FakeIde(timeoutSeconds: 10.0);
        $appPath = $this->fixture('property-app.php');

        $this->spawnChild($this->fixture('property-entry.php'), $ide->port());
        $ide->accept();
        $ide->receive(); // <init>

        $bpLine = $this->lineOf($appPath, 'return $error->getMessage(); // inspection point');
        $set    = $this->command($ide, "breakpoint_set -t line -f file://{$appPath} -n {$bpLine}");
        $this->command($ide, 'feature_set -n max_data -v 8');
        $this->assertSame('break', (string) $this->command($ide, 'run')['status']);

        $clamped = $this->property($ide, '$banner');
        $this->assertSame('40', (string) $clamped['size'], 'size always reports the full length');
        $this->assertSame(str_repeat('x', 8), base64_decode((string) $clamped));

        $whole = $this->command($ide, 'property_value -d 0 -c 0 -n $banner')->property;
        $this->assertSame(str_repeat('x', 40), base64_decode((string) $whole));

        // -m lifts the clamp for a single property_get, without changing the feature
        $unclamped = $this->property($ide, '$banner', '-m 0');
        $this->assertSame(str_repeat('x', 40), base64_decode((string) $unclamped));

        $this->command($ide, "breakpoint_remove -d {$set['id']}");
        $this->assertSame('stopping', (string) $this->command($ide, 'run')['status']);
        $this->command($ide, 'stop');

        $ide->close();
        $this->finishChild('REPORT=connection refused', 'PROPERTY APP DONE');
    }

    /**
     * A fullname that addresses nothing is the client's error (300), never a dead session
     */
    public function testAnUnknownPropertyIsReportedAndLeavesTheSessionUsable(): void
    {
        $ide     = new FakeIde(timeoutSeconds: 10.0);
        $appPath = $this->fixture('property-app.php');

        $this->spawnChild($this->fixture('property-entry.php'), $ide->port());
        $ide->accept();
        $ide->receive(); // <init>

        $bpLine = $this->lineOf($appPath, 'return $error->getMessage(); // inspection point');
        $set    = $this->command($ide, "breakpoint_set -t line -f file://{$appPath} -n {$bpLine}");
        $this->assertSame('break', (string) $this->command($ide, 'run')['status']);

        foreach (['$nowhere', '$error->nowhere', '$numbers[99]', '$error->getMessage()'] as $fullName) {
            $response = $this->command($ide, "property_get -d 0 -c 0 -n {$fullName}");
            $this->assertSame('300', (string) $response->error['code'], "{$fullName} must not resolve");
        }

        // The session survives every one of them
        $this->assertSame('connection refused', $this->childValues($this->property($ide, '$error'))['message'] ?? null);

        $this->command($ide, "breakpoint_remove -d {$set['id']}");
        $this->assertSame('stopping', (string) $this->command($ide, 'run')['status']);
        $this->command($ide, 'stop');

        $ide->close();
        $this->finishChild('REPORT=connection refused', 'PROPERTY APP DONE');
    }

    /**
     * Sends a property_get and returns the single <property> it answers with
     */
    private function property(FakeIde $ide, string $fullName, string $extraArguments = ''): \SimpleXMLElement
    {
        $arguments = rtrim("property_get -d 0 -c 0 {$extraArguments}") . " -n {$fullName}";
        $response  = $this->command($ide, $arguments);
        $this->assertFalse(isset($response->error), "property_get {$fullName} answered an error");
        $this->assertTrue(isset($response->property), "property_get {$fullName} answered no property");

        return $response->property;
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

    private function childNamed(\SimpleXMLElement $property, string $name): \SimpleXMLElement
    {
        foreach ($property->property as $child) {
            if ((string) $child['name'] === $name) {
                return $child;
            }
        }
        $this->fail("The property has no {$name} child");
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
