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

use ZEngine\Core;
use ZEngine\Reflection\ReflectionValue;
use ZEngine\System\ExecutionData;

/**
 * Extracts variable contexts from a suspended frame for context_get / property_get
 *
 * Locals (context 0) come from the frame's compiled-variable slots by name; the
 * "$this" pseudo-variable is added when the frame has an object scope. Superglobals
 * (context 1) are read from the engine global symbol table. Values are materialized to
 * native PHP right away (the M1 breadth) so PropertySerializer stays FFI-free.
 */
final class ContextProvider
{
    public const int CONTEXT_LOCALS       = 0;
    public const int CONTEXT_SUPERGLOBALS = 1;

    private const array SUPERGLOBAL_NAMES = [
        '_GET', '_POST', '_COOKIE', '_FILES', '_ENV', '_REQUEST', '_SERVER', '_SESSION', 'GLOBALS',
    ];

    /**
     * Returns the named variables of a context as name => native value
     *
     * @return array<string, mixed>
     */
    public function variables(StackFrame $frame, int $contextId): array
    {
        return match ($contextId) {
            self::CONTEXT_LOCALS       => $this->locals($frame->execution),
            self::CONTEXT_SUPERGLOBALS => $this->superglobals(),
            default                    => [],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function locals(ExecutionData $execution): array
    {
        $variables = [];
        foreach ($execution->getLocalVariables() as $name => $value) {
            $variables['$' . $name] = self::materialize($value);
        }

        $thisValue = $execution->getThis();
        if ($thisValue->getType() === ReflectionValue::IS_OBJECT) {
            $variables['$this'] = self::materialize($thisValue);
        }

        return $variables;
    }

    /**
     * @return array<string, mixed>
     */
    private function superglobals(): array
    {
        $globals = Core::$executor->getGlobalSymbolTable();
        $result  = [];
        foreach (self::SUPERGLOBAL_NAMES as $name) {
            $entry = $globals->find($name);
            if ($entry !== null) {
                $result['$' . $name] = self::materialize($entry);
            }
        }

        return $result;
    }

    private static function materialize(ReflectionValue $value): mixed
    {
        $native = null;
        $value->getNativeValue($native);

        return $native;
    }
}
