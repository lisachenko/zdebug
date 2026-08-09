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

use ZDebug\Breakpoint\BreakpointType;
use ZDebug\Protocol\EngineIdentity;

/**
 * DBGp feature store with Xdebug-compatible defaults
 *
 * Backs feature_get/feature_set. Some features are read-only descriptions of the
 * engine (language_name, protocol_version) and are taken from EngineIdentity so they
 * cannot contradict the <init> packet; others are IDE-tunable knobs the debugger honors
 * (max_depth, max_children, max_data), for which DEFAULTS is the one place the shipped
 * values live. Unknown names are reported unsupported rather than rejected, so an IDE
 * probing capabilities degrades gracefully.
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

        /**
         * Return-value debugging, as introduced by Xdebug 3.2
         *
         * An IDE turns this on to ask for one extra stop when a stepped-through function
         * returns, with the returned value attached. It is off until asked for: the stop
         * costs a break the user did not press a button for, and an IDE that never sets
         * the feature must never see one.
         */
        'breakpoint_include_return_value' => true,
    ];

    /** @var array<string, string> The values every session starts from */
    private const array DEFAULTS = [
        'language_name'             => EngineIdentity::LANGUAGE,
        'language_supports_threads' => '0',
        'protocol_version'          => EngineIdentity::PROTOCOL_VERSION,
        'encoding'                  => EngineIdentity::ENCODING,
        'data_encoding'             => EngineIdentity::DATA_ENCODING,
        'supports_async'            => '0',
        'supports_postmortem'       => '0',
        'multiple_sessions'         => '0',
        'max_children'              => '100',
        'max_data'                  => '1024',
        'max_depth'                 => '1',
        'resolved_breakpoints'      => '1',
        'breakpoint_details'        => '0',
        'show_hidden'               => '0',
        'extended_properties'       => '0',
        'notify_ok'                 => '0',

        // Advertised as supported (an IDE probes it before offering the feature) and off
        // until the IDE asks for it
        'breakpoint_include_return_value' => '0',
    ];

    public function __construct(string $languageVersion)
    {
        $this->values = array_merge(self::DEFAULTS, [
            'language_version' => $languageVersion,
            'breakpoint_types' => self::breakpointTypes(),
        ]);
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

    /**
     * Reads a numeric feature, falling back to the value zdebug ships with
     *
     * feature_set takes any string, so a client may store "auto" in max_depth; rather
     * than propagate a nonsense limit into the property serializer, such a value (and an
     * unknown feature name, which reads as 0) resolves back to the default.
     */
    public function getInt(string $name): int
    {
        $value = $this->values[$name] ?? null;
        if (!is_numeric($value)) {
            $value = self::DEFAULTS[$name] ?? '0';
        }

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Whether the IDE has switched a boolean feature on
     */
    public function isEnabled(string $name): bool
    {
        return $this->getInt($name) === 1;
    }

    /**
     * The property-rendering limits, as the max_* features currently stand
     *
     * Both the command dispatcher and the session render properties, and reading the
     * three names in two places is how they would eventually come to disagree about
     * which features govern a <property>.
     *
     * @return array{int, int, int} [max_depth, max_children, max_data]
     */
    public function propertyLimits(): array
    {
        return [$this->getInt('max_depth'), $this->getInt('max_children'), $this->getInt('max_data')];
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

    /**
     * The space-separated `breakpoint_types` an IDE reads before offering breakpoint kinds
     *
     * Derived from BreakpointType, the same enum breakpoint_set validates -t against, so
     * the advertised list cannot outgrow what the dispatcher will actually register.
     */
    private static function breakpointTypes(): string
    {
        return implode(' ', array_column(BreakpointType::cases(), 'value'));
    }
}
