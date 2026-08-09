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

namespace ZDebug\Protocol;

use ZDebug\Breakpoint\Breakpoint;

/**
 * Builds DBGp XML payloads (the wire framing is applied by DbgpConnection)
 *
 * Every packet is a standalone XML document opening with the iso-8859-1 prolog Xdebug
 * uses and the debugger_protocol_v1 namespace (plus the xdebug extension namespace,
 * which some IDEs key cursor movement off). Payload text values are base64-encoded by
 * the caller (PropertySerializer); this class only escapes XML attribute/element text.
 *
 * Two shapes live here: instance methods build a whole packet (init/response/error),
 * static ones render a single element that a caller passes back as a response body.
 * No layer above this one concatenates protocol XML of its own.
 */
final class ResponseBuilder
{
    public const string PROLOG    = '<?xml version="1.0" encoding="' . EngineIdentity::ENCODING . '"?>';
    public const string NS        = 'urn:debugger_protocol_v1';
    public const string NS_XDEBUG = 'https://xdebug.org/dbgp/xdebug';

    /**
     * The XML Schema namespaces a typemap_get response declares
     *
     * Its <map> elements carry `xsi:type` attributes, so without these two declarations
     * on the <response> the packet is not a well-formed namespaced document at all.
     *
     * @var array<string, string>
     */
    public const array SCHEMA_NAMESPACES = [
        'xmlns:xsi' => 'http://www.w3.org/2001/XMLSchema-instance',
        'xmlns:xsd' => 'http://www.w3.org/2001/XMLSchema',
    ];

    /**
     * PHP type => [DBGp common type, XML Schema type or null], as typemap_get reports it
     *
     * The middle column is what <property type="..."> carries; array, object, resource and
     * null have no XML Schema counterpart and are reported without an xsi:type, as the
     * protocol prescribes for language types that map onto no scalar schema type.
     *
     * @var array<string, array{string, string|null}>
     */
    private const array TYPE_MAP = [
        'bool'     => ['bool', 'xsd:boolean'],
        'int'      => ['int', 'xsd:long'],
        'float'    => ['float', 'xsd:double'],
        'string'   => ['string', 'xsd:string'],
        'array'    => ['array', null],
        'object'   => ['object', null],
        'resource' => ['resource', null],
        'null'     => ['null', null],
    ];

    /**
     * Builds the <init> packet sent immediately after connecting to the IDE
     */
    public function init(string $fileUri, string $ideKey, int $appId, string $languageVersion): string
    {
        $attributes = self::attributes([
            'xmlns'                   => self::NS,
            'xmlns:xdebug'            => self::NS_XDEBUG,
            'fileuri'                 => $fileUri,
            'language'                => EngineIdentity::LANGUAGE,
            'xdebug:language_version' => $languageVersion,
            'protocol_version'        => EngineIdentity::PROTOCOL_VERSION,
            'appid'                   => (string) $appId,
            'idekey'                  => $ideKey,
        ]);
        $engine = '<engine version="' . self::escape(EngineIdentity::VERSION) . '">'
            . '<![CDATA[' . EngineIdentity::NAME . ']]></engine>';

        return self::PROLOG . '<init ' . $attributes . '>' . $engine . '</init>';
    }

    /**
     * Opens a <response> element with the standard attributes and namespaces
     *
     * @param array<string, string> $extraAttributes
     */
    public function response(string $command, string $transactionId, array $extraAttributes = [], string $body = ''): string
    {
        $attributes = self::attributes(array_merge([
            'xmlns'          => self::NS,
            'xmlns:xdebug'   => self::NS_XDEBUG,
            'command'        => $command,
            'transaction_id' => $transactionId,
        ], $extraAttributes));

        if ($body === '') {
            return self::PROLOG . '<response ' . $attributes . '/>';
        }

        return self::PROLOG . '<response ' . $attributes . '>' . $body . '</response>';
    }

    /**
     * Builds an error <response> carrying a DBGp error code and message
     */
    public function error(string $command, string $transactionId, ErrorCode $code, string $message): string
    {
        $body = '<error code="' . $code->value . '"><message>' . self::escape($message) . '</message></error>';

        return $this->response($command, $transactionId, [], $body);
    }

    /**
     * Builds the feature_get response, whose value is element text rather than an attribute
     *
     * `supported` answers two different questions with one attribute: for a feature name it
     * means "this engine knows the setting", for a command name it means "this engine
     * implements the command" - IDEs probe both through feature_get.
     */
    public function feature(string $transactionId, string $name, bool $supported, string $value): string
    {
        return $this->response(DbgpCommand::FeatureGet->value, $transactionId, [
            'feature_name' => $name,
            'supported'    => $supported ? '1' : '0',
        ], self::escape($value));
    }

