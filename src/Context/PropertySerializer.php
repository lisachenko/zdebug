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
 * each, with page metadata so the IDE can request more. This is the M1 breadth
 * (scalars + one array/object level by default); deeper paging is exercised in M2.
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
     */
    public function serialize(string $name, string $fullName, mixed $value): string
    {
        return $this->render($name, $fullName, $value, 0);
    }

    private function render(string $name, string $fullName, mixed $value, int $depth): string
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

        if (is_array($value) || is_object($value)) {
            return $this->renderContainer($attributes, $value, $fullName, $depth);
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
    private function renderContainer(array $attributes, array|object $value, string $fullName, int $depth): string
    {
        $children    = is_array($value) ? $value : self::objectProperties($value);
        $numChildren = count($children);

        $attributes['children']    = $numChildren > 0 ? '1' : '0';
        $attributes['numchildren'] = (string) $numChildren;

        if ($depth >= $this->maxDepth || $numChildren === 0) {
            return '<property ' . ResponseBuilder::attributes($attributes) . '/>';
        }

        $attributes['page']     = '0';
        $attributes['pagesize'] = (string) $this->maxChildren;

        $body     = '';
        $rendered = 0;
        foreach ($children as $key => $childValue) {
            if ($rendered >= $this->maxChildren) {
                break;
            }
            [$childName, $childFullName] = self::childNames($fullName, $key, is_array($value));
            $body .= $this->render($childName, $childFullName, $childValue, $depth + 1);
            $rendered++;
        }

        return '<property ' . ResponseBuilder::attributes($attributes) . '>' . $body . '</property>';
    }

    /**
     * Collects an object's properties, private and protected ones included
     *
     * get_object_vars() applies the *calling* scope's visibility, and this class is
     * nowhere near the debuggee's: it would report only the public properties, so
     * context_get showed strictly less than the same session's `eval` (which binds its
     * closure to the inspected object and sees everything). The (array) cast is
     * scope-free; the price is mangled keys, "\0Class\0name" for a private property and
     * "\0*\0name" for a protected one, which are decoded back to the plain name the IDE
     * displays - and which must not reach the XML, since NUL is not a legal character
     * there.
     *
     * A name can appear twice, when a private property is redeclared further down the
     * hierarchy: the most-derived declaration wins, i.e. the one the debuggee's own
     * `$this->name` resolves to in the object's class. The parent's shadowed slot is
     * dropped rather than renamed, because DBGp has no way to address two properties
     * under one fullname.
     *
     * @return array<string, mixed>
     */
    private static function objectProperties(object $value): array
    {
        $properties = [];
        $owners     = [];
        foreach ((array) $value as $key => $propertyValue) {
            [$name, $owner] = self::demangle((string) $key, $value::class);
            if (isset($owners[$name]) && !is_subclass_of($owner, $owners[$name])) {
                continue;
            }
            $properties[$name] = $propertyValue;
            $owners[$name]     = $owner;
        }

        return $properties;
    }

    /**
     * Splits a property-table key into its plain name and the class that declares it
     *
     * Only private properties carry their declaring class in the key; public and
     * protected ones are answered with the object's own class, which is the most-derived
     * declaration possible and therefore always wins a collision.
     *
     * @return array{string, string} [plain name, declaring class]
     */
    private static function demangle(string $key, string $className): array
    {
        if (!str_starts_with($key, "\0")) {
            return [$key, $className];
        }
        // The LAST NUL is the separator: a property name cannot contain one, while an
        // anonymous declaring class carries one inside its own mangled name
        $separator = strrpos($key, "\0");
        if ($separator === false || $separator === 0) {
            return [ltrim($key, "\0"), $className];
        }

        $owner = substr($key, 1, $separator - 1);
        $name  = substr($key, $separator + 1);

        // "\0*\0name" is protected: visible from the object's own scope like a public one,
        // and with no declaring class recorded to compare against
        $isPrivate = $owner !== '*' && class_exists($owner, false);

        return [$name, $isPrivate ? $owner : $className];
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
