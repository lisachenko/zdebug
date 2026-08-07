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
    public function error(string $command, string $transactionId, int $code, string $message): string
    {
        $body = '<error code="' . $code . '"><message>' . self::escape($message) . '</message></error>';

        return $this->response($command, $transactionId, [], $body);
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
     */
    public static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
