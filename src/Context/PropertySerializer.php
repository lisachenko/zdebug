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
        $children    = is_array($value) ? $value : get_object_vars($value);
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
