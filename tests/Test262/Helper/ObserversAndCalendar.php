<?php

declare(strict_types=1);

namespace Temporal\Tests\Test262\Helper;

/**
 * Observer stand-ins and calendar-era canonicalization from TC39's TemporalHelpers harness.
 *
 * The JS observer helpers (toPrimitiveObserver / propertyBagObserver) log
 * property-access order to drive order-of-operations tests; PHP has no observable
 * equivalent, so these are passthrough stand-ins (see each method's docblock).
 * canonicalizeCalendarEra normalizes implementation-specific era casing/aliases.
 *
 * Composed into {@see \Temporal\Tests\Test262\TemporalHelpers}; the public
 * surface is `TemporalHelpers::toPrimitiveObserver()` etc.
 */
trait ObserversAndCalendar
{
    /**
     * Passthrough stand-in for the JS TemporalHelpers.toPrimitiveObserver.
     *
     * The JS version returns an object whose `valueOf` and `toString` getters log
     * "get" events and whose returned functions log "call" events to a shared array,
     * so that order-of-operations tests can verify the spec's prescribed sequence
     * of property accesses on argument objects.
     *
     * PHP does not distinguish property-get from method-call on a normal object
     * (no JS-style ToPrimitive coercion for object args), so we return the primitive
     * directly. Tests that care purely about the call sequence — `assert.compareArray`
     * on the calls array, and explicit `.splice(0)` clears — are dropped at the
     * transpiler level, which leaves the constructor / from() / etc. behavior under
     * test still exercised.
     *
     * @psalm-suppress UnusedParam
     * @psalm-api used by dynamically-required test scripts in tests/Test262/scripts/
     *
     * @param list<string> $calls Ignored — we do not track property-access order.
     */
    public static function toPrimitiveObserver(array &$calls, mixed $primitiveValue, string $propertyName): mixed
    {
        return $primitiveValue;
    }

    /**
     * Passthrough stand-in for the JS TemporalHelpers.propertyBagObserver.
     *
     * The JS version wraps `propertyBag` in a Proxy that logs `get`/`has`/`ownKeys`
     * events and wraps each property's value via {@see toPrimitiveObserver}. Same
     * rationale as toPrimitiveObserver: PHP has no observable equivalent, and the
     * compareArray order-checks at the call sites are dropped by the transpiler.
     *
     * @psalm-suppress UnusedParam
     * @psalm-api used by dynamically-required test scripts in tests/Test262/scripts/
     *
     * @param list<string>      $calls            Ignored.
     * @param array<string, mixed>|object $propertyBag    Returned unchanged.
     * @param list<string>|null $skipToPrimitive  Ignored.
     *
     * @return array<string, mixed>|object
     */
    public static function propertyBagObserver(
        array &$calls,
        array|object $propertyBag,
        string $objectName,
        ?array $skipToPrimitive = null,
    ): array|object {
        return $propertyBag;
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
