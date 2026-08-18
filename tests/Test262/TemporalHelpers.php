<?php

declare(strict_types=1);

namespace Calendrics\Tests\Test262;

use Calendrics\Tests\Test262\Helper\AssertsValueObjects;
use Calendrics\Tests\Test262\Helper\ChecksBehavior;
use Calendrics\Tests\Test262\Helper\IsoStringData;
use Calendrics\Tests\Test262\Helper\ObserversAndCalendar;

/**
 * PHP port of TC39's TemporalHelpers test harness.
 *
 * Only the subset used in the generated test262 scripts is implemented here.
 * Unimplemented methods are handled at the transpiler level (emitIncomplete).
 *
 * The implementation is split across four composed traits mirroring the upstream
 * JS harness's own sub-namespaces — keeping the public `TemporalHelpers::foo()`
 * surface byte-identical while each concern lives in its own file:
 *   - {@see AssertsValueObjects}    — `assert*` value-object comparisons
 *   - {@see ChecksBehavior}         — `check*` behavioral harnesses
 *   - {@see IsoStringData}          — `iso*` / month / calendar data providers
 *   - {@see ObserversAndCalendar}   — observer stand-ins + era canonicalization
 *
 * @psalm-api used by dynamically-required test scripts in tests/Test262/scripts/
 */
final class TemporalHelpers
{
    use AssertsValueObjects;
    use ChecksBehavior;
    use IsoStringData;
    use ObserversAndCalendar;
}
