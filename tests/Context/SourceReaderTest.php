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

namespace ZDebug\Tests\Context;

use PHPUnit\Framework\TestCase;
use ZDebug\Context\SourceReader;
use ZDebug\Instrumentation\FileFilter;

final class SourceReaderTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'zdebug-source-');
        $this->assertIsString($path);
        file_put_contents($path, "one\ntwo\nthree\nfour\n");
        $this->file = $path;
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    public function testReadsTheWholeFileWithoutARange(): void
    {
        $reader = new SourceReader(new FileFilter([]));

        $this->assertSame("one\ntwo\nthree\nfour\n", $reader->read($this->file));
    }

    public function testRangeIsOneBasedAndInclusive(): void
    {
        $reader = new SourceReader(new FileFilter([]));

        $this->assertSame("two\nthree\n", $reader->read($this->file, 2, 3));
        $this->assertSame("one\ntwo\n", $reader->read($this->file, null, 2));
        $this->assertSame("three\nfour\n", $reader->read($this->file, 3));
        $this->assertSame("two\n", $reader->read($this->file, 2, 2));
    }

    /**
     * A range past the end read a file that exists; it just selected nothing, which is
     * not the same answer as "you may not read this file"
     */
    public function testARangeSelectingNothingIsEmptyRatherThanNull(): void
    {
        $reader = new SourceReader(new FileFilter([]));

        $this->assertSame('', $reader->read($this->file, 9, 12));
        $this->assertSame('', $reader->read($this->file, 3, 2));
    }

    /**
     * The command is a file-read primitive on an open socket: it must not reach outside
     * the paths the user configured as debuggable
     */
    public function testAFileOutsideTheFilterIsRefused(): void
    {
        $reader = new SourceReader(new FileFilter([__DIR__]));

        $this->assertNull($reader->read($this->file));
        $this->assertSame(file_get_contents(__FILE__), $reader->read(__FILE__));
    }

    public function testAMissingFileIsRefused(): void
    {
        $reader = new SourceReader(new FileFilter([]));

        $this->assertNull($reader->read('/no/such/file.php'));
        $this->assertNull($reader->read(sys_get_temp_dir()), 'a directory is not a source file');
        $this->assertNull($reader->read('relative/path.php'));
    }
}
