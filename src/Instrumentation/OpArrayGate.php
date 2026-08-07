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

namespace ZDebug\Instrumentation;

use ZEngine\System\ExecutionData;
use ZEngine\Type\StringEntry;

/**
 * Resolves and memoizes the per-frame "should this be observed" decision
 *
 * Each op_array is decided once, keyed by its stable entry address, so the statement
 * hook's steady-state cost is one array lookup. This is also the single place in
 * zdebug that reads a raw engine struct field: op_array->filename / ->function_name.
 * The native ReflectionFunction::getFileName()/getName() cannot be used here because
 * they throw for closure frames (uninitialized native reflection state), and throwing
 * inside the opcode-handler FFI callback is a fatal engine abort.
 */
final class OpArrayGate
{
    /** @var array<int, GateDecision> address => decision */
    private array $cache = [];

    public function __construct(private readonly FileFilter $filter) {}

    /**
     * Returns the (memoized) decision for the frame's executing op_array
     */
    public function decide(ExecutionData $frame): GateDecision
    {
        $entry = $frame->getFunctionEntry();
        if ($entry === null || !$entry->isUserDefined()) {
            // Internal functions and function-less frames have no observable source
            return GateDecision::notObserved();
        }

        $address = $entry->getAddress();
        if (isset($this->cache[$address])) {
            return $this->cache[$address];
        }

        $opArray  = $entry->getOpArrayPointer();
        $file     = self::readString($opArray->filename);
        $name     = self::readString($opArray->function_name) ?? '{main}';
        $observed = $file !== null && $this->filter->accepts($file);

        return $this->cache[$address] = new GateDecision($observed, $file ?? '', $name);
    }

    /**
     * Reads a zend_string* engine field into a PHP string, or null when the pointer is null
     */
    private static function readString(mixed $pointer): ?string
    {
        if ($pointer === null) {
            return null;
        }

        return StringEntry::fromCData($pointer)->getStringValue();
    }
}
