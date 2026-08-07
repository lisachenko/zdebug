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
 * Parses a raw DBGp command line into a Command
 *
 * Grammar (per the DBGp spec): `command_name [-o value ...] [-- base64(data)]`. Option
 * values may be quoted with single or double quotes; everything after a standalone `--`
 * is the base64-encoded data argument. The transaction id (-i) is mandatory and lifted
 * out into its own field.
 */
final class CommandParser
{
    /**
     * @throws ProtocolException when the line is empty, lacks -i, or has malformed data
     */
    public function parse(string $line): Command
    {
        $line = trim($line);
        if ($line === '') {
            throw ProtocolException::emptyCommand();
        }

        // Split off the data part first: a standalone "--" token separates it
        $data    = null;
        $dataPos = self::findDataSeparator($line);
        if ($dataPos !== null) {
            $encoded = trim(substr($line, $dataPos + 2));
            $decoded = base64_decode($encoded, true);
            $line    = rtrim(substr($line, 0, $dataPos));
            if ($encoded !== '' && $decoded === false) {
                throw ProtocolException::malformedData($line);
            }
            $data = $decoded === false ? '' : $decoded;
        }

        $tokens = self::tokenize($line);
        $name   = array_shift($tokens);
        if ($name === null || $name === '') {
            throw ProtocolException::emptyCommand();
        }

        $arguments = [];
        for ($index = 0; $index < count($tokens); $index++) {
            $token = $tokens[$index];
            if (str_starts_with($token, '-') && strlen($token) >= 2) {
                $option             = substr($token, 1);
                $value              = $tokens[$index + 1] ?? '';
                $arguments[$option] = $value;
                $index++;
            }
        }

        $transactionId = $arguments['i'] ?? null;
        if ($transactionId === null) {
            throw ProtocolException::missingTransactionId($name);
        }
        unset($arguments['i']);

        return new Command($name, $transactionId, $arguments, $data);
    }

    /**
     * Finds the byte offset of the standalone "--" data separator, or null
     */
    private static function findDataSeparator(string $line): ?int
    {
        $length = strlen($line);
        for ($index = 0; $index < $length; $index++) {
            if (
                $line[$index]                === '-'
                && ($line[$index + 1] ?? '') === '-'
                && ($index === 0 || $line[$index - 1] === ' ')
                && (($index + 2) === $length || $line[$index + 2] === ' ')
            ) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Splits a command line into whitespace-separated tokens, honoring single/double quotes
     *
     * @return list<string>
     */
    private static function tokenize(string $line): array
    {
        $tokens  = [];
        $current = '';
        $quote   = null;
        $inToken = false;
        $length  = strlen($line);

        for ($index = 0; $index < $length; $index++) {
            $char = $line[$index];
            if ($quote !== null) {
                if ($char === $quote) {
                    $quote = null;
                } else {
                    $current .= $char;
                }
                continue;
            }
            if ($char === '"' || $char === "'") {
                $quote   = $char;
                $inToken = true;
                continue;
            }
            if ($char === ' ' || $char === "\t") {
                if ($inToken) {
                    $tokens[] = $current;
                    $current  = '';
                    $inToken  = false;
                }
                continue;
            }
            $current .= $char;
            $inToken = true;
        }
        if ($inToken) {
            $tokens[] = $current;
        }

        return $tokens;
    }
}
