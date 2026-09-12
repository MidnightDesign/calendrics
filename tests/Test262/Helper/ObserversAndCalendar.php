<?php

declare(strict_types=1);

namespace Calendrics\Tests\Test262\Helper;

use Calendrics\Tests\Test262\ObserverTrace;
use Calendrics\Tests\Test262\PropertyBagObserver;
use Calendrics\Tests\Test262\StringCoercionObserver;

/**
 * Property-access observers and calendar-era canonicalization from TC39's TemporalHelpers harness.
 *
 * The observer helpers (toPrimitiveObserver / propertyBagObserver) record the order
 * in which an operation reads its arguments, which is what the order-of-operations
 * fixtures assert. JS's two coercion hooks map unevenly onto PHP: ToString has an
 * equivalent (`__toString`), ToNumber has none — no object can stand in for an
 * integer — so string values are observed and numeric ones are handed over bare.
 * {@see \Calendrics\Tests\Test262\Assert::compareObserverTrace()} drops the
 * correspondingly unobservable `…valueOf` events from each fixture's expected trace.
 *
 * canonicalizeCalendarEra normalizes implementation-specific era casing/aliases.
 *
 * Composed into {@see \Calendrics\Tests\Test262\TemporalHelpers}; the public
 * surface is `TemporalHelpers::toPrimitiveObserver()` etc.
 */
trait ObserversAndCalendar
{
    /**
     * Port of the JS TemporalHelpers.toPrimitiveObserver, for the half PHP can observe.
     *
     * The JS version returns an object whose `valueOf` / `toString` accessors log a
     * "get" event and whose returned functions log a "call" event, so a fixture can
     * assert that each argument is coerced at the point the spec says it is.
     *
     * A string becomes a {@see StringCoercionObserver}, which logs both events from
     * `__toString()`. Anything else is returned bare: PHP reaches ToNumber only for
     * real int/float values, so wrapping a number would change the outcome under test
     * rather than observe it.
     *
     * @psalm-api used by dynamically-required test scripts in tests/Test262/scripts/
     */
    public static function toPrimitiveObserver(ObserverTrace $calls, mixed $primitiveValue, string $propertyName): mixed
    {
        if (!is_string($primitiveValue)) {
            return $primitiveValue;
        }

        return new StringCoercionObserver($calls, $primitiveValue, $propertyName);
    }

    /**
     * Port of the JS TemporalHelpers.propertyBagObserver.
     *
     * The JS version wraps `propertyBag` in a Proxy that logs each `get` and routes
     * the value through {@see toPrimitiveObserver}. {@see PropertyBagObserver} is the
     * PHP spelling: `__get` does the logging and the same wrapping, and the spec layer
     * reaches it because it reads bags through a faithful `Get(O, P)`.
     *
     * An array bag is normalized to the observer object rather than passed through —
     * a JS property bag is always an object, and only the object path fires accessors.
     *
     * @psalm-api used by dynamically-required test scripts in tests/Test262/scripts/
     *
     * @param array<array-key, mixed>|object $propertyBag  Values the returned bag exposes.
     * @param list<string>|null           $skipToPrimitive Names handed over without ToString wrapping.
     */
    public static function propertyBagObserver(
        ObserverTrace $calls,
        array|object $propertyBag,
        string $objectName,
        ?array $skipToPrimitive = null,
    ): PropertyBagObserver {
        return new PropertyBagObserver(
            $calls,
            is_array($propertyBag) ? $propertyBag : get_object_vars($propertyBag),
            $objectName,
            $skipToPrimitive,
        );
    }

    /**
     * Normalizes calendar era strings across implementations.
     *
     * Different Temporal implementations may return slightly different era identifiers
     * for the same conceptual era (e.g., "ce" vs "CE" vs "ad"). This helper returns
     * a canonical lowercase form so that test assertions comparing eras are not
     * sensitive to implementation-specific casing or alias choices.
     *
     * For the `gregory` calendar the recognized canonical pairs are:
     *   ce  / ad / anno domini       → "ce"
     *   bce / bc / before common era  → "bce"
     *
     * For all other calendars the era string is returned lowercased unchanged.
     *
     * @psalm-api used by dynamically-required test scripts in tests/Test262/scripts/
     */
    public static function canonicalizeCalendarEra(string $calendarId, ?string $era): ?string
    {
        if ($era === null) {
            return null;
        }
        $normalized = strtolower(trim($era));
        if ($calendarId === 'gregory' || $calendarId === 'iso8601') {
            return match ($normalized) {
                'ce', 'ad', 'anno domini', 'common era' => 'ce',
                'bce', 'bc', 'before common era', 'b.c.', 'b.c.e.' => 'bce',
                default => $normalized,
            };
        }
        return $normalized;
    }

