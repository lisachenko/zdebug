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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ZDebug\Breakpoint\BreakpointRegistry;
use ZDebug\Breakpoint\BreakpointType;
use ZDebug\Context\ContextProvider;
use ZDebug\Context\StackFrame;
use ZDebug\Protocol\Command;
use ZDebug\Protocol\DbgpCommand;
use ZDebug\Protocol\ResponseBuilder;
use ZDebug\Session\CommandDispatcher;
use ZDebug\Session\CommandDispatcherFactory;
use ZDebug\Session\DispatchResult;
use ZDebug\Session\Features;
use ZDebug\Session\SessionStatus;
use ZDebug\Stepping\ResumeMode;
use ZEngine\System\ExecutionData;

/**
 * Command handling against a fake suspended debuggee
 *
 * The dispatcher only ever sees a SuspendedState, so the whole DBGp command surface can
 * be driven here without an engine, a socket or an IDE: what is asserted is the wire
 * answer an IDE would read.
 */
final class CommandDispatcherTest extends TestCase
{
    private FakeSuspendedState $state;

    private BreakpointRegistry $breakpoints;

    private Features $features;

    private CommandDispatcher $dispatcher;

    protected function setUp(): void
    {
        $this->state       = new FakeSuspendedState(SessionStatus::Break);
        $this->breakpoints = new BreakpointRegistry();
        $this->features    = new Features('8.4.19');
        $this->dispatcher  = (new CommandDispatcherFactory(
            $this->features,
            $this->breakpoints,
            new ContextProvider(),
            new ResponseBuilder(),
        ))->create($this->state);
    }

    public function testStatusReportsTheSessionStatus(): void
    {
        $response = $this->respondTo('status');

        $this->assertSame('break', $response->getAttribute('status'));
        $this->assertSame('ok', $response->getAttribute('reason'));
    }

    public function testUnknownCommandIsAnsweredWithErrorFour(): void
    {
        $this->assertSame(4, $this->errorCodeOf($this->respondTo('frobnicate')));
    }

    /**
     * A handler that throws must come back as DBGp 998, never as an escaping exception:
     * the loop above the dispatcher runs inside the FFI statement callback, where a
     * throwable is a fatal engine abort rather than something anyone could catch.
     */
    public function testAThrowingHandlerIsReportedAsAnInternalException(): void
    {
        $this->state->failWith(new \RuntimeException('engine gone'));

        $response = $this->respondTo('stack_get');
        $this->assertSame(998, $this->errorCodeOf($response));
        $this->assertStringContainsString('engine gone', $response->textContent);
    }

    public function testBreakpointSetRegistersALineBreakpoint(): void
    {
        $response = $this->respondTo('breakpoint_set', ['t' => 'line', 'f' => 'file:///app/a.php', 'n' => '17']);

        $this->assertSame('enabled', $response->getAttribute('state'));
        $this->assertSame('resolved', $response->getAttribute('resolved'));

        $breakpoint = $this->breakpoints->get((int) $response->getAttribute('id'));
        $this->assertNotNull($breakpoint);
        $this->assertSame(BreakpointType::Line, $breakpoint->type);
        $this->assertSame('/app/a.php', $breakpoint->file);
        $this->assertSame(17, $breakpoint->line);
    }

    public function testBreakpointSetTurnsADataPartIntoAConditionalBreakpoint(): void
    {
        $response = $this->respondTo(
            'breakpoint_set',
            ['t' => 'line', 'f' => 'file:///app/a.php', 'n' => '17'],
            '$i === 3',
        );

        $breakpoint = $this->breakpoints->get((int) $response->getAttribute('id'));
        $this->assertNotNull($breakpoint);
        $this->assertSame(BreakpointType::Conditional, $breakpoint->type);
        $this->assertSame('$i === 3', $breakpoint->condition);
    }

    public function testBreakpointSetRegistersAnExceptionBreakpoint(): void
    {
        $response   = $this->respondTo('breakpoint_set', ['t' => 'exception', 'x' => 'DomainException', 's' => 'disabled']);
        $breakpoint = $this->breakpoints->get((int) $response->getAttribute('id'));

        $this->assertSame('disabled', $response->getAttribute('state'));
        $this->assertNotNull($breakpoint);
        $this->assertSame(BreakpointType::Exception, $breakpoint->type);
        $this->assertSame('DomainException', $breakpoint->exceptionName);
        $this->assertFalse($breakpoint->enabled);
    }

