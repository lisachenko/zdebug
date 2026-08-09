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
use ZDebug\Breakpoint\Breakpoint;
use ZDebug\Breakpoint\BreakpointType;
use ZDebug\Protocol\EngineIdentity;
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
        $xml = $this->builder->error('frobnicate', '9', ErrorCode::Unimplemented, 'unimplemented');
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

    public function testInitAndFeaturesAgreeOnTheEngineIdentity(): void
    {
        $doc = $this->loadXml($this->builder->init('file:///app/entry.php', 'phpstorm', 1, '8.4.19'));

        $this->assertSame(EngineIdentity::LANGUAGE, $doc->getAttribute('language'));
        $this->assertSame(EngineIdentity::PROTOCOL_VERSION, $doc->getAttribute('protocol_version'));
        $engine = $doc->getElementsByTagName('engine')->item(0);
        $this->assertInstanceOf(\DOMElement::class, $engine);
        $this->assertSame(EngineIdentity::NAME, $engine->textContent);
        $this->assertSame(EngineIdentity::XDEBUG_COMPAT_VERSION, $engine->getAttribute('version'));
        $this->assertStringContainsString('encoding="' . EngineIdentity::ENCODING . '"', ResponseBuilder::PROLOG);
    }

    /**
     * The <engine> element answers two questions an IDE asks it, and they must not be
     * confused: WHO is debugging (the name) and WHAT protocol generation it speaks (the
     * version). PhpStorm reads only the second to gate features like return-value
     * debugging, so the number has to name an Xdebug generation while the name stays
     * honest about which engine is actually on the other end.
     */
    public function testTheEngineAdvertisesAnXdebugCapabilityLevelWithoutClaimingToBeXdebug(): void
    {
        $doc    = $this->loadXml($this->builder->init('file:///app/entry.php', 'phpstorm', 1, '8.4.19'));
        $engine = $doc->getElementsByTagName('engine')->item(0);
        $this->assertInstanceOf(\DOMElement::class, $engine);

        $this->assertSame('zdebug', $engine->textContent, 'the engine must not impersonate Xdebug');
        $this->assertGreaterThanOrEqual(
            0,
            version_compare($engine->getAttribute('version'), '3.2.0'),
            'return-value debugging is gated on >= 3.2 by IDEs that read this attribute',
        );
    }

    public function testFeatureResponseCarriesTheValueAsElementText(): void
    {
        $doc = $this->loadXml($this->builder->feature('3', 'max_depth', true, '2'));

        $this->assertSame('feature_get', $doc->getAttribute('command'));
        $this->assertSame('max_depth', $doc->getAttribute('feature_name'));
        $this->assertSame('1', $doc->getAttribute('supported'));
        $this->assertSame('2', $doc->textContent);
    }

    public function testFeatureResponseEscapesAHostileValue(): void
    {
        $doc = $this->loadXml($this->builder->feature('3', 'idekey', false, 'a & b <c>'));

        $this->assertSame('0', $doc->getAttribute('supported'));
        $this->assertSame('a & b <c>', $doc->textContent);
    }

    public function testLineBreakpointElementIsSelfClosingAndCarriesHitBookkeeping(): void
    {
        $breakpoint = new Breakpoint(
            id: 3,
            type: BreakpointType::Line,
            file: '/app/a.php',
            line: 17,
            hitCount: 2,
            hitValue: 5,
            hitCondition: Breakpoint::HIT_MULTIPLE,
        );

        $element = $this->elementOf($this->builder->response('breakpoint_get', '1', [], ResponseBuilder::breakpoint($breakpoint)), 'breakpoint');
        $this->assertSame('3', $element->getAttribute('id'));
        $this->assertSame('line', $element->getAttribute('type'));
        $this->assertSame('enabled', $element->getAttribute('state'));
        $this->assertSame('resolved', $element->getAttribute('resolved'));
        $this->assertSame('file:///app/a.php', $element->getAttribute('filename'));
        $this->assertSame('17', $element->getAttribute('lineno'));
        $this->assertSame('2', $element->getAttribute('hit_count'));
        $this->assertSame('5', $element->getAttribute('hit_value'));
        $this->assertSame('%', $element->getAttribute('hit_condition'));
        $this->assertFalse($element->hasChildNodes());
    }

    /**
     * DBGp returns user-supplied source base64-encoded, so an expression full of XML
     * metacharacters cannot break the document
     */
    public function testConditionalBreakpointCarriesABase64ExpressionChild(): void
    {
        $breakpoint = new Breakpoint(
            id: 4,
            type: BreakpointType::Conditional,
            enabled: false,
            file: '/app/a.php',
            line: 8,
            condition: '$i < 3 && $name === "a&b"',
        );

        $element = $this->elementOf($this->builder->response('breakpoint_list', '1', [], ResponseBuilder::breakpoint($breakpoint)), 'breakpoint');
        $this->assertSame('conditional', $element->getAttribute('type'));
        $this->assertSame('disabled', $element->getAttribute('state'));
        $this->assertSame('$i < 3 && $name === "a&b"', base64_decode($element->textContent));
    }

    public function testExceptionBreakpointCarriesTheClassAndNoLocation(): void
    {
        $breakpoint = new Breakpoint(id: 5, type: BreakpointType::Exception, exceptionName: 'DomainException');

        $element = $this->elementOf($this->builder->response('breakpoint_get', '1', [], ResponseBuilder::breakpoint($breakpoint)), 'breakpoint');
        $this->assertSame('exception', $element->getAttribute('type'));
        $this->assertSame('DomainException', $element->getAttribute('exception'));
        $this->assertFalse($element->hasAttribute('filename'));
        $this->assertFalse($element->hasAttribute('lineno'));
    }

    public function testStackFrameElementDescribesOneCallStackEntry(): void
    {
        $body = ResponseBuilder::stackFrame(1, 'App\\Service->run', 'file:///app/a.php', 42);

        $element = $this->elementOf($this->builder->response('stack_get', '1', [], $body), 'stack');
        $this->assertSame('1', $element->getAttribute('level'));
        $this->assertSame('App\\Service->run', $element->getAttribute('where'));
        $this->assertSame('file', $element->getAttribute('type'));
        $this->assertSame('file:///app/a.php', $element->getAttribute('filename'));
        $this->assertSame('42', $element->getAttribute('lineno'));
    }

    public function testContextNamesRendersOneElementPerContext(): void
    {
        $body = ResponseBuilder::contextNames(['Locals' => 0, 'Superglobals' => 1]);
        $doc  = $this->loadXml($this->builder->response('context_names', '1', [], $body));

        $contexts = $doc->getElementsByTagName('context');
        $this->assertSame(2, $contexts->length);
        $first = $contexts->item(0);
        $last  = $contexts->item(1);
        $this->assertInstanceOf(\DOMElement::class, $first);
        $this->assertInstanceOf(\DOMElement::class, $last);
        $this->assertSame('Locals', $first->getAttribute('name'));
        $this->assertSame('0', $first->getAttribute('id'));
        $this->assertSame('Superglobals', $last->getAttribute('name'));
        $this->assertSame('1', $last->getAttribute('id'));
    }

    private function elementOf(string $xml, string $tagName): \DOMElement
    {
        $element = $this->loadXml($xml)->getElementsByTagName($tagName)->item(0);
        $this->assertInstanceOf(\DOMElement::class, $element);

        return $element;
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
