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
 * Who the debug engine claims to be on the wire
 *
 * DBGp makes an IDE learn the same identity twice: once from the <init> packet it reads
 * before sending anything, and again from the feature_get values it polls afterwards.
 * The two must agree - an IDE that sees protocol_version "1.0" in <init> and something
 * else from feature_get has no way to reconcile them - so both readers (ResponseBuilder
 * and Session\Features) take the values from here.
 */
final class EngineIdentity
{
    /** Engine name, carried as the <engine> text of <init> */
    public const string NAME = 'zdebug';

    /**
     * The Xdebug protocol generation this engine speaks, as the <engine> version attribute
     *
     * IDEs read that number as a CAPABILITY LEVEL, not as a release number. PhpStorm gates
     * return-value debugging on ">= 3.2" and decides from this attribute alone - it does
     * not read the engine name, and it does not take the per-feature answer for a reply.
     * Reporting zdebug's own release here (0.1.0) therefore switched off every
     * version-gated feature, however completely the protocol behind it was implemented.
     *
     * So the name stays "zdebug" - nothing here claims to BE Xdebug - and the number says
     * which of Xdebug's protocol generations this engine implements. zdebug's own release
     * version lives in ZDebugModule::VERSION, which is what phpinfo(), `php -m` and
     * `php --ri zdebug` report.
     *
     * Raise this only together with the features of the generation it names: an IDE that
     * believes the number offers the user everything that generation could do. What keeps
     * that honest is feature_get, which answers per feature and never claims support the
     * dispatcher cannot deliver - so an IDE unlocking a 3.2-era feature we do not have
     * still gets told "unsupported" the moment it asks.
     */
    public const string XDEBUG_COMPAT_VERSION = '3.2.0';

    /** The DBGp revision implemented (<init> protocol_version, feature protocol_version) */
    public const string PROTOCOL_VERSION = '1.0';

    /** The debuggee's language, spelled the way DBGp names it */
    public const string LANGUAGE = 'PHP';

    /** Packet encoding: the iso-8859-1 prolog Xdebug emits and every DBGp client accepts */
    public const string ENCODING = 'iso-8859-1';

    /** How payload text (property values, conditions) is encoded */
    public const string DATA_ENCODING = 'base64';

    private function __construct() {}
}
