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

use ZEngine\Reflection\ReflectionValue;

/**
 * Writes a value back into the debuggee, for the DBGp property_set command
 *
 * The only path in zdebug that changes the program being debugged, and the asymmetry with
 * reading is the whole design. Reading materializes engine values into native copies and
 * walks the copies; a write has to end up in the live zval the frame actually reads, so it
 * starts from the ReflectionValue of the base variable's CV slot and pushes the whole
 * (possibly rebuilt) root back through setNativeValue().
 *
 * Rebuilding rather than mutating in place is what makes the intermediate steps correct:
 * PHP arrays are value types, so the array behind `$rows` that a read handed over is a
 * copy, and writing into that copy would change nothing the debuggee can observe. Objects
 * are handles, so a property written through ReflectionProperty lands in the real object -
 * assigning the root back afterwards is then a harmless no-op that keeps one code path
 * for both.
 *
 * Failure is a return of false, never an exception: property_set is dispatched from inside
 * the suspended statement hook, and the engine writes below can legitimately refuse (a
 * readonly property, a typed property that rejects the value). The caller reports
 * success="0" and the session carries on.
 */
final class PropertyWriter
{
    /**
     * Assigns $value at the end of $steps, starting from the live slot $slot
     *
     * @param list<PropertyStep> $steps
     */
    public static function write(ReflectionValue $slot, array $steps, mixed $value): bool
    {
        try {
            if ($steps === []) {
                $slot->setNativeValue($value);

                return true;
            }

            $root = null;
            $slot->getNativeValue($root);
            $updated = self::assign($root, $steps, 0, $value);
            if ($updated === null) {
                return false;
            }
            $slot->setNativeValue($updated[0]);

            return true;
        } catch (\Throwable) {
            // A refused write (readonly, type mismatch) is the IDE's problem to report,
            // not a debugger failure - and never something that may reach the FFI callback
            return false;
        }
    }

    /**
     * Rebuilds $container with $value assigned at the remaining steps
     *
     * Returns the container to store back, wrapped in a one-element list, or null when
     * the path does not exist. Only *existing* keys and properties are followed: a
     * property_set is an edit of the debuggee's state, and inventing an array key the
     * program never created would be an addition disguised as one.
     *
     * @param  list<PropertyStep> $steps
     * @return array{mixed}|null
     */
    private static function assign(mixed $container, array $steps, int $index, mixed $value): ?array
    {
        $step   = $steps[$index];
        $isLast = $index === count($steps) - 1;

        if ($step->isIndex) {
            if (!is_array($container) || !array_key_exists($step->key, $container)) {
                return null;
            }
            $child = $isLast ? [$value] : self::assign($container[$step->key], $steps, $index + 1, $value);
            if ($child === null) {
                return null;
            }
            $container[$step->key] = $child[0];

            return [$container];
        }

        if (!is_object($container)) {
            return null;
        }
        $property = self::declaredProperty($container, $step->key);
        if ($property === null) {
            return null;
        }
        $child = $isLast
            ? [$value]
            : self::assign($property->isInitialized($container) ? $property->getValue($container) : null, $steps, $index + 1, $value);
        if ($child === null) {
            return null;
        }
        $property->setValue($container, $child[0]);

        return [$container];
    }

    /**
     * Finds the property declaration `$object->name` resolves to, or null when there is none
     *
     * Walks from the object's own class upwards, so a private property redeclared in a
     * subclass is reached through the same "most-derived wins" rule ObjectProperties
     * applies when reading - otherwise property_set could write the parent's shadowed slot
     * while property_get keeps showing the child's.
     */
    private static function declaredProperty(object $object, string $name): ?\ReflectionProperty
    {
        for ($class = new \ReflectionClass($object); $class !== false; $class = $class->getParentClass()) {
            if ($class->hasProperty($name)) {
                $property = $class->getProperty($name);
                // Statics live on the class, not on the object this path walks through
                return $property->isStatic() ? null : $property;
            }
        }

        return null;
    }
}
