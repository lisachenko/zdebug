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

namespace ZDebug\Tests\Context;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ZDebug\Context\PropertyPath;

final class PathFixture
{
    public function __construct(
        public ?PathFixture $child = null,
        private string $secret = 'hidden',
        protected ?string $absent = null,
    ) {}

    public function describe(): string
    {
        return $this->secret . (string) $this->absent;
    }
}

/**
 * Resolving the `fullname` an IDE sends back to expand a node
 *
 * The paths asserted here are exactly the ones PropertySerializer writes onto its
 * <property> elements: the two classes are one grammar read from both ends, and a path
 * this test cannot resolve is a node the variables panel cannot open.
 */
final class PropertyPathTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $variables;

    protected function setUp(): void
    {
        $this->variables = [
            '$object'  => new PathFixture(new PathFixture()),
            '$rows'    => ['first' => ['id' => 7, 'tags' => ['a', 'b']], 3 => 'third'],
            '$plain'   => 'text',
            '$nothing' => null,
        ];
    }

    public function testABaseVariableResolvesToItself(): void
    {
        $property = PropertyPath::resolve($this->variables, '$plain');

        $this->assertNotNull($property);
        $this->assertSame('$plain', $property->name);
        $this->assertSame('$plain', $property->fullName);
        $this->assertSame('text', $property->value);
    }

    /**
     * A variable holding null exists; only array_key_exists can tell the two apart
     */
    public function testAVariableHoldingNullIsFoundRatherThanMissing(): void
    {
        $property = PropertyPath::resolve($this->variables, '$nothing');

        $this->assertNotNull($property);
        $this->assertNull($property->value);
        $this->assertNotNull(PropertyPath::resolve($this->variables, '$object->absent'));
    }

    /**
     * The DBGp spec spells names without the sigil, every IDE sends the PHP spelling
     */
    public function testTheLeadingSigilIsOptional(): void
    {
        $property = PropertyPath::resolve($this->variables, 'plain');

        $this->assertNotNull($property);
        $this->assertSame('text', $property->value);
    }

    public function testNameIsTheLastStepAndFullNameThePathAsSent(): void
    {
        $property = PropertyPath::resolve($this->variables, "\$rows['first']['id']");

        $this->assertNotNull($property);
        $this->assertSame('id', $property->name);
        $this->assertSame("\$rows['first']['id']", $property->fullName);
        $this->assertSame(7, $property->value);
    }

    #[DataProvider('resolvablePaths')]
    public function testStepsWalkArraysAndObjects(string $path, mixed $expected): void
    {
        $property = PropertyPath::resolve($this->variables, $path);

        $this->assertNotNull($property, "{$path} did not resolve");
        $this->assertSame($expected, $property->value);
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function resolvablePaths(): iterable
    {
        yield 'quoted key' => ["\$rows['first']['id']", 7];
        // The command tokenizer strips the IDE's quoting before the name arrives here
        yield 'bare key' => ['$rows[first][id]', 7];
        yield 'double-quoted key' => ['$rows["first"]["id"]', 7];
        yield 'integer key' => ['$rows[3]', 'third'];
        yield 'list index' => ["\$rows['first']['tags'][1]", 'b'];
        yield 'private property' => ['$object->secret', 'hidden'];
        yield 'nested object property' => ['$object->child->secret', 'hidden'];
        yield 'padded by the client' => ['  $plain  ', 'text'];
    }

    #[DataProvider('unresolvablePaths')]
    public function testAPathThatAddressesNothingIsRejected(string $path): void
    {
        $this->assertNull(PropertyPath::resolve($this->variables, $path));
    }

    /**
     * Guessing here would answer a property_get with a value the debuggee never had; the
     * dispatcher turns every one of these into DBGp error 300 instead.
     *
     * @return iterable<string, array{string}>
     */
    public static function unresolvablePaths(): iterable
    {
        yield 'unknown variable' => ['$missing'];
        yield 'unknown key' => ["\$rows['second']"];
        yield 'unknown property' => ['$object->missing'];
        yield 'non-canonical numeric key' => ['$rows[03]'];
        yield 'subscript on a scalar' => ['$plain[0]'];
        yield 'property on an array' => ['$rows->first'];
        yield 'property of null' => ['$object->child->child->secret'];
        yield 'method call' => ['$object->describe()'];
        yield 'static property' => ['PathFixture::$secret'];
        yield 'unterminated subscript' => ['$rows[first'];
        yield 'empty property name' => ['$object->'];
        yield 'empty path' => [''];
        yield 'trailing junk' => ['$rows[3]x'];
    }
}
