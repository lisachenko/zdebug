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

use PHPUnit\Framework\TestCase;
use ZDebug\Context\PropertySerializer;

final class PropertyFixture
{
    public int $count    = 3;
    public string $label = 'x';
}

class VisibilityFixture
{
    public string $open      = 'public';
    protected string $shared = 'protected';
    private string $own      = 'private';
    private string $shadowed = 'base';

    /** Reading them from the class itself is exactly what a suspended frame's `eval` does */
    public function describe(): string
    {
        return $this->own . $this->shared . $this->shadowed;
    }
}

final class DerivedVisibilityFixture extends VisibilityFixture
{
    // Redeclares the parent's private slot: both live in the property table at once
    private string $shadowed = 'derived';

    public function describe(): string
    {
        return $this->shadowed;
    }
}

final class UninitializedFixture
{
    public int $assigned = 1;

    public string $never;
}

final class PropertySerializerTest extends TestCase
{
    public function testIntScalarCarriesTypeAndBase64Value(): void
    {
        $property = $this->parse((new PropertySerializer())->serialize('$n', '$n', 42));
        $this->assertSame('int', $property->getAttribute('type'));
        $this->assertSame('$n', $property->getAttribute('name'));
        $this->assertSame('base64', $property->getAttribute('encoding'));
        $this->assertSame('42', base64_decode($property->textContent));
    }

    public function testStringScalarReportsFullSize(): void
    {
        $property = $this->parse((new PropertySerializer())->serialize('s', '$s', 'hello'));
        $this->assertSame('string', $property->getAttribute('type'));
        $this->assertSame('5', $property->getAttribute('size'));
        $this->assertSame('hello', base64_decode($property->textContent));
    }

    public function testBoolAndNull(): void
    {
        $true = $this->parse((new PropertySerializer())->serialize('b', '$b', true));
        $this->assertSame('bool', $true->getAttribute('type'));
        $this->assertSame('1', base64_decode($true->textContent));

        $null = $this->parse((new PropertySerializer())->serialize('z', '$z', null));
        $this->assertSame('null', $null->getAttribute('type'));
    }

    public function testArrayRecursesOneLevelWithChildFullNames(): void
    {
        $serializer = new PropertySerializer(maxDepth: 1);
        $property   = $this->parse($serializer->serialize('a', '$a', ['x' => 1, 7 => 'v']));

        $this->assertSame('array', $property->getAttribute('type'));
        $this->assertSame('1', $property->getAttribute('children'));
        $this->assertSame('2', $property->getAttribute('numchildren'));

        $children = $property->getElementsByTagName('property');
        $this->assertSame(2, $children->length);
        $first = $children->item(0);
        $this->assertInstanceOf(\DOMElement::class, $first);
        $this->assertSame("\$a['x']", $first->getAttribute('fullname'));
        $second = $children->item(1);
        $this->assertInstanceOf(\DOMElement::class, $second);
        $this->assertSame('$a[7]', $second->getAttribute('fullname'));
    }

    public function testDepthLimitStopsRecursionButReportsChildCount(): void
    {
        $serializer = new PropertySerializer(maxDepth: 1);
        $property   = $this->parse($serializer->serialize('a', '$a', ['nested' => ['deep' => 1]]));

        $nested = $property->getElementsByTagName('property')->item(0);
        $this->assertInstanceOf(\DOMElement::class, $nested);
        // The nested array is announced as having children but not expanded at depth 1
        $this->assertSame('array', $nested->getAttribute('type'));
        $this->assertSame('1', $nested->getAttribute('children'));
        $this->assertFalse($nested->hasChildNodes());
    }

    public function testMaxChildrenCapsEmittedEntries(): void
    {
        $serializer = new PropertySerializer(maxDepth: 1, maxChildren: 2);
        $property   = $this->parse($serializer->serialize('a', '$a', [1, 2, 3, 4]));

        $this->assertSame('4', $property->getAttribute('numchildren'));
        $this->assertSame(2, $property->getElementsByTagName('property')->length);
    }

