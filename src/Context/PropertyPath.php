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
 * Resolves a DBGp fullname ("$error->previous->message") against a context's variables
 *
 * property_get addresses a value by the very `fullname` PropertySerializer put on the
 * <property> element the IDE is expanding, so the two are one grammar read from both
 * ends: `$name`, `->property` and `[index]` steps, nested to any depth. Anything else -
 * a method call, a static property, an unknown step - is *not* guessed at; the caller
 * turns a null into DBGp error 300, which is what an IDE knows how to render.
 *
 * Resolution walks the already-materialized native values ContextProvider handed over,
 * so it needs no FFI and cannot run debuggee code: reading a property here never fires
 * __get(), exactly as the variables panel of a debugger should behave.
 */
final class PropertyPath
{
    private const string STEP_PROPERTY = 'property';
    private const string STEP_INDEX    = 'index';

    /**
     * Looks a fullname up in a context, or returns null when it addresses nothing
     *
     * A leading `$` is optional: the DBGp spec spells names without it, while every IDE
     * that learned the protocol from Xdebug sends the PHP spelling, and the two must find
     * the same variable.
     *
     * @param array<string, mixed> $variables Context variables, keyed as ContextProvider emits them ('$name')
     */
    public static function resolve(array $variables, string $fullName): ?PropertyReference
    {
        $path   = trim($fullName);
        $parsed = self::parse($path);
        if ($parsed === null) {
            return null;
        }
        [$base, $steps] = $parsed;

        $key = array_key_exists($base, $variables) ? $base : '$' . ltrim($base, '$');
        if (!array_key_exists($key, $variables)) {
            return null;
        }

        $value = $variables[$key];
        $name  = $base;
        foreach ($steps as [$kind, $step]) {
            $resolved = self::step($value, $kind, $step);
            if ($resolved === null) {
                return null;
            }
            $value = $resolved[0];
            $name  = $step;
        }

        return new PropertyReference($name, $path, $value);
    }

    /**
     * Takes one step into a container, or returns null when it leads nowhere
     *
     * The found value is wrapped in a one-element list so that a property legitimately
     * holding null stays distinguishable from a property that does not exist.
     *
     * @return array{mixed}|null
     */
    private static function step(mixed $value, string $kind, string $step): ?array
    {
        if ($kind === self::STEP_PROPERTY) {
            if (!is_object($value)) {
                return null;
            }
            $properties = ObjectProperties::of($value);

            return array_key_exists($step, $properties) ? [$properties[$step]] : null;
        }

        // An array subscript: PHP normalizes a canonical numeric string key to an int on
        // lookup, so "[7]" finds the int key 7 without any casting here
        if (!is_array($value) || !array_key_exists($step, $value)) {
            return null;
        }

        return [$value[$step]];
    }

    /**
     * Splits a fullname into its base variable and the steps taken from it
     *
     * @return array{string, list<array{string, string}>}|null
     */
    private static function parse(string $path): ?array
    {
        $length = strlen($path);
        $base   = '';
        $steps  = [];

        for ($index = 0; $index < $length;) {
            $character = $path[$index];

            if ($character === '[') {
                $close = strpos($path, ']', $index);
                if ($close === false) {
                    return null;
                }
                $steps[] = [self::STEP_INDEX, self::unquote(substr($path, $index + 1, $close - $index - 1))];
                $index   = $close + 1;
                continue;
            }

            if ($character === '-' && ($path[$index + 1] ?? '') === '>') {
                $index += 2;
                $start = $index;
                while ($index < $length && !self::startsStep($path, $index)) {
                    $index++;
                }
                $name = substr($path, $start, $index - $start);
                if ($name === '') {
                    return null;
                }
                $steps[] = [self::STEP_PROPERTY, self::unquote($name)];
                continue;
            }

            // Plain characters belong to the base variable, and only before the first step
            if ($steps !== []) {
                return null;
            }
            $base .= $character;
            $index++;
        }

        return $base === '' ? null : [$base, $steps];
    }

    /**
     * Whether a new step (`[` or `->`) begins at this offset
     */
    private static function startsStep(string $path, int $index): bool
    {
        return $path[$index] === '[' || ($path[$index] === '-' && ($path[$index + 1] ?? '') === '>');
    }

    /**
     * Strips the quotes an array subscript may carry: `['a']` and `[a]` are the same key
     */
    private static function unquote(string $key): string
    {
        $quote = $key[0] ?? '';
        if (($quote === '"' || $quote === "'") && strlen($key) >= 2 && str_ends_with($key, $quote)) {
            $key = substr($key, 1, -1);

            return str_replace(['\\' . $quote, '\\\\'], [$quote, '\\'], $key);
        }

        return $key;
    }
}
