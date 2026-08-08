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
 * pair of isset() checks. Exception breakpoints are kept in a small list matched by
 * class name. Every breakpoint also lives in a by-id map for breakpoint_remove/update.
 */
final class BreakpointRegistry
{
    /** @var array<int, Breakpoint> */
    private array $byId = [];

    /** @var array<string, array<int, list<Breakpoint>>> file => line => breakpoints */
    private array $byLocation = [];

    /** @var list<Breakpoint> */
    private array $exceptionBreakpoints = [];

    private int $nextId = 1;

    public function add(Breakpoint $breakpoint): Breakpoint
    {
        $this->byId[$breakpoint->id] = $breakpoint;
        if ($breakpoint->isLineType() && $breakpoint->file !== null && $breakpoint->line !== null) {
            $this->byLocation[$breakpoint->file][$breakpoint->line][] = $breakpoint;
        } elseif ($breakpoint->type === BreakpointType::Exception) {
            $this->exceptionBreakpoints[] = $breakpoint;
        }

        return $breakpoint;
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

        if ($breakpoint->isLineType() && $breakpoint->file !== null && $breakpoint->line !== null) {
            $bucket    = $this->byLocation[$breakpoint->file][$breakpoint->line] ?? [];
            $remaining = array_values(
                array_filter($bucket, static fn(Breakpoint $candidate): bool => $candidate->id !== $id),
            );
            if ($remaining === []) {
                // Drop the empty line (and file) buckets so hasLineBreakpoints() stays accurate
                unset($this->byLocation[$breakpoint->file][$breakpoint->line]);
                if ($this->byLocation[$breakpoint->file] === []) {
                    unset($this->byLocation[$breakpoint->file]);
                }
            } else {
                $this->byLocation[$breakpoint->file][$breakpoint->line] = $remaining;
            }
        } else {
            $this->exceptionBreakpoints = array_values(
                array_filter($this->exceptionBreakpoints, static fn(Breakpoint $candidate): bool => $candidate->id !== $id),
            );
        }

        return true;
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
    public function hasLineBreakpoints(): bool
    {
        return $this->byLocation !== [];
    }

    /**
     * Whether any exception breakpoint is registered (fast global gate for the THROW hook)
     *
     * Deliberately ignores the enabled flag, exactly like hasLineBreakpoints(): this is the
     * cheap "is it worth resolving the thrown value at all" check, and forException() below
     * still filters disabled breakpoints out.
     */
    public function hasExceptionBreakpoints(): bool
    {
        return $this->exceptionBreakpoints !== [];
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
     * @return list<Breakpoint>
     */
    public function all(): array
    {
        return array_values($this->byId);
    }
}
