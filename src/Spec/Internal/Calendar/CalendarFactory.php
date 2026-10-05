<?php

declare(strict_types=1);

namespace Calendrics\Spec\Internal\Calendar;

use Calendrics\Exception\RangeError;
use Calendrics\Exception\TypeError;
use Calendrics\Spec\PlainDate;
use Calendrics\Spec\PlainDateTime;
use Calendrics\Spec\PlainMonthDay;
use Calendrics\Spec\PlainYearMonth;
use Calendrics\Spec\ZonedDateTime;

/**
 * Singleton factory for calendar protocol instances.
 *
 * Caches one CalendarProtocol per canonical calendar ID. Handles calendar ID
 * canonicalization (lowercasing, alias resolution) and validation.
 *
 * @internal
 */
final class CalendarFactory
{
    /** @var array<string, CalendarProtocol> */
    private static array $instances = [];

    /** @var array<string, string> Memoized canonicalize() results. */
    private static array $canonicalCache = [];

    /**
     * All calendar identifiers recognized by TC39 Temporal (ECMA-402).
     *
     * @var list<string>
     */
    private const KNOWN_CALENDARS = [
        'iso8601',
        'buddhist',
        'chinese',
        'coptic',
        'dangi',
        'ethioaa',
        'ethiopic',
        'gregory',
        'hebrew',
        'indian',
        'islamic-civil',
        'islamic-tbla',
        'islamic-umalqura',
        'japanese',
        'persian',
        'roc',
    ];

    /**
     * Alias map: deprecated or alternate IDs -> canonical ID.
     *
     * @var array<string, string>
     */
    private const ALIASES = [
        'islamicc' => 'islamic-civil',
        'ethiopic-amete-alem' => 'ethioaa',
    ];

    /**
     * Returns the CalendarProtocol for the given canonical calendar ID.
     */
    public static function get(string $calendarId): CalendarProtocol
    {
        return self::$instances[$calendarId] ??= self::create($calendarId);
    }

    /**
     * Canonicalizes a calendar identifier: lowercases, resolves aliases.
     *
     * @throws RangeError if the calendar ID is unknown.
     */
    public static function canonicalize(string $id): string
    {
        if (array_key_exists($id, self::$canonicalCache)) {
            return self::$canonicalCache[$id];
        }

        $lower = strtolower($id);

        if (array_key_exists($lower, self::ALIASES)) {
            $lower = self::ALIASES[$lower];
        }

        if (!in_array($lower, self::KNOWN_CALENDARS, strict: true)) {
            throw new RangeError("Unknown calendar \"{$id}\".");
        }

        return self::$canonicalCache[$id] = $lower;
    }

    /**
     * Resolves a constructor's positional `calendar` argument to a canonical
     * calendar ID. Per TC39, an omitted (or null — PHP cannot distinguish JS
     * `undefined` from `null` positionally) calendar defaults to ISO 8601; a
     * non-string, non-null value (bool/number/object/Symbol) is a wrong-type
     * TypeError; an unknown calendar string is a RangeError (via canonicalize).
     *
     * Unlike {@see resolveBagCalendar}, this does NOT accept ISO date/datetime
     * strings or `[u-ca=...]` annotations — a constructor calendar argument must
     * be a bare calendar identifier.
     *
     * @throws TypeError if $value is non-null and not a string.
     * @throws RangeError if $value names an unknown calendar.
     */
    public static function resolveConstructorCalendar(mixed $value, string $context): string
    {
        if (!is_string($value)) {
            throw new TypeError("{$context} calendar argument must be a string.");
        }
        return self::canonicalize($value);
    }

    /**
     * Resolves a property-bag `calendar` field to a canonical calendar ID.
     *
     * Type-checks $value, then forwards to {@see extractCalendarFromString}.
     * The $context label is used in the TypeError message (e.g. "PlainDate"
     * produces "PlainDate calendar must be a string; got int.").
     *
     * Per TC39 sec-temporal-totemporalcalendar step 1.a, objects that carry an
     * internal calendar slot (PlainDate, PlainDateTime, PlainMonthDay,
     * PlainYearMonth, ZonedDateTime) may be passed directly; their `calendarId`
     * is extracted in place of calling the public calendar getter.
     *
     * @throws TypeError if $value is neither a string nor a date-bearing Temporal object.
     * @throws RangeError if $value is malformed or names an unknown calendar.
     */
    public static function resolveBagCalendar(mixed $value, string $context): string
    {
        if (
            $value instanceof PlainDate
            || $value instanceof PlainDateTime
            || $value instanceof PlainMonthDay
            || $value instanceof PlainYearMonth
            || $value instanceof ZonedDateTime
        ) {
            return self::canonicalize($value->calendarId);
        }
        if (!is_string($value)) {
            throw new TypeError(sprintf('%s calendar must be a string; got %s.', $context, get_debug_type($value)));
        }
        return self::extractCalendarFromString($value);
    }

    /**
     * Resolves a calendar-field string to a canonical calendar ID.
     *
     * Accepts:
     *   - Bare calendar IDs ("hebrew", "iso8601", ...) — case-insensitive
     *   - ISO date / datetime / year-month / month-day / time strings, with
     *     or without a `[u-ca=...]` annotation. No annotation → "iso8601".
     *
     * Rejects (RangeError):
     *   - Empty string
     *   - Minus-zero extended-year strings ("-000000-...")
     *   - Bracket annotations not preceded by an ISO date prefix
     *     ("foo[u-ca=hebrew]", "[u-ca=hebrew]", "abc[u-ca=hebrew]")
     *   - Unknown calendar identifiers
     *
     * @throws RangeError for invalid/unsupported calendars.
     */
    public static function extractCalendarFromString(string $s): string
    {
        if (self::isKnownCalendar($s)) {
            return self::canonicalize($s);
        }
        return CalendarString::parse($s);
    }

    /**
     * Returns true if the given ID (after lowercasing and alias resolution) is a known calendar.
     */
    public static function isKnownCalendar(string $id): bool
    {
        $lower = strtolower($id);

        if (array_key_exists($lower, self::ALIASES)) {
            $lower = self::ALIASES[$lower];
        }

        return in_array($lower, self::KNOWN_CALENDARS, strict: true);
    }

    private static function create(string $id): CalendarProtocol
    {
        if ($id === 'iso8601') {
            return new IsoCalendar();
        }

        if ($id === 'hebrew') {
            return new PureHebrewCalendar();
        }

        if ($id === 'indian') {
            return new PureIndianCalendar();
        }

        if ($id === 'chinese' || $id === 'dangi') {
            return new ChineseCalendar($id);
        }

        return new IntlCalendarBridge($id);
    }
}