    public function testBreakpointSetRejectsAnUnsupportedType(): void
    {
        $this->assertSame(201, $this->errorCodeOf($this->respondTo('breakpoint_set', ['t' => 'watch'])));
        $this->assertSame([], $this->breakpoints->all());
    }

    public function testLineBreakpointWithoutFileOrLineIsInvalid(): void
    {
        $this->assertSame(202, $this->errorCodeOf($this->respondTo('breakpoint_set', ['t' => 'line', 'f' => 'file:///app/a.php'])));
    }

    public function testBreakpointSetRejectsAnUnknownHitCondition(): void
    {
        $arguments = ['t' => 'line', 'f' => 'file:///app/a.php', 'n' => '3', 'h' => '2', 'o' => '<='];

        $this->assertSame(202, $this->errorCodeOf($this->respondTo('breakpoint_set', $arguments)));
    }

    public function testBreakpointRemoveDropsTheBreakpoint(): void
    {
        $id = (int) $this->respondTo('breakpoint_set', ['t' => 'line', 'f' => 'file:///app/a.php', 'n' => '5'])
            ->getAttribute('id');

        $this->assertFalse($this->respondTo('breakpoint_remove', ['d' => (string) $id])->hasChildNodes());
        $this->assertNull($this->breakpoints->get($id));
    }

    public function testBreakpointRemoveOfAnUnknownIdIsError205(): void
    {
        $this->assertSame(205, $this->errorCodeOf($this->respondTo('breakpoint_remove', ['d' => '404'])));
        $this->assertSame(205, $this->errorCodeOf($this->respondTo('breakpoint_remove')));
    }

    public function testBreakpointGetRendersTheRequestedBreakpointOnly(): void
    {
        $this->respondTo('breakpoint_set', ['t' => 'line', 'f' => 'file:///app/a.php', 'n' => '5']);
        $second = $this->respondTo('breakpoint_set', ['t' => 'line', 'f' => 'file:///app/b.php', 'n' => '9', 'h' => '2', 'o' => '%']);

        $elements = $this->childrenOf($this->respondTo('breakpoint_get', ['d' => $second->getAttribute('id')]), 'breakpoint');
        $this->assertCount(1, $elements);
        $this->assertSame('file:///app/b.php', $elements[0]->getAttribute('filename'));
        $this->assertSame('9', $elements[0]->getAttribute('lineno'));
        $this->assertSame('2', $elements[0]->getAttribute('hit_value'));
        $this->assertSame('%', $elements[0]->getAttribute('hit_condition'));
        $this->assertSame('0', $elements[0]->getAttribute('hit_count'));
    }

    public function testBreakpointGetOfAnUnknownIdIsError205(): void
    {
        $this->assertSame(205, $this->errorCodeOf($this->respondTo('breakpoint_get', ['d' => '7'])));
    }

    public function testBreakpointListRendersEveryRegisteredBreakpoint(): void
    {
        $this->respondTo('breakpoint_set', ['t' => 'line', 'f' => 'file:///app/a.php', 'n' => '5']);
        $this->respondTo('breakpoint_set', ['t' => 'exception', 'x' => 'LengthException']);

        $elements = $this->childrenOf($this->respondTo('breakpoint_list'), 'breakpoint');
        $this->assertCount(2, $elements);
        $this->assertSame('line', $elements[0]->getAttribute('type'));
        $this->assertSame('exception', $elements[1]->getAttribute('type'));
        $this->assertSame('LengthException', $elements[1]->getAttribute('exception'));
    }

    public function testBreakpointListIsEmptyWithoutBreakpoints(): void
    {
        $this->assertSame([], $this->childrenOf($this->respondTo('breakpoint_list'), 'breakpoint'));
    }

