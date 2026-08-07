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
use ZDebug\Protocol\ErrorCode;
use ZDebug\Protocol\ResponseBuilder;

final class ResponseBuilderTest extends TestCase
{
    private ResponseBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new ResponseBuilder();
    }

    public function testInitPacketCarriesRequiredAttributes(): void
    {
        $xml = $this->builder->init('file:///app/entry.php', 'phpstorm', 4242, '8.4.19');
        $doc = $this->loadXml($xml);

        $this->assertSame('init', $doc->nodeName);
        $this->assertSame('file:///app/entry.php', $doc->getAttribute('fileuri'));
        $this->assertSame('PHP', $doc->getAttribute('language'));
        $this->assertSame('1.0', $doc->getAttribute('protocol_version'));
        $this->assertSame('phpstorm', $doc->getAttribute('idekey'));
        $this->assertSame('4242', $doc->getAttribute('appid'));
        $this->assertStringContainsString('zdebug', $xml);
    }

    public function testEmptyResponseIsSelfClosing(): void
    {
        $xml = $this->builder->response('status', '5', ['status' => 'break', 'reason' => 'ok']);
        $doc = $this->loadXml($xml);

        $this->assertSame('response', $doc->nodeName);
        $this->assertSame('status', $doc->getAttribute('command'));
        $this->assertSame('5', $doc->getAttribute('transaction_id'));
        $this->assertSame('break', $doc->getAttribute('status'));
        $this->assertFalse($doc->hasChildNodes());
    }

    public function testResponseWithBodyWrapsChildren(): void
    {
        $xml = $this->builder->response('context_names', '6', [], '<context name="Locals" id="0"/>');
        $doc = $this->loadXml($xml);

        $contexts = $doc->getElementsByTagName('context');
        $this->assertSame(1, $contexts->length);
        $context = $contexts->item(0);
        $this->assertInstanceOf(\DOMElement::class, $context);
        $this->assertSame('Locals', $context->getAttribute('name'));
    }

    public function testErrorResponseCarriesCodeAndMessage(): void
    {
        $xml = $this->builder->error('frobnicate', '9', ErrorCode::UNIMPLEMENTED, 'unimplemented');
        $doc = $this->loadXml($xml);

        $error = $doc->getElementsByTagName('error')->item(0);
        $this->assertInstanceOf(\DOMElement::class, $error);
        $this->assertSame('4', $error->getAttribute('code'));
        $this->assertSame('unimplemented', $error->textContent);
    }

    public function testAttributeValuesAreXmlEscaped(): void
    {
        $xml = $this->builder->response('source', '1', ['filename' => 'file:///a&b"c.php']);
        // Must remain well-formed despite the & and " in the value
        $doc = $this->loadXml($xml);
        $this->assertSame('file:///a&b"c.php', $doc->getAttribute('filename'));
    }

    public function testBreakMessageIsSelfClosingForALineBreak(): void
    {
        $body = ResponseBuilder::breakMessage('file:///app/a.php', 42);
        $xml  = $this->builder->response('run', '7', ['status' => 'break', 'reason' => 'ok'], $body);
        $doc  = $this->loadXml($xml);

        $message = $this->breakMessageOf($doc);
        $this->assertSame('file:///app/a.php', $message->getAttribute('filename'));
        $this->assertSame('42', $message->getAttribute('lineno'));
        $this->assertFalse($message->hasAttribute('exception'));
        $this->assertSame('', $message->textContent);
    }

    public function testBreakMessageCarriesTheExceptionClassAndMessage(): void
    {
        $body = ResponseBuilder::breakMessage('file:///app/a.php', 12, \DomainException::class, 'seed out of range');
        $xml  = $this->builder->response('run', '8', ['status' => 'break', 'reason' => 'exception'], $body);
        $doc  = $this->loadXml($xml);

        $this->assertSame('exception', $doc->getAttribute('reason'));
        $message = $this->breakMessageOf($doc);
        $this->assertSame('DomainException', $message->getAttribute('exception'));
        $this->assertSame('12', $message->getAttribute('lineno'));
        $this->assertSame('seed out of range', $message->textContent);
    }

    public function testBreakMessageWithAnEmptyExceptionMessageStaysSelfClosing(): void
    {
        $body = ResponseBuilder::breakMessage('file:///app/a.php', 12, \DomainException::class);
        $xml  = $this->builder->response('run', '9', ['status' => 'break'], $body);

        $message = $this->breakMessageOf($this->loadXml($xml));
        $this->assertSame('DomainException', $message->getAttribute('exception'));
        $this->assertSame('', $message->textContent);
    }

    public function testBreakMessageKeepsTheDocumentWellFormedForHostileText(): void
    {
        $body = ResponseBuilder::breakMessage('file:///a&b.php', 3, 'Vendor\\Bad<Class>', "]]> & <bang>\0");
        $xml  = $this->builder->response('run', '10', ['status' => 'break'], $body);

        // Well-formedness is what matters: loadXml() asserts it, NUL bytes and all
        $message = $this->breakMessageOf($this->loadXml($xml));
        $this->assertSame('Vendor\\Bad<Class>', $message->getAttribute('exception'));
        $this->assertSame('file:///a&b.php', $message->getAttribute('filename'));
        $this->assertSame(']]> & <bang>', $message->textContent);
    }

    public function testPrologPrecedesEveryPacket(): void
    {
        $xml = $this->builder->response('status', '1');
        $this->assertStringStartsWith(ResponseBuilder::PROLOG, $xml);
    }

    private function breakMessageOf(\DOMElement $response): \DOMElement
    {
        $message = $response->getElementsByTagNameNS(ResponseBuilder::NS_XDEBUG, 'message')->item(0);
        $this->assertInstanceOf(\DOMElement::class, $message);

        return $message;
    }

    private function loadXml(string $xml): \DOMElement
    {
        $document = new \DOMDocument();
        $this->assertTrue($document->loadXML($xml), 'Response is not well-formed XML');
        $root = $document->documentElement;
        $this->assertInstanceOf(\DOMElement::class, $root);

        return $root;
    }
}