    /**
     * Paging is how property_get answers for a container an IDE cannot show in one go:
     * the slice moves, while numchildren keeps describing the whole thing
     */
    public function testPageSelectsTheSliceOfChildrenToEmit(): void
    {
        $serializer = new PropertySerializer(maxDepth: 1, maxChildren: 2);
        $property   = $this->parse($serializer->serialize('a', '$a', [10, 20, 30, 40, 50], page: 1));

        $this->assertSame('5', $property->getAttribute('numchildren'));
        $this->assertSame('1', $property->getAttribute('page'));
        $this->assertSame('2', $property->getAttribute('pagesize'));
        $this->assertSame(['2' => '30', '3' => '40'], $this->children($property));
    }

    public function testTheLastPageMayBeShorterAndAPageBeyondTheEndIsEmpty(): void
    {
        $serializer = new PropertySerializer(maxDepth: 1, maxChildren: 2);

        $last = $this->parse($serializer->serialize('a', '$a', [10, 20, 30], page: 1));
        $this->assertSame(['2' => '30'], $this->children($last));

        $beyond = $this->parse($serializer->serialize('a', '$a', [10, 20, 30], page: 9));
        $this->assertSame('3', $beyond->getAttribute('numchildren'), 'the count still describes the whole array');
        $this->assertSame([], $this->children($beyond));
    }

    /**
     * Only the rendered value is paged: a child container is a node the IDE has not
     * expanded yet, so there is no page it could have asked about
     */
    public function testNestedContainersAlwaysStartAtTheirFirstPage(): void
    {
        $serializer = new PropertySerializer(maxDepth: 2, maxChildren: 2);
        $property   = $this->parse($serializer->serialize('a', '$a', [['x', 'y', 'z'], ['p', 'q']], page: 1));

        $this->assertSame([], $this->children($property), 'page 1 of a two-element array is empty');
        $this->assertSame('0', $this->parse($serializer->serialize('a', '$a', [['x', 'y', 'z']]))
            ->getElementsByTagName('property')->item(0)?->getAttribute('page'));
    }

    public function testMaxDataClampsScalarValue(): void
    {
        $serializer = new PropertySerializer(maxData: 4);
        $property   = $this->parse($serializer->serialize('s', '$s', 'abcdefgh'));
        // size is the full length, the value is clamped to max_data bytes
        $this->assertSame('8', $property->getAttribute('size'));
        $this->assertSame('abcd', base64_decode($property->textContent));
    }

    public function testObjectReportsClassnameAndProperties(): void
    {
        $object     = new PropertyFixture();
        $serializer = new PropertySerializer(maxDepth: 1);
        $property   = $this->parse($serializer->serialize('$o', '$o', $object));

        $this->assertSame('object', $property->getAttribute('type'));
        $this->assertSame(PropertyFixture::class, $property->getAttribute('classname'));
        $this->assertSame(2, $property->getElementsByTagName('property')->length);
    }

    public function testPrivateAndProtectedPropertiesAreVisibleUnderTheirPlainNames(): void
    {
        $serializer = new PropertySerializer(maxDepth: 1);
        $property   = $this->parse($serializer->serialize('$o', '$o', new VisibilityFixture()));

        // get_object_vars() from this scope would have shown only $open, while the same
        // session's `eval` sees all three - context_get must not be the poorer view
        $this->assertSame('4', $property->getAttribute('numchildren'));
        $this->assertSame([
            'open'     => 'public',
            'shared'   => 'protected',
            'own'      => 'private',
            'shadowed' => 'base',
        ], $this->children($property));
    }