    public function testStackGetRendersTheSuspendedStackInnermostFirst(): void
    {
        $this->state->suspendOn([
            $this->frame(0, '/app/a.php', 12, 'compute'),
            $this->frame(1, '/app/entry.php', 4, '{main}'),
        ]);

        $frames = $this->childrenOf($this->respondTo('stack_get'), 'stack');
        $this->assertCount(2, $frames);
        $this->assertSame('compute', $frames[0]->getAttribute('where'));
        $this->assertSame('0', $frames[0]->getAttribute('level'));
        $this->assertSame('file', $frames[0]->getAttribute('type'));
        $this->assertSame('file:///app/a.php', $frames[0]->getAttribute('filename'));
        $this->assertSame('12', $frames[0]->getAttribute('lineno'));
        $this->assertSame('{main}', $frames[1]->getAttribute('where'));
    }

    public function testStackGetWithADepthReturnsThatFrameOnly(): void
    {
        $this->state->suspendOn([
            $this->frame(0, '/app/a.php', 12, 'compute'),
            $this->frame(1, '/app/entry.php', 4, '{main}'),
        ]);

        $frames = $this->childrenOf($this->respondTo('stack_get', ['d' => '1']), 'stack');
        $this->assertCount(1, $frames);
        $this->assertSame('{main}', $frames[0]->getAttribute('where'));
    }

    public function testStackDepthCountsTheSuspendedFrames(): void
    {
        $this->assertSame('0', $this->respondTo('stack_depth')->getAttribute('depth'), 'nothing suspended');

        $this->state->suspendOn([
            $this->frame(0, '/app/a.php', 12, 'compute'),
            $this->frame(1, '/app/entry.php', 4, '{main}'),
        ]);
        $this->assertSame('2', $this->respondTo('stack_depth')->getAttribute('depth'));
    }

    /**
     * The typemap must describe the very types PropertySerializer emits, or an IDE that
     * trusts it renders values it was told the wrong shape of
     */
    public function testTypemapGetDescribesTheTypesPropertiesCarry(): void
    {
        $response = $this->respondTo('typemap_get');

        $types = [];
        foreach ($this->childrenOf($response, 'map') as $map) {
            $types[$map->getAttribute('name')] = $map->getAttribute('type');
        }
        $this->assertSame([
            'bool'     => 'bool',
            'int'      => 'int',
            'float'    => 'float',
            'string'   => 'string',
            'array'    => 'array',
            'object'   => 'object',
            'resource' => 'resource',
            'null'     => 'null',
        ], $types);

        // The xsi:type attributes are only legal because the response declares the schema
        // namespaces; without them the packet is not a well-formed document at all
        $this->assertSame('http://www.w3.org/2001/XMLSchema', $response->getAttribute('xmlns:xsd'));
        $this->assertSame('xsd:long', $this->childrenOf($response, 'map')[1]->getAttribute('xsi:type'));
    }

    public function testSourceReturnsTheFileBase64Encoded(): void
    {
        $response = $this->respondTo('source', ['f' => 'file://' . __FILE__, 'b' => '1', 'e' => '1']);

        $this->assertSame('1', $response->getAttribute('success'));
        $this->assertSame('base64', $response->getAttribute('encoding'));
        $this->assertSame("<?php\n", base64_decode($response->textContent));
    }

    public function testSourceOfAnUnreadableFileIsError100(): void
    {
        $this->assertSame(100, $this->errorCodeOf($this->respondTo('source', ['f' => 'file:///no/such/file.php'])));
        // No -f and no suspended frame: there is no file the command could mean
        $this->assertSame(100, $this->errorCodeOf($this->respondTo('source')));
    }

    public function testBreakpointUpdateChangesStateAndHitConditionInPlace(): void
    {
        $id = (int) $this->respondTo('breakpoint_set', ['t' => 'line', 'f' => 'file:///app/a.php', 'n' => '5'])
            ->getAttribute('id');

        $updated = $this->respondTo('breakpoint_update', ['d' => (string) $id, 's' => 'disabled', 'h' => '3', 'o' => '%']);

        $breakpoint = $this->breakpoints->get($id);
        $this->assertNotNull($breakpoint, 'the id survives an update');
        $this->assertFalse($breakpoint->enabled);
        $this->assertSame(3, $breakpoint->hitValue);
        $this->assertSame('%', $breakpoint->hitCondition);
        $this->assertSame('disabled', $this->childrenOf($updated, 'breakpoint')[0]->getAttribute('state'));
    }

