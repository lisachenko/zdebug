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

/**
 * Reads an object's property table, private and protected slots included
 *
 * get_object_vars() applies the *calling* scope's visibility, and this class is nowhere
 * near the debuggee's: it would report only the public properties, so context_get showed
 * strictly less than the same session's `eval` (which binds its closure to the inspected
 * object and sees everything). The (array) cast is scope-free; the price is mangled keys,
 * "\0Class\0name" for a private property and "\0*\0name" for a protected one, which are
 * decoded back to the plain name the IDE displays - and which must not reach the XML,
 * since NUL is not a legal character there.
 *
 * The one place both halves of property inspection agree on what an object contains:
 * PropertySerializer renders these entries, PropertyPath walks a `->name` step through
 * them, so a property an IDE can see is by construction a property it can also expand.
 */
final class ObjectProperties
{
    public const string FACET_PUBLIC    = 'public';
    public const string FACET_PROTECTED = 'protected';
    public const string FACET_PRIVATE   = 'private';

    /**
     * The object's properties as plain name => value, most-derived declaration winning
     *
     * @return array<string, mixed>
     */
    public static function of(object $value): array
    {
        return array_map(
            static fn(ObjectProperty $property): mixed => $property->value,
            self::entries($value),
        );
    }

    /**
     * The object's properties with the visibility each was declared under
     *
     * A name can appear twice, when a private property is redeclared further down the
     * hierarchy: the most-derived declaration wins, i.e. the one the debuggee's own
     * `$this->name` resolves to in the object's class. The parent's shadowed slot is
     * dropped rather than renamed, because DBGp has no way to address two properties
     * under one fullname.
     *
     * @return array<string, ObjectProperty>
     */
    public static function entries(object $value): array
    {
        $properties = [];
        $owners     = [];
        foreach ((array) $value as $key => $propertyValue) {
            [$name, $owner, $facet] = self::demangle((string) $key, $value::class);
            if (isset($owners[$name]) && !is_subclass_of($owner, $owners[$name])) {
                continue;
            }
            $properties[$name] = new ObjectProperty($propertyValue, $facet);
            $owners[$name]     = $owner;
        }

        return $properties;
    }

    /**
     * Splits a property-table key into its name, declaring class and visibility
     *
     * Only private properties carry their declaring class in the key; public and
     * protected ones are answered with the object's own class, which is the most-derived
     * declaration possible and therefore always wins a collision.
     *
     * @return array{string, string, string} [plain name, declaring class, DBGp facet]
     */
    private static function demangle(string $key, string $className): array
    {
        if (!str_starts_with($key, "\0")) {
            return [$key, $className, self::FACET_PUBLIC];
        }
        // The LAST NUL is the separator: a property name cannot contain one, while an
        // anonymous declaring class carries one inside its own mangled name
        $separator = strrpos($key, "\0");
        if ($separator === false || $separator === 0) {
            return [ltrim($key, "\0"), $className, self::FACET_PUBLIC];
        }

        $owner = substr($key, 1, $separator - 1);
        $name  = substr($key, $separator + 1);

        // "\0*\0name" is protected: visible from the object's own scope like a public one,
        // and with no declaring class recorded to compare against
        $isPrivate = $owner !== '*' && class_exists($owner, false);

        return [
            $name,
            $isPrivate ? $owner : $className,
            $isPrivate ? self::FACET_PRIVATE : self::FACET_PROTECTED,
        ];
    }
}
