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
use ZEngine\System\OpCode;

/**
 * Resolves and memoizes the per-frame "should this be observed" decision
 *
 * Each op_array is decided once, keyed by its stable entry address, so the statement
 * hook's steady-state cost is one array lookup. Identity comes from z-engine's
 * pointer-based getFileName()/getFunctionName(), never from native reflection: the
 * native accessors construct reflection state that throws for closure frames, and
 * throwing inside the opcode-handler FFI callback is a fatal engine abort. Both return
 * null rather than throwing when the engine field is unset.
 */
final class OpArrayGate
{
    /** @var array<int, GateDecision> address => decision */
    private array $cache = [];

    /** @var array<int, int> address => line of the op_array's first EXT_STMT */
    private array $entryLines = [];

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

        $file     = $entry->getFileName();
        $name     = $entry->getFunctionName() ?? '{main}';
        $observed = $file !== null && $this->filter->accepts($file);

        return $this->cache[$address] = new GateDecision($observed, $file ?? '', $name, $address);
    }

    /**
     * The line of the op_array's first EXT_STMT, i.e. where a call into it begins executing
     *
     * A frame always starts at the top of its op_array, so the first EXT_STMT is the one
     * statement guaranteed to run exactly once per call - which is what turns "a statement
     * on this line" into "this function was just entered" for a call breakpoint.
     *
     * Scanning the opcodes is O(op_array) and would be far too expensive per statement, so
     * it is memoized per op_array like the decision above, and only ever reached from the
     * call-breakpoint branch: a debuggee with no call breakpoint never pays for it. Returns
     * 0 for an op_array with no EXT_STMT at all (compiled before the debugger attached),
     * which no real line number can equal.
     */
    public function entryLine(ExecutionData $frame): int
    {
        $entry = $frame->getFunctionEntry();
        if ($entry === null || !$entry->isUserDefined()) {
            return 0;
        }

        $address = $entry->getAddress();
        if (isset($this->entryLines[$address])) {
            return $this->entryLines[$address];
        }

        $line = 0;
        foreach ($entry->getOpCodes() as $opLine) {
            if ($opLine->getCode() === OpCode::EXT_STMT) {
                $line = $opLine->getLine();
                break;
            }
        }

        return $this->entryLines[$address] = $line;
    }
}