    /**
     * Builds the <xdebug:message> element that tells the IDE where the debuggee stopped
     *
     * IDEs move their cursor off filename/lineno; for an exception breakpoint Xdebug also
     * puts the throwable's class in an `exception` attribute and its message in the element
     * text, which is how the "first chance exception" popup gets its wording. An empty
     * message stays a self-closing element so line breaks keep their historic shape.
     */
    public static function breakMessage(string $fileUri, int $line, ?string $exceptionClass = null, string $exceptionMessage = ''): string
    {
        $attributes = ['filename' => $fileUri, 'lineno' => (string) $line];
        if ($exceptionClass !== null) {
            $attributes['exception'] = $exceptionClass;
        }
        $element = '<xdebug:message ' . self::attributes($attributes);
        if ($exceptionClass === null || $exceptionMessage === '') {
            return $element . '/>';
        }

        return $element . '>' . self::escape($exceptionMessage) . '</xdebug:message>';
    }

    /**
     * Renders one <breakpoint> element, including hit bookkeeping and the condition
     *
     * hit_count / hit_value / hit_condition are what an IDE needs to render a hit-limited
     * breakpoint; the condition of a conditional breakpoint is returned base64-encoded in
     * the <expression> child, as the DBGp spec prescribes for user-supplied source.
     */
    public static function breakpoint(Breakpoint $breakpoint): string
    {
        $attributes = [
            'id'       => (string) $breakpoint->id,
            'type'     => $breakpoint->type->value,
            'state'    => $breakpoint->state(),
            'resolved' => 'resolved',
        ];
        if ($breakpoint->file !== null) {
            $attributes['filename'] = FileUri::fromPath($breakpoint->file);
        }
        if ($breakpoint->line !== null) {
            $attributes['lineno'] = (string) $breakpoint->line;
        }
        if ($breakpoint->exceptionName !== null) {
            $attributes['exception'] = $breakpoint->exceptionName;
        }
        if ($breakpoint->functionName !== null) {
            $attributes['function'] = $breakpoint->functionName;
        }
        $attributes['hit_count']     = (string) $breakpoint->hitCount;
        $attributes['hit_value']     = (string) $breakpoint->hitValue;
        $attributes['hit_condition'] = $breakpoint->hitCondition;

        $rendered = '<breakpoint ' . self::attributes($attributes);
        if ($breakpoint->condition === null) {
            return $rendered . '/>';
        }

        return $rendered . '><expression><![CDATA[' . base64_encode($breakpoint->condition) . ']]></expression></breakpoint>';
    }

    /**
     * Renders one <stack> element of a stack_get response
     *
     * type="file" is the only frame type a PHP debuggee produces (DBGp also defines
     * "eval"); `where` is the function name an IDE prints in its call-stack panel.
     */
    public static function stackFrame(int $level, string $where, string $fileUri, int $line): string
    {
        return '<stack ' . self::attributes([
            'where'    => $where,
            'level'    => (string) $level,
            'type'     => 'file',
            'filename' => $fileUri,
            'lineno'   => (string) $line,
        ]) . '/>';
    }

    /**
     * Renders the <map> elements of a typemap_get response
     */
    public static function typeMap(): string
    {
        $body = '';
        foreach (self::TYPE_MAP as $languageName => [$commonType, $schemaType]) {
            $attributes = ['name' => $languageName, 'type' => $commonType];
            if ($schemaType !== null) {
                $attributes['xsi:type'] = $schemaType;
            }
            $body .= '<map ' . self::attributes($attributes) . '/>';
        }

        return $body;
    }

    /**
     * Renders the <context> elements of a context_names response
     *
     * The ids are the ones context_get takes in its -c argument; the names are display
     * labels the IDE shows as variable-panel sections.
     *
     * @param array<string, int> $contexts Display name => context id
     */
    public static function contextNames(array $contexts): string
    {
        $body = '';
        foreach ($contexts as $name => $id) {
            $body .= '<context ' . self::attributes(['name' => $name, 'id' => (string) $id]) . '/>';
        }

        return $body;
    }

    /**
     * Renders an attribute string from a name => value map (values XML-escaped)
     *
     * Public for PropertySerializer, which renders the <property> tree out of the context
     * layer and needs the same escaping guarantees as the elements built here.
     *
     * @param array<string, string> $attributes
     */
    public static function attributes(array $attributes): string
    {
        $parts = [];
        foreach ($attributes as $name => $value) {
            $parts[] = $name . '="' . self::escape($value) . '"';
        }

        return implode(' ', $parts);
    }

    /**
     * Escapes a value for use in an XML attribute or element text node
     *
     * Control characters illegal in XML 1.0 (notably the NUL bytes PHP embeds in
     * anonymous-class names) are stripped first: htmlspecialchars would pass them
     * through and produce a document no parser accepts.
     */
    private static function escape(string $value): string
    {
        $value = self::stripInvalidXmlChars($value);

        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /**
     * Removes characters not permitted in XML 1.0 documents
     *
     * XML 1.0 allows only tab, newline, carriage return and the printable ranges;
     * anonymous-class names ("class@anonymous\0...") and binary string values would
     * otherwise break well-formedness.
     */
    private static function stripInvalidXmlChars(string $value): string
    {
        $cleaned = preg_replace('/[^\x09\x0A\x0D\x20-\x{10FFFF}]/u', '', $value);
        if ($cleaned !== null) {
            return $cleaned;
        }

        // Invalid UTF-8 in the input: fall back to stripping every non-printable-ASCII byte
        return (string) preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '', $value);
    }
}
