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
 * In-memory breakpoint store, indexed for fast per-statement lookup
 *
 * Line breakpoints are indexed by [file][line] so the statement hook's hot path is a
 * pair of isset() checks. Exception and call/return breakpoints are kept in small lists
 * matched by class or function name. Every breakpoint also lives in a by-id map for
 * breakpoint_get/update/remove.
 */
final class BreakpointRegistry
{
    /** @var array<int, Breakpoint> */
    private array $byId = [];

    /** @var array<string, array<int, list<Breakpoint>>> file => line => breakpoints */
    private array $byLocation = [];

    /** @var list<Breakpoint> */
    private array $exceptionBreakpoints = [];

    /** @var list<Breakpoint> */
    private array $callBreakpoints = [];

    /** @var list<Breakpoint> */
    private array $returnBreakpoints = [];

    private int $nextId = 1;

    public function add(Breakpoint $breakpoint): Breakpoint
    {
        $this->byId[$breakpoint->id] = $breakpoint;
        if ($breakpoint->isLineType && $breakpoint->file !== null && $breakpoint->line !== null) {
            $this->byLocation[$breakpoint->file][$breakpoint->line][] = $breakpoint;
        } elseif ($breakpoint->type === BreakpointType::Exception) {
            $this->exceptionBreakpoints[] = $breakpoint;
        } elseif ($breakpoint->type === BreakpointType::Call) {
            $this->callBreakpoints[] = $breakpoint;
        } elseif ($breakpoint->type === BreakpointType::Return) {
            $this->returnBreakpoints[] = $breakpoint;
        }

        return $breakpoint;
    }

    /**
     * Moves a line breakpoint to another line, keeping the location index consistent
     *
     * breakpoint_update may change `-n`, and the line is a key of the index the statement
     * hook reads on its hot path: assigning the field alone would leave the breakpoint
     * firing on its old line forever. A breakpoint that is not indexed by location (an
     * exception or call/return one) simply records the new line.
     */
    public function relocate(Breakpoint $breakpoint, int $line): void
    {
        $file = $breakpoint->file;
        if (!$breakpoint->isLineType || $file === null || $breakpoint->line === null) {
            $breakpoint->line = $line;

            return;
        }

        $this->unindexLine($breakpoint, $file, $breakpoint->line);
        $breakpoint->line                 = $line;
        $this->byLocation[$file][$line][] = $breakpoint;
    }

    public function nextId(): int
    {
        return $this->nextId++;
    }

    public function get(int $id): ?Breakpoint
    {
        return $this->byId[$id] ?? null;
    }

    public function remove(int $id): bool
    {
        $breakpoint = $this->byId[$id] ?? null;
        if ($breakpoint === null) {
            return false;
        }
        unset($this->byId[$id]);

        if ($breakpoint->isLineType && $breakpoint->file !== null && $breakpoint->line !== null) {
            $this->unindexLine($breakpoint, $breakpoint->file, $breakpoint->line);
        } else {
            $this->exceptionBreakpoints = self::without($this->exceptionBreakpoints, $id);
            $this->callBreakpoints      = self::without($this->callBreakpoints, $id);
            $this->returnBreakpoints    = self::without($this->returnBreakpoints, $id);
        }

        return true;
    }

    /**
     * Drops a breakpoint from the [file][line] index, pruning the buckets it empties
     */
    private function unindexLine(Breakpoint $breakpoint, string $file, int $line): void
    {
        $remaining = self::without($this->byLocation[$file][$line] ?? [], $breakpoint->id);
        if ($remaining !== []) {
            $this->byLocation[$file][$line] = $remaining;

            return;
        }

        // Drop the empty line (and file) buckets so hasLineBreakpoints() stays accurate
        unset($this->byLocation[$file][$line]);
        if ($this->byLocation[$file] === []) {
            unset($this->byLocation[$file]);
        }
    }

    /**
     * @param  list<Breakpoint> $breakpoints
     * @return list<Breakpoint>
     */
    private static function without(array $breakpoints, int $id): array
    {
        return array_values(
            array_filter($breakpoints, static fn(Breakpoint $candidate): bool => $candidate->id !== $id),
        );
    }

    /**
     * Drops the one-shot (DBGp `-r 1`) breakpoints among the ones that just triggered
     *
     * A temporary breakpoint is spent by the break it causes, not by the hit it records:
     * a hit that a `-h`/`-o` hit condition filters out leaves it armed. Non-temporary
     * breakpoints in the list are left untouched, so the hook can hand over everything
     * that triggered without pre-filtering.
     *
     * @param list<Breakpoint> $triggered
     */
    public function dropTemporary(array $triggered): void
    {
        foreach ($triggered as $breakpoint) {
            if ($breakpoint->temporary) {
                $this->remove($breakpoint->id);
            }
        }
    }

