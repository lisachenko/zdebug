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

namespace ZDebug\Breakpoint;

/**
 * A single breakpoint as registered by the IDE via breakpoint_set
 *
 * Line breakpoints (with an optional condition, evaluated in the frame), exception
 * breakpoints (matched by class name) and call/return breakpoints (matched by function
 * name). $file is the resolved filesystem path; the DBGp file:// URI is reconstructed on
 * the way out.
 *
 * $hitCount counts the times the breakpoint actually matched (location plus condition);
 * $hitValue / $hitCondition then decide whether such a match suspends the debuggee, per
 * the DBGp `-h` / `-o` arguments.
 *
 * The fields DBGp's breakpoint_update may change ($enabled, $line, $hitValue,
 * $hitCondition) are mutable, everything that identifies the breakpoint is readonly:
 * an update must never turn a breakpoint into a different one behind the IDE's id. $line
 * is indexed by BreakpointRegistry, so it is moved through relocate() rather than
 * assigned directly.
 */
final class Breakpoint
{
    /** Break from the n-th hit onwards (the DBGp default) */
    public const string HIT_GREATER_OR_EQUAL = '>=';

    /** Break on the n-th hit only */
    public const string HIT_EQUAL = '==';

    /** Break on every n-th hit */
    public const string HIT_MULTIPLE = '%';

    /** @var list<string> The hit conditions DBGp defines */
    public const array HIT_CONDITIONS = [self::HIT_GREATER_OR_EQUAL, self::HIT_EQUAL, self::HIT_MULTIPLE];

    public function __construct(
        public readonly int $id,
        public readonly BreakpointType $type,
        public bool $enabled = true,
        public readonly ?string $file = null,
        public ?int $line = null,
        public readonly ?string $condition = null,
        public readonly ?string $exceptionName = null,
        public readonly ?string $functionName = null,
        public readonly bool $temporary = false,
        public int $hitCount = 0,
        public int $hitValue = 0,
        public string $hitCondition = self::HIT_GREATER_OR_EQUAL,
    ) {}

    /** Whether this breakpoint is located by (file, line) - a plain or conditional line breakpoint */
    public bool $isLineType {
        get => $this->type === BreakpointType::Line || $this->type === BreakpointType::Conditional;
    }

    /** Whether this breakpoint fires on entering or leaving a named function */
    public bool $isFunctionType {
        get => $this->type === BreakpointType::Call || $this->type === BreakpointType::Return;
    }

    /** The DBGp state attribute: 'enabled' or 'disabled' */
    public string $state {
        get => $this->enabled ? 'enabled' : 'disabled';
    }

    /**
     * Whether the current $hitCount satisfies the configured hit condition
     *
     * A hit value of zero (the default, and what an IDE sends when the user did not ask
     * for a hit condition) means "every hit counts".
     */
    public bool $hitConditionSatisfied {
        get {
            if ($this->hitValue <= 0) {
                return true;
            }

            return match ($this->hitCondition) {
                self::HIT_EQUAL    => $this->hitCount                   === $this->hitValue,
                self::HIT_MULTIPLE => $this->hitCount % $this->hitValue === 0,
                default            => $this->hitCount >= $this->hitValue,
            };
        }
    }
}
