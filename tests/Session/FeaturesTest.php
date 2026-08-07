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

namespace ZDebug\Tests\Session;

use PHPUnit\Framework\TestCase;
use ZDebug\Session\Features;

final class FeaturesTest extends TestCase
{
    public function testExposesXdebugCompatibleDefaults(): void
    {
        $features = new Features('8.4.19');
        $this->assertSame('PHP', $features->get('language_name'));
        $this->assertSame('8.4.19', $features->get('language_version'));
        $this->assertSame('1.0', $features->get('protocol_version'));
        $this->assertSame('base64', $features->get('data_encoding'));
        $this->assertSame('0', $features->get('supports_async'));
        $this->assertSame('line conditional exception', $features->get('breakpoint_types'));
    }

    public function testUnknownFeatureIsUnsupported(): void
    {
        $features = new Features('8.4.19');
        $this->assertFalse($features->supports('warp_drive'));
        $this->assertNull($features->get('warp_drive'));
    }

    public function testWritableFeatureCanBeSet(): void
    {
        $features = new Features('8.4.19');
        $this->assertTrue($features->set('max_depth', '5'));
        $this->assertSame(5, $features->getInt('max_depth', 1));
    }

    public function testReadOnlyFeatureRejectsSet(): void
    {
        $features = new Features('8.4.19');
        $this->assertFalse($features->set('protocol_version', '2.0'));
        $this->assertSame('1.0', $features->get('protocol_version'));
    }

    public function testGetIntFallsBackWhenNonNumeric(): void
    {
        $features = new Features('8.4.19');
        $this->assertSame(1, $features->getInt('language_name', 1));
    }
}