    /**
     * The line is an index key of the registry, not just a field: an update that only
     * assigned it would leave the breakpoint firing on its old line forever
     */
    public function testBreakpointUpdateMovesTheBreakpointInTheLineIndex(): void
    {
        $id = (int) $this->respondTo('breakpoint_set', ['t' => 'line', 'f' => 'file:///app/a.php', 'n' => '5'])
            ->getAttribute('id');

        $this->respondTo('breakpoint_update', ['d' => (string) $id, 'n' => '9']);

        $this->assertSame([], $this->breakpoints->atLine('/app/a.php', 5));
        $this->assertCount(1, $this->breakpoints->atLine('/app/a.php', 9));
    }

    public function testBreakpointUpdateRejectsUnknownIdsAndValues(): void
    {
        $id = (int) $this->respondTo('breakpoint_set', ['t' => 'line', 'f' => 'file:///app/a.php', 'n' => '5'])
            ->getAttribute('id');

        $this->assertSame(205, $this->errorCodeOf($this->respondTo('breakpoint_update', ['d' => '404'])));
        $this->assertSame(204, $this->errorCodeOf($this->respondTo('breakpoint_update', ['d' => (string) $id, 's' => 'sideways'])));
        $this->assertSame(202, $this->errorCodeOf($this->respondTo('breakpoint_update', ['d' => (string) $id, 'o' => '<='])));

        // A rejected update changes nothing
        $breakpoint = $this->breakpoints->get($id);
        $this->assertNotNull($breakpoint);
        $this->assertTrue($breakpoint->enabled);
        $this->assertSame('>=', $breakpoint->hitCondition);
    }

    public function testCallBreakpointRequiresAFunctionName(): void
    {
        $response = $this->respondTo('breakpoint_set', ['t' => 'call', 'm' => 'handle']);
        $this->assertSame('enabled', $response->getAttribute('state'));

        $breakpoint = $this->breakpoints->get((int) $response->getAttribute('id'));
        $this->assertNotNull($breakpoint);
        $this->assertSame(BreakpointType::Call, $breakpoint->type);
        $this->assertSame('handle', $breakpoint->functionName);

        $this->assertSame(202, $this->errorCodeOf($this->respondTo('breakpoint_set', ['t' => 'return'])));
    }

    public function testContextNamesAdvertisesLocalsAndSuperglobals(): void
    {
        $contexts = $this->childrenOf($this->respondTo('context_names'), 'context');

        $this->assertCount(2, $contexts);
        $this->assertSame('Locals', $contexts[0]->getAttribute('name'));
        $this->assertSame((string) ContextProvider::CONTEXT_LOCALS, $contexts[0]->getAttribute('id'));
        $this->assertSame('Superglobals', $contexts[1]->getAttribute('name'));
        $this->assertSame((string) ContextProvider::CONTEXT_SUPERGLOBALS, $contexts[1]->getAttribute('id'));
    }

    public function testContextGetEchoesTheContextItAnswersFor(): void
    {
        $this->state->suspendOn([$this->frame(0, '/app/a.php', 12, 'compute')]);

        $response = $this->respondTo('context_get', ['c' => '0', 'd' => '0']);
        $this->assertSame('0', $response->getAttribute('context'));
    }

    public function testContextGetRejectsADepthWithNoFrame(): void
    {
        $this->assertSame(301, $this->errorCodeOf($this->respondTo('context_get', ['c' => '0', 'd' => '4'])));
    }

    /**
     * property_get is what an IDE sends to open a node the one-level context_get showed;
     * a name it cannot resolve is the client's error (300), and a missing -n is a
     * malformed command (3) - neither may ever come back as "not implemented"
     */
    public function testPropertyGetWithoutANameIsAClientError(): void
    {
        $this->assertSame(3, $this->errorCodeOf($this->respondTo('property_get')));
        $this->assertSame(3, $this->errorCodeOf($this->respondTo('property_get', ['n' => '  '])));
        $this->assertSame(3, $this->errorCodeOf($this->respondTo('property_value')));
    }

