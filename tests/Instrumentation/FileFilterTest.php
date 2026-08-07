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
use ZDebug\Instrumentation\FileFilter;

final class FileFilterTest extends TestCase
{
    public function testEmptyPrefixListAcceptsEverything(): void
    {
        $filter = new FileFilter([]);
        $this->assertTrue($filter->accepts('/anything.php'));
        $this->assertTrue($filter->accepts('/var/www/app.php'));
    }

    public function testAcceptsPathsUnderConfiguredPrefix(): void
    {
        $filter = new FileFilter(['/var/www/app']);
        $this->assertTrue($filter->accepts('/var/www/app/src/Service.php'));
        $this->assertFalse($filter->accepts('/var/www/other/x.php'));
    }

    public function testRejectsSyntheticFilenames(): void
    {
        $filter = new FileFilter(['/var/www/app']);
        // eval()'d code and stream wrappers are never real breakpoint targets
        $this->assertFalse($filter->accepts("/var/www/app(12) : eval()'d code"));
        $this->assertFalse($filter->accepts('php://input'));
        $this->assertFalse($filter->accepts(''));
    }

    public function testMultiplePrefixes(): void
    {
        $filter = new FileFilter(['/app/a', '/app/b']);
        $this->assertTrue($filter->accepts('/app/a/x.php'));
        $this->assertTrue($filter->accepts('/app/b/y.php'));
        $this->assertFalse($filter->accepts('/app/c/z.php'));
    }
}
