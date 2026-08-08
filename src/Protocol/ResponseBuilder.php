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

/**
 * Builds DBGp XML payloads (the wire framing is applied by DbgpConnection)
 *
 * Every packet is a standalone XML document opening with the iso-8859-1 prolog Xdebug
 * uses and the debugger_protocol_v1 namespace (plus the xdebug extension namespace,
 * which some IDEs key cursor movement off). Payload text values are base64-encoded by
 * the caller (PropertySerializer); this class only escapes XML attribute/element text.
 */
final class ResponseBuilder
{
    public const string PROLOG    = '<?xml version="1.0" encoding="iso-8859-1"?>';
    public const string NS        = 'urn:debugger_protocol_v1';
    public const string NS_XDEBUG = 'https://xdebug.org/dbgp/xdebug';

    private const string ENGINE_NAME    = 'zdebug';
    private const string ENGINE_VERSION = '0.1.0';

    /**
     * Builds the <init> packet sent immediately after connecting to the IDE
     */
    public function init(string $fileUri, string $ideKey, int $appId, string $languageVersion): string
    {
        $attributes = self::attributes([
            'xmlns'                   => self::NS,
            'xmlns:xdebug'            => self::NS_XDEBUG,
            'fileuri'                 => $fileUri,
            'language'                => 'PHP',
            'xdebug:language_version' => $languageVersion,
            'protocol_version'        => '1.0',
            'appid'                   => (string) $appId,
            'idekey'                  => $ideKey,
        ]);
        $engine = '<engine version="' . self::escape(self::ENGINE_VERSION) . '">'
            . '<![CDATA[' . self::ENGINE_NAME . ']]></engine>';

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
     * Renders an attribute string from a name => value map (values XML-escaped)
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
    public static function escape(string $value): string
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
    public static function stripInvalidXmlChars(string $value): string
    {
        $cleaned = preg_replace('/[^\x09\x0A\x0D\x20-\x{10FFFF}]/u', '', $value);
        if ($cleaned !== null) {
            return $cleaned;
        }

        // Invalid UTF-8 in the input: fall back to stripping every non-printable-ASCII byte
        return (string) preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '', $value);
    }
}
