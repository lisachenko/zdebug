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

        return $this->cache[$address] = new GateDecision($observed, $file ?? '', $name);
    }
}