    public function testPropertyGetRejectsADepthWithNoFrame(): void
    {
        $this->assertSame(301, $this->errorCodeOf($this->respondTo('property_get', ['n' => '$a', 'd' => '4'])));
    }

    public function testPropertyGetOfAnUnresolvableNameIsError300(): void
    {
        $this->state->suspendOn([$this->frame(0, '/app/a.php', 12, 'compute')]);

        $this->assertSame(300, $this->errorCodeOf($this->respondTo('property_get', ['n' => '$missing'])));
    }

    public function testFeatureGetReadsAKnownFeature(): void
    {
        $response = $this->respondTo('feature_get', ['n' => 'max_depth']);

        $this->assertSame('max_depth', $response->getAttribute('feature_name'));
        $this->assertSame('1', $response->getAttribute('supported'));
        $this->assertSame('1', $response->textContent);
    }

    public function testFeatureGetReportsADispatchableCommandAsSupported(): void
    {
        $response = $this->respondTo('feature_get', ['n' => 'stack_get']);

        $this->assertSame('1', $response->getAttribute('supported'));
        $this->assertSame('1', $response->textContent);
    }

    /**
     * Advertising a command the dispatch table has no arm for is a protocol lie: an IDE
     * that believes `source` is supported sends it and gets error 4 back. DbgpCommand is
     * now the single table both answers come from, so the two cannot disagree.
     */
    #[DataProvider('unimplementedCommandNames')]
    public function testFeatureGetDoesNotAdvertiseCommandsItCannotDispatch(string $name): void
    {
        $this->assertSame('0', $this->respondTo('feature_get', ['n' => $name])->getAttribute('supported'));
        $this->assertSame(4, $this->errorCodeOf($this->respondTo($name)));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unimplementedCommandNames(): iterable
    {
        yield 'break' => ['break'];
        yield 'interact' => ['interact'];
        yield 'spawnpoint_set' => ['spawnpoint_set'];
    }

    public function testFeatureGetOfAnUnknownNameIsUnsupported(): void
    {
        $response = $this->respondTo('feature_get', ['n' => 'warp_drive']);

        $this->assertSame('0', $response->getAttribute('supported'));
        $this->assertSame('0', $response->textContent);
    }

    public function testFeatureSetStoresAWritableFeature(): void
    {
        $response = $this->respondTo('feature_set', ['n' => 'max_depth', 'v' => '3']);

        $this->assertSame('1', $response->getAttribute('success'));
        $this->assertSame('max_depth', $response->getAttribute('feature'));
        $this->assertSame(3, $this->features->getInt('max_depth'));
    }

    public function testFeatureSetRefusesAReadOnlyFeature(): void
    {
        $response = $this->respondTo('feature_set', ['n' => 'protocol_version', 'v' => '2.0']);

        $this->assertSame('0', $response->getAttribute('success'));
        $this->assertSame('1.0', $this->features->get('protocol_version'));
    }

    public function testEvalWithoutAnExpressionIsAClientError(): void
    {
        $this->assertSame(207, $this->errorCodeOf($this->respondTo('eval')));
        $this->assertSame(207, $this->errorCodeOf($this->respondTo('eval', [], '   ')));
    }

    /**
     * A failing expression is the user's problem, not an internal one: 206, not 998
     */
    public function testEvalOfAFailingExpressionIsError206(): void
    {
        $this->assertSame(206, $this->errorCodeOf($this->respondTo('eval', [], 'intdiv(1, 0)')));
        $this->assertSame(206, $this->errorCodeOf($this->respondTo('eval', [], '1 +')));
    }

    public function testEvalOfAConstantExpressionAnswersWithoutASuspendedStack(): void
    {
        $response = $this->respondTo('eval', [], '6 * 7');

        $this->assertSame('1', $response->getAttribute('success'));
        $property = $this->childrenOf($response, 'property')[0];
        $this->assertSame('int', $property->getAttribute('type'));
        $this->assertSame('42', base64_decode($property->textContent));
    }

    public function testEvalRejectsADepthOutsideASuspendedStack(): void
    {
        $this->state->suspendOn([$this->frame(0, '/app/a.php', 12, 'compute')]);

        $this->assertSame(301, $this->errorCodeOf($this->respondTo('eval', ['d' => '3'], '1 + 1')));
    }

    #[DataProvider('continuationCommands')]
    public function testContinuationCommandsAreAnsweredAtTheNextBreak(string $name, ResumeMode $expected): void
    {
        $result = $this->dispatch($name);

        $this->assertNull($result->response, 'a continuation is answered when the debuggee suspends again');
        $this->assertSame($expected, $result->resume);
        $this->assertFalse($result->terminate);
    }

    /**
     * @return iterable<string, array{string, ResumeMode}>
     */
    public static function continuationCommands(): iterable
    {
        yield 'run' => ['run', ResumeMode::Run];
        yield 'step_into' => ['step_into', ResumeMode::StepInto];
        yield 'step_over' => ['step_over', ResumeMode::StepOver];
        yield 'step_out' => ['step_out', ResumeMode::StepOut];
    }

    /**
     * stop answers "stopped" and ends the session; it never resumes the debuggee, so the
     * result carries no resume mode for the command loop to act on
     */
    public function testStopTerminatesTheSessionWithoutResuming(): void
    {
        $result = $this->dispatch('stop');

        $this->assertTrue($result->terminate);
        $this->assertNull($result->resume);
        $this->assertSame('stopped', $this->parse((string) $result->response)->getAttribute('status'));
    }

    public function testDetachTerminatesTheSessionAndLetsTheScriptFinish(): void
    {
        $result = $this->dispatch('detach');

        $this->assertTrue($result->terminate);
        $this->assertNull($result->resume);
        $this->assertSame('stopping', $this->parse((string) $result->response)->getAttribute('status'));
    }

    #[DataProvider('streamCommands')]
    public function testStreamRedirectionIsAcknowledgedAsUnsupported(string $name): void
    {
        $this->assertSame('0', $this->respondTo($name)->getAttribute('success'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function streamCommands(): iterable
    {
        yield 'stdout' => ['stdout'];
        yield 'stderr' => ['stderr'];
    }

    public function testEveryDispatchableCommandAnswersSomething(): void
    {
        foreach (DbgpCommand::cases() as $case) {
            $result = $this->dispatch($case->value);
            $this->assertTrue(
                $result->response !== null || $result->resume !== null,
                "{$case->value} is advertised as supported but answers nothing",
            );
            if ($result->response !== null) {
                $this->assertNotSame(4, $this->errorCodeOf($this->parse($result->response)), "{$case->value} answers error 4");
            }
        }
    }

    /**
     * @param array<string, string> $arguments
     */
    private function respondTo(string $name, array $arguments = [], ?string $data = null): \DOMElement
    {
        $result = $this->dispatch($name, $arguments, $data);
        $this->assertNotNull($result->response, "{$name} answered no response");

        return $this->parse($result->response);
    }

    /**
     * @param array<string, string> $arguments
     */
    private function dispatch(string $name, array $arguments = [], ?string $data = null): DispatchResult
    {
        return $this->dispatcher->dispatch(new Command($name, '42', $arguments, $data));
    }

    private function frame(int $level, string $file, int $line, string $where): StackFrame
    {
        return new StackFrame($level, $file, $line, $where, $this->createStub(ExecutionData::class));
    }

    /**
     * @return list<\DOMElement>
     */
    private function childrenOf(\DOMElement $response, string $tagName): array
    {
        $elements = [];
        foreach ($response->getElementsByTagNameNS(ResponseBuilder::NS, $tagName) as $element) {
            $elements[] = $element;
        }

        return $elements;
    }

    private function errorCodeOf(\DOMElement $response): ?int
    {
        $error = $response->getElementsByTagNameNS(ResponseBuilder::NS, 'error')->item(0);

        return $error instanceof \DOMElement ? (int) $error->getAttribute('code') : null;
    }

    private function parse(string $xml): \DOMElement
    {
        $document = new \DOMDocument();
        $this->assertTrue($document->loadXML($xml), 'Response is not well-formed XML');
        $root = $document->documentElement;
        $this->assertInstanceOf(\DOMElement::class, $root);
        $this->assertSame('42', $root->getAttribute('transaction_id'));

        return $root;
    }
}
