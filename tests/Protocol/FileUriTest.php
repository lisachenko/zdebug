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

namespace ZDebug\Tests\Protocol;

use PHPUnit\Framework\TestCase;
use ZDebug\Protocol\FileUri;

final class FileUriTest extends TestCase
{
    public function testFromPathEmitsTripleSlashForm(): void
    {
        $this->assertSame('file:///app/src/Service.php', FileUri::fromPath('/app/src/Service.php'));
    }

    public function testRoundTripPreservesPath(): void
    {
        $path = '/home/user/project/index.php';
        $this->assertSame($path, FileUri::toPath(FileUri::fromPath($path)));
    }

    public function testToPathAcceptsTripleSlashForm(): void
    {
        $this->assertSame('/app/x.php', FileUri::toPath('file:///app/x.php'));
    }

    public function testToPathAcceptsHostAuthorityForm(): void
    {
        // file://host/path — the authority is discarded, the absolute path kept
        $this->assertSame('/app/x.php', FileUri::toPath('file://localhost/app/x.php'));
    }

    public function testEncodesAndDecodesSpaces(): void
    {
        $path = '/app/a folder/My File.php';
        $uri  = FileUri::fromPath($path);
        $this->assertStringContainsString('%20', $uri);
        $this->assertSame($path, FileUri::toPath($uri));
    }

    public function testNonFileUriReturnedUnchanged(): void
    {
        $this->assertSame('http://x', FileUri::toPath('http://x'));
    }
}