    /**
     * Whether any file has a line breakpoint (fast global gate for the statement hook)
     */
    public bool $hasLineBreakpoints {
        get => $this->byLocation !== [];
    }

    /**
     * Whether any exception breakpoint is registered (fast global gate for the THROW hook)
     *
     * Deliberately ignores the enabled flag, exactly like $hasLineBreakpoints: this is the
     * cheap "is it worth resolving the thrown value at all" check, and forException() below
     * still filters disabled breakpoints out.
     */
    public bool $hasExceptionBreakpoints {
        get => $this->exceptionBreakpoints !== [];
    }

    /**
     * Returns the enabled line breakpoints registered at a (file, line), if any
     *
     * @return list<Breakpoint>
     */
    public function atLine(string $file, int $line): array
    {
        $candidates = $this->byLocation[$file][$line] ?? [];

        return array_values(array_filter($candidates, static fn(Breakpoint $bp): bool => $bp->enabled));
    }

    /**
     * Returns the enabled exception breakpoints whose class matches the given throwable class
     *
     * @return list<Breakpoint>
     */
    public function forException(string $className): array
    {
        $matches = [];
        foreach ($this->exceptionBreakpoints as $breakpoint) {
            if (!$breakpoint->enabled) {
                continue;
            }
            $name = $breakpoint->exceptionName;
            if ($name === null || $name === '*' || is_a($className, $name, true)) {
                $matches[] = $breakpoint;
            }
        }

        return $matches;
    }

    /**
     * Whether any call breakpoint is registered (the statement hook's entry-detection gate)
     */
    public bool $hasCallBreakpoints {
        get => $this->callBreakpoints !== [];
    }

    /**
     * Whether any return breakpoint is registered (fast global gate for the RETURN hook)
     */
    public bool $hasReturnBreakpoints {
        get => $this->returnBreakpoints !== [];
    }

    /**
     * The enabled call breakpoints matching a function being entered
     *
     * @return list<Breakpoint>
     */
    public function forCall(string $functionName, ?string $className): array
    {
        return self::matchingFunction($this->callBreakpoints, $functionName, $className);
    }

    /**
     * The enabled return breakpoints matching a function being left
     *
     * @return list<Breakpoint>
     */
    public function forReturn(string $functionName, ?string $className): array
    {
        return self::matchingFunction($this->returnBreakpoints, $functionName, $className);
    }

    /**
     * Matches a frame's function against the `-m` names of function breakpoints
     *
     * DBGp only defines `-m FUNCTION`, and clients spell a method in it three different
     * ways ("tick", "Counter::tick", "Counter->tick"), so the name is compared segment by
     * segment: the method part always has to match, the class part only when the client
     * supplied one AND the frame has a bound object to compare it against. A static method
     * (no `$this`) therefore matches on its method name alone rather than never matching -
     * the failure mode of a too-narrow comparison is a breakpoint that silently never
     * fires, which is far worse to debug than one that fires once too often.
     *
     * @param  list<Breakpoint> $breakpoints
     * @return list<Breakpoint>
     */
    private static function matchingFunction(array $breakpoints, string $functionName, ?string $className): array
    {
        $matches = [];
        foreach ($breakpoints as $breakpoint) {
            if (!$breakpoint->enabled || $breakpoint->functionName === null) {
                continue;
            }
            [$wantedClass, $wantedFunction] = self::splitFunctionName($breakpoint->functionName);
            if (strcasecmp($wantedFunction, $functionName) !== 0) {
                continue;
            }
            if ($wantedClass !== null && $className !== null && !is_a($className, $wantedClass, true)) {
                continue;
            }
            $matches[] = $breakpoint;
        }

        return $matches;
    }

    /**
     * @return array{string|null, string} [class part or null, function part]
     */
    private static function splitFunctionName(string $name): array
    {
        foreach (['::', '->'] as $separator) {
            $position = strrpos($name, $separator);
            if ($position !== false) {
                return [substr($name, 0, $position), substr($name, $position + strlen($separator))];
            }
        }

        return [null, $name];
    }

    /**
     * @return list<Breakpoint>
     */
    public function all(): array
    {
        return array_values($this->byId);
    }
}