    /**
     * Returns the runtime's default calendar identifier in TC39/BCP-47 canonical
     * form — the value `new Intl.DateTimeFormat(...).resolvedOptions().calendar`
     * yields in JS.
     *
     * The options-conflict toLocaleString fixtures use that Intl chain ONLY to pick
     * a default calendar for constructing the instance under test; the calendar value
     * itself is not load-bearing. We derive it from ICU's default calendar type
     * (e.g. ICU "gregorian") and map it to the BCP-47 unicode calendar key ("gregory")
     * that ECMA-402 / Temporal use, mirroring the JS implementation rather than
     * hardcoding a constant.
     *
     * @psalm-api used by dynamically-required test scripts in tests/Test262/scripts/
     */
    public static function defaultLocaleCalendar(): string
    {
        // IntlCalendar::createInstance() is signature-nullable (it returns null on an
        // invalid timezone — none passed here — or ICU OOM). The factory result is read
        // through a ?\IntlCalendar-typed wrapper so the null guard is honored by every
        // analyzer (PHPStan's bundled stub otherwise wrongly types the call non-null).
        $calendar = self::defaultIntlCalendar();
        $icuType = $calendar === null ? 'gregorian' : $calendar->getType();
        // ICU calendar type → BCP-47 unicode `ca` key (the few that differ).
        return match ($icuType) {
            'gregorian' => 'gregory',
            'ethiopic-amete-alem' => 'ethioaa',
            default => $icuType,
        };
    }

    /**
     * Returns the default ICU calendar instance, or null if ICU cannot create one.
     *
     * Wraps {@see \IntlCalendar::createInstance()} behind an explicit ?\IntlCalendar
     * return type so callers' null handling type-checks consistently across analyzers
     * (PHPStan's bundled stub types the factory as non-null; the runtime and the PHP
     * manual declare it ?\IntlCalendar).
     */
    private static function defaultIntlCalendar(): ?\IntlCalendar
    {
        return \IntlCalendar::createInstance();
    }

    /**
     * Returns the value of `Intl.supportedValuesOf("calendar")` — every calendar the
     * runtime can format with, as BCP-47 unicode `ca` keys, sorted ascending.
     *
     * Derived from ICU's own keyword-value list rather than from this library's
     * calendar registry, so the fixtures that pick "some calendar other than the
     * locale's" are not silently narrowed to whatever we already support.
     *
     * @psalm-api used by dynamically-required test scripts in tests/Test262/scripts/
     *
     * @return list<string>
     */
    public static function supportedCalendars(): array
    {
        $calendars = [];
        foreach (self::icuCalendarTypes() as $icuType) {
            // ICU calendar type → BCP-47 unicode `ca` key (the few that differ).
            $calendars[] = match ($icuType) {
                'gregorian' => 'gregory',
                'ethiopic-amete-alem' => 'ethioaa',
                default => $icuType,
            };
        }
        sort($calendars);

        return $calendars;
    }

    /**
     * Returns every calendar type ICU knows, in ICU's own vocabulary.
     *
     * Wrapped behind an explicit `list<string>` return type for the same reason as
     * {@see self::defaultIntlCalendar()}: it keeps the iterator's element type out of
     * the caller, where the analyzers disagree about it.
     *
     * @return list<string>
     */
    private static function icuCalendarTypes(): array
    {
        // 'und' asks for every calendar rather than the ones common in some region;
        // getKeywordValuesForLocale() is only false for a keyword ICU does not know.
        $values = \IntlCalendar::getKeywordValuesForLocale('calendar', locale: 'und', onlyCommon: false);
        // The runtime returns false for a keyword ICU does not know; PHPStan's stub
        // types the return non-false, so the guard reads as always-false to it.
        // @phpstan-ignore identical.alwaysFalse
        if ($values === false) {
            return [];
        }

        /** @var list<string> */
        return array_values(iterator_to_array($values));
    }
}
