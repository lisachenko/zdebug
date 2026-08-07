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

namespace ZDebug\Tests\Instrumentation;

use PHPUnit\Framework\TestCase;
use ZDebug\Instrumentation\HookLatch;

final class HookLatchTest extends TestCase
{
    protected function setUp(): void
    {
        HookLatch::leave();
    }

    protected function tearDown(): void
    {
        HookLatch::leave();
    }

    public function testFirstEntryEngagesTheLatch(): void
    {
        $this->assertFalse(HookLatch::isEngaged());
        $this->assertTrue(HookLatch::tryEnter());
        $this->assertTrue(HookLatch::isEngaged());
    }

    public function testReentrantEntryIsRefused(): void
    {
        $this->assertTrue(HookLatch::tryEnter());
        // A second hook (or the same one re-entered through debugger code) must bail out
        $this->assertFalse(HookLatch::tryEnter());
        $this->assertFalse(HookLatch::tryEnter());
    }

    public function testLeaveReopensTheLatchForTheNextCallback(): void
    {
        HookLatch::tryEnter();
        HookLatch::leave();

        $this->assertFalse(HookLatch::isEngaged());
        $this->assertTrue(HookLatch::tryEnter());
    }

    public function testLatchIsSharedAcrossEveryHook(): void
    {
        // The statement hook enters, then the THROW hook fires while it is suspended:
        // exactly the recursion a per-hook latch would let through
        $this->assertTrue(HookLatch::tryEnter());
        $this->assertFalse(HookLatch::tryEnter());
        HookLatch::leave();
        $this->assertFalse(HookLatch::isEngaged());
    }
}
