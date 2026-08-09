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

namespace ZDebug\Context;

use ZDebug\Protocol\ResponseBuilder;

/**
 * Serializes a native PHP value into a DBGp <property> element
 *
 * Operates on materialized PHP values (not raw zvals), which needs zero FFI surface:
 * the frame reader hands over already-extracted values. Scalars are base64-encoded;
 * arrays and objects recurse up to max_depth, emitting at most max_children entries
 * each, with page metadata so the IDE can request more.
 *
 * Depth is what makes context_get affordable and property_get necessary: a context is
 * rendered one level deep, and expanding a node in the variables panel comes back as a
 * property_get for that node's fullname, rendered by this same class from the value
 * PropertyPath resolved.
 */
final class PropertySerializer
{
    public function __construct(
        private readonly int $maxDepth = 1,
        private readonly int $maxChildren = 100,
        private readonly int $maxData = 1024,
    ) {}

    /**
     * Renders a single named value as a <property> element
     *
     * $page selects which max_children-sized slice of a container's entries is emitted -
     * the IDE's "show more" on a large array. It applies to the rendered value itself;
     * nested containers always start at their first page, which is the only page an IDE
     * can ask about without expanding them first.
     */
    public function serialize(string $name, string $fullName, mixed $value, int $page = 0, ?string $facet = null): string
    {
        return $this->render($name, $fullName, $value, 0, max(0, $page), $facet);
    }

    private function render(string $name, string $fullName, mixed $value, int $depth, int $page = 0, ?string $facet = null): string
    {
        [$type, $classname] = self::classify($value);

        $attributes = [
            'name'     => $name,
            'fullname' => $fullName,
            'type'     => $type,
        ];
        if ($classname !== null) {
            $attributes['classname'] = $classname;
        }
        // Only an object's own properties have a visibility to report; array elements and
        // frame locals are not declared under one, and DBGp has no facet to describe them
        if ($facet !== null) {
            $attributes['facet'] = $facet;
        }

        if (is_array($value) || is_object($value)) {
            return $this->renderContainer($attributes, $value, $fullName, $depth, $page);
        }

        return $this->renderScalar($attributes, $value);
    }

    /**
     * @param array<string, string> $attributes
     */
    private function renderScalar(array $attributes, mixed $value): string
    {
        $raw = self::scalarToString($value);
        // size reports the full length; the emitted value is clamped to max_data
        $attributes['size']     = (string) strlen($raw);
        $attributes['encoding'] = 'base64';

        return '<property ' . ResponseBuilder::attributes($attributes) . '>'
            . '<![CDATA[' . $this->encodeScalarClamped($raw) . ']]></property>';
    }

    /**
     * @param array<string, string>             $attributes
     * @param array<int|string, mixed>|object   $value
     */
    private function renderContainer(array $attributes, array|object $value, string $fullName, int $depth, int $page): string
    {
        $isArray     = is_array($value);
        $children    = $isArray ? $value : ObjectProperties::entries($value);
        $numChildren = count($children);

        $attributes['children']    = $numChildren > 0 ? '1' : '0';
        $attributes['numchildren'] = (string) $numChildren;

        if ($depth >= $this->maxDepth || $numChildren === 0) {
            return '<property ' . ResponseBuilder::attributes($attributes) . '/>';
        }

        $attributes['page']     = (string) $page;
        $attributes['pagesize'] = (string) $this->maxChildren;

        $body = '';
        foreach (array_slice($children, $page * $this->maxChildren, $this->maxChildren, true) as $key => $child) {
            [$childName, $childFullName] = self::childNames($fullName, $key, $isArray);
            $body .= $child instanceof ObjectProperty
                ? $this->render($childName, $childFullName, $child->value, $depth + 1, 0, $child->facet)
                : $this->render($childName, $childFullName, $child, $depth + 1);
        }

        return '<property ' . ResponseBuilder::attributes($attributes) . '>' . $body . '</property>';
    }

    /**
     * @return array{string, string|null} [dbgp type, classname or null]
     */
    private static function classify(mixed $value): array
    {
        return match (true) {
            $value === null   => ['null', null],
            is_bool($value)   => ['bool', null],
            is_int($value)    => ['int', null],
            is_float($value)  => ['float', null],
            is_string($value) => ['string', null],
            is_array($value)  => ['array', null],
            is_object($value) => ['object', $value::class],
            default           => ['resource', null],
        };
    }

    private function encodeScalarClamped(string $raw): string
    {
        if (strlen($raw) > $this->maxData) {
            $raw = substr($raw, 0, $this->maxData);
        }

        return base64_encode($raw);
    }

    private static function scalarToString(mixed $value): string
    {
        return match (true) {
            $value === null                  => '',
            is_bool($value)                  => $value ? '1' : '0',
            is_string($value)                => $value,
            is_int($value), is_float($value) => (string) $value,
            default                          => '',
        };
    }

    /**
     * @return array{string, string} [display name, fullname path]
     */
    private static function childNames(string $parentFullName, int|string $key, bool $isArray): array
    {
        if ($isArray) {
            $accessor = is_int($key) ? "[{$key}]" : "['{$key}']";

            return [(string) $key, $parentFullName . $accessor];
        }

        return [(string) $key, $parentFullName . '->' . $key];
    }
}
