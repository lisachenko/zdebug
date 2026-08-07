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
 * Conversion between local filesystem paths and DBGp file:// URIs
 *
 * DBGp identifies source files by URI. IDEs emit either the triple-slash form
 * (file:///abs/path, empty authority) or the host form (file://host/abs/path); this
 * class accepts both on the way in and always emits the triple-slash form on the way
 * out, which is what Xdebug produces and every known client accepts.
 */
final class FileUri
{
    /**
     * Converts an absolute filesystem path to a file:// URI
     */
    public static function fromPath(string $path): string
    {
        // Percent-encode each segment but keep the slashes and a leading drive-letter colon
        $segments = explode('/', $path);
        $encoded  = array_map(
            static fn(string $segment): string => rawurlencode($segment),
            $segments,
        );

        return 'file://' . implode('/', $encoded);
    }

    /**
     * Converts a DBGp file:// URI back to a filesystem path
     *
     * Tolerates file:///path (empty authority) and file://host/path (named authority);
     * the authority is discarded either way. Non-file URIs are returned undecoded so a
     * caller can reject them.
     */
    public static function toPath(string $uri): string
    {
        if (!str_starts_with($uri, 'file://')) {
            return $uri;
        }
        $rest = substr($uri, strlen('file://'));

        // Strip an authority component if present: everything up to the first '/'.
        // file:///a/b -> authority '' , path '/a/b'; file://host/a -> authority 'host', path '/a'
        $slash = strpos($rest, '/');
        if ($slash === false) {
            $path = '';
        } else {
            $path = substr($rest, $slash);
        }

        return rawurldecode($path);
    }
}
