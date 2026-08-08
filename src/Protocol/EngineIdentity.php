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

    /** Engine version, the `version` attribute of that same <engine> element */
    public const string VERSION = '0.1.0';

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
