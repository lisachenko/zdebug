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

/**
 * Decides whether a source file is in scope for debugging
 *
 * An empty prefix list observes everything (useful for small scripts); otherwise a
 * file is observed only when its path begins with one of the configured realpath
 * prefixes. Keeping instrumentation scoped is the main lever against the per-statement
 * cost of the hook (see z-engine docs/self-debugging.md, Performance).
 */
final class FileFilter
{
    public function __construct(
        /** @var list<string> Realpath-normalized path prefixes; empty = observe all */
        private readonly array $prefixes,
    ) {}

    public function accepts(string $file): bool
    {
        // Synthetic filenames are never real breakpoint targets: eval()'d code carries
        // a "<real-path>(NN) : eval()'d code" name, stream wrappers use a scheme prefix
        if ($file === '' || !str_starts_with($file, '/') || str_contains($file, "eval()'d code")) {
            return false;
        }
        if ($this->prefixes === []) {
            return true;
        }
        foreach ($this->prefixes as $prefix) {
            if (str_starts_with($file, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
