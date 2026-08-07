<?php

/**
 * This file is part of the zdebug package.
 *
 * @copyright Copyright 2026, Lisachenko Alexander <lisachenko.it@gmail.com>
 *
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 *
 * Debuggee for the exception-breakpoint integration test. Throws (and catches) two
 * unrelated LogicException subclasses: the unmatched one first, so a hook that broke on
 * every throw would be caught reporting the wrong frame. Both are caught, so the script
 * must still run to completion after the debugger resumes it.
 */
declare(strict_types=1);

function raiseUnmatched(): string
{
    try {
        throw new LengthException('unmatched throw');
    } catch (LengthException $error) {
        return $error->getMessage();
    }
}

function raiseMatched(): string
{
    try {
        throw new DomainException('matched throw');
    } catch (DomainException $error) {
        return $error->getMessage();
    }
}

$unmatched = raiseUnmatched();
$matched   = raiseMatched();

echo 'UNMATCHED=' . $unmatched . "\n";
echo 'MATCHED=' . $matched . "\n";
echo "THROWING APP DONE\n";