    /**
     * facet is how an IDE draws the padlock next to a non-public property, and the
     * mangled property-table key is the only place the visibility is recorded
     */
    public function testObjectPropertiesReportTheirVisibilityAsAFacet(): void
    {
        $serializer = new PropertySerializer(maxDepth: 1);
        $property   = $this->parse($serializer->serialize('$o', '$o', new VisibilityFixture()));

        $facets = [];
        foreach ($property->getElementsByTagName('property') as $child) {
            $facets[$child->getAttribute('name')] = $child->getAttribute('facet');
        }
        $this->assertSame([
            'open'     => 'public',
            'shared'   => 'protected',
            'own'      => 'private',
            'shadowed' => 'private',
        ], $facets);

        // The object itself is a frame local, not a declared member: it has no visibility
        $this->assertFalse($property->hasAttribute('facet'));
    }

    /**
     * An array element is not declared under a visibility, so it carries no facet at all
     */
    public function testArrayElementsCarryNoFacet(): void
    {
        $serializer = new PropertySerializer(maxDepth: 1);
        $property   = $this->parse($serializer->serialize('a', '$a', ['x' => 1]));

        $child = $property->getElementsByTagName('property')->item(0);
        $this->assertInstanceOf(\DOMElement::class, $child);
        $this->assertFalse($child->hasAttribute('facet'));
    }

    public function testChildFullNamesUseThePlainPropertyName(): void
    {
        $serializer = new PropertySerializer(maxDepth: 1);
        $property   = $this->parse($serializer->serialize('$o', '$o', new VisibilityFixture()));

        $names = [];
        foreach ($property->getElementsByTagName('property') as $child) {
            $names[] = $child->getAttribute('fullname');
        }
        // The mangled "\0Class\0name" spelling would be both unusable and illegal in XML
        $this->assertSame(['$o->open', '$o->shared', '$o->own', '$o->shadowed'], $names);
    }

    public function testAShadowedPrivatePropertyReportsTheMostDerivedDeclaration(): void
    {
        $serializer = new PropertySerializer(maxDepth: 1);
        $property   = $this->parse($serializer->serialize('$o', '$o', new DerivedVisibilityFixture()));

        // Both slots exist in the property table; the one the object's own scope resolves
        // to is the one DBGp can address under the single fullname `$o->shadowed`
        $this->assertSame('derived', $this->children($property)['shadowed'] ?? null);
        $this->assertSame('4', $property->getAttribute('numchildren'));
    }

    public function testUninitializedTypedPropertiesAreNotReported(): void
    {
        $serializer = new PropertySerializer(maxDepth: 1);
        $property   = $this->parse($serializer->serialize('$o', '$o', new UninitializedFixture()));

        // A typed property with no value has no zval at all; inventing a null for it would
        // misreport the debuggee's state
        $this->assertSame(['assigned' => '1'], $this->children($property));
    }

    public function testAnonymousClassNameDoesNotBreakWellFormedness(): void
    {
        // Anonymous class names embed a NUL byte, illegal in XML; the serializer must
        // still emit a well-formed document (the sanitizer strips the NUL)
        $object = new class {
            public int $n = 1;
        };
        $property = $this->parse((new PropertySerializer())->serialize('$o', '$o', $object));
        $this->assertSame('object', $property->getAttribute('type'));
        $this->assertStringStartsWith('class@anonymous', $property->getAttribute('classname'));
    }

    /**
     * The direct <property> children of a container, as name => decoded value
     *
     * @return array<string, string>
     */
    private function children(\DOMElement $property): array
    {
        $values = [];
        foreach ($property->getElementsByTagName('property') as $child) {
            $values[$child->getAttribute('name')] = base64_decode($child->textContent);
        }

        return $values;
    }

    private function parse(string $xml): \DOMElement
    {
        $document = new \DOMDocument();
        $this->assertTrue($document->loadXML($xml), 'Property XML is not well-formed');
        $root = $document->documentElement;
        $this->assertInstanceOf(\DOMElement::class, $root);

        return $root;
    }
}
