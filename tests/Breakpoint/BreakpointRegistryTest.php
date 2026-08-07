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

namespace ZDebug\Tests\Breakpoint;

use PHPUnit\Framework\TestCase;
use ZDebug\Breakpoint\Breakpoint;
use ZDebug\Breakpoint\BreakpointRegistry;

final class BreakpointRegistryTest extends TestCase
{
    public function testAllocatesMonotonicIds(): void
    {
        $registry = new BreakpointRegistry();
        $this->assertSame(1, $registry->nextId());
        $this->assertSame(2, $registry->nextId());
    }

    public function testLineBreakpointIsFoundByLocation(): void
    {
        $registry = new BreakpointRegistry();
        $registry->add(new Breakpoint(id: 1, type: Breakpoint::TYPE_LINE, file: '/app/x.php', line: 42));

        $this->assertTrue($registry->hasLineBreakpoints());
        $this->assertCount(1, $registry->atLine('/app/x.php', 42));
        $this->assertCount(0, $registry->atLine('/app/x.php', 43));
        $this->assertCount(0, $registry->atLine('/other.php', 42));
    }

    public function testDisabledBreakpointIsNotReturnedByLocation(): void
    {
        $registry = new BreakpointRegistry();
        $registry->add(new Breakpoint(id: 1, type: Breakpoint::TYPE_LINE, enabled: false, file: '/app/x.php', line: 7));

        $this->assertCount(0, $registry->atLine('/app/x.php', 7));
    }

    public function testRemoveDropsFromBothIndexes(): void
    {
        $registry = new BreakpointRegistry();
        $registry->add(new Breakpoint(id: 5, type: Breakpoint::TYPE_LINE, file: '/app/x.php', line: 10));

        $this->assertTrue($registry->remove(5));
        $this->assertNull($registry->get(5));
        $this->assertCount(0, $registry->atLine('/app/x.php', 10));
        $this->assertFalse($registry->hasLineBreakpoints());
        $this->assertFalse($registry->remove(5));
    }

    public function testMultipleBreakpointsOnSameLine(): void
    {
        $registry = new BreakpointRegistry();
        $registry->add(new Breakpoint(id: 1, type: Breakpoint::TYPE_LINE, file: '/a.php', line: 3));
        $registry->add(new Breakpoint(id: 2, type: Breakpoint::TYPE_CONDITION, file: '/a.php', line: 3, condition: '$x > 1'));

        $this->assertCount(2, $registry->atLine('/a.php', 3));
    }

    public function testExceptionBreakpointSubclassMatch(): void
    {
        $registry = new BreakpointRegistry();
        $registry->add(new Breakpoint(id: 1, type: Breakpoint::TYPE_EXCEPTION, exceptionName: \RuntimeException::class));

        // RangeException extends RuntimeException — must match
        $this->assertCount(1, $registry->forException(\RangeException::class));
        // LogicException is unrelated — must not
        $this->assertCount(0, $registry->forException(\LogicException::class));
    }

    public function testWildcardExceptionBreakpointMatchesEverything(): void
    {
        $registry = new BreakpointRegistry();
        $registry->add(new Breakpoint(id: 1, type: Breakpoint::TYPE_EXCEPTION, exceptionName: '*'));

        $this->assertCount(1, $registry->forException(\LogicException::class));
    }
}
