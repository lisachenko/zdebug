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

namespace ZDebug\Session;

/**
 * DBGp feature store with Xdebug-compatible defaults
 *
 * Backs feature_get/feature_set. Some features are read-only descriptions of the
 * engine (language_name, protocol_version); others are IDE-tunable knobs the debugger
 * honors (max_depth, max_children, max_data). Unknown names are reported unsupported
 * rather than rejected, so an IDE probing capabilities degrades gracefully.
 *
 * @see https://xdebug.org/docs/dbgp#feature-names
 */
final class Features
{
    /** @var array<string, string> */
    private array $values;

    /** @var array<string, true> Feature names the IDE is allowed to overwrite */
    private const array WRITABLE = [
        'max_children'         => true,
        'max_data'             => true,
        'max_depth'            => true,
        'show_hidden'          => true,
        'extended_properties'  => true,
        'notify_ok'            => true,
        'resolved_breakpoints' => true,
        'breakpoint_details'   => true,
    ];

    public function __construct(string $languageVersion)
    {
        $this->values = [
            'language_name'             => 'PHP',
            'language_version'          => $languageVersion,
            'language_supports_threads' => '0',
            'protocol_version'          => '1.0',
            'encoding'                  => 'iso-8859-1',
            'data_encoding'             => 'base64',
            'supports_async'            => '0',
            'supports_postmortem'       => '0',
            'breakpoint_types'          => 'line conditional exception',
            'multiple_sessions'         => '0',
            'max_children'              => '100',
            'max_data'                  => '1024',
            'max_depth'                 => '1',
            'resolved_breakpoints'      => '1',
            'breakpoint_details'        => '0',
            'show_hidden'               => '0',
            'extended_properties'       => '0',
            'notify_ok'                 => '0',
        ];
    }

    /**
     * Whether the feature is known and readable
     */
    public function supports(string $name): bool
    {
        return array_key_exists($name, $this->values);
    }

    public function get(string $name): ?string
    {
        return $this->values[$name] ?? null;
    }

    public function getInt(string $name, int $default): int
    {
        $value = $this->values[$name] ?? null;

        return $value !== null && is_numeric($value) ? (int) $value : $default;
    }

    /**
     * Attempts to set a feature; returns whether it was accepted (writable)
     */
    public function set(string $name, string $value): bool
    {
        if (!isset(self::WRITABLE[$name])) {
            return false;
        }
        $this->values[$name] = $value;

        return true;
    }
}
