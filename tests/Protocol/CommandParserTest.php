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
use ZDebug\Protocol\CommandParser;
use ZDebug\Protocol\ProtocolException;

final class CommandParserTest extends TestCase
{
    private CommandParser $parser;

    protected function setUp(): void
    {
        $this->parser = new CommandParser();
    }

    public function testParsesCommandNameAndTransactionId(): void
    {
        $command = $this->parser->parse('status -i 5');
        $this->assertSame('status', $command->name);
        $this->assertSame('5', $command->transactionId);
    }

    public function testParsesOptionArguments(): void
    {
        $command = $this->parser->parse('breakpoint_set -i 3 -t line -f file:///app/x.php -n 42');
        $this->assertSame('breakpoint_set', $command->name);
        $this->assertSame('3', $command->transactionId);
        $this->assertSame('line', $command->argument('t'));
        $this->assertSame('file:///app/x.php', $command->argument('f'));
        $this->assertSame(42, $command->intArgument('n'));
    }

    public function testDecodesBase64DataPart(): void
    {
        $expression = '$counter > 10';
        $command    = $this->parser->parse('eval -i 7 -- ' . base64_encode($expression));
        $this->assertSame('eval', $command->name);
        $this->assertSame($expression, $command->data);
    }

    public function testHandlesQuotedArgumentValues(): void
    {
        $command = $this->parser->parse('property_get -i 9 -n "$obj->name with spaces"');
        $this->assertSame('$obj->name with spaces', $command->argument('n'));
    }

    public function testDataSeparatorInsideQuotesIsNotTreatedAsData(): void
    {
        $command = $this->parser->parse('feature_set -i 1 -n "a--b" -v 1');
        $this->assertSame('a--b', $command->argument('n'));
        $this->assertNull($command->data);
    }

    public function testEmptyDataPartYieldsEmptyString(): void
    {
        $command = $this->parser->parse('eval -i 2 -- ');
        $this->assertSame('', $command->data);
    }

    public function testMissingTransactionIdThrows(): void
    {
        $this->expectException(ProtocolException::class);
        $this->parser->parse('status');
    }

    public function testEmptyLineThrows(): void
    {
        $this->expectException(ProtocolException::class);
        $this->parser->parse('   ');
    }

    public function testMalformedBase64DataThrows(): void
    {
        $this->expectException(ProtocolException::class);
        // A '!' is not valid base64 and strict decoding rejects it
        $this->parser->parse('eval -i 2 -- not_base64!!');
    }

    public function testIntArgumentDefaultsWhenAbsent(): void
    {
        $command = $this->parser->parse('stack_get -i 4');
        $this->assertSame(0, $command->intArgument('d', 0));
        $this->assertFalse($command->has('d'));
    }
}
