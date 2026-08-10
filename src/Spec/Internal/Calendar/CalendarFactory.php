<?php

declare(strict_types=1);

namespace Temporal\Spec\Internal\Calendar;

use Temporal\Exception\RangeError;
use Temporal\Exception\TypeError;

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
        if ($value === null) {
            $value = 'iso8601';
        } elseif (!is_string($value)) {
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
     * @throws TypeError if $value is not a string and does not carry a calendarId.
     * @throws RangeError if $value is malformed or names an unknown calendar.
     */
    public static function resolveBagCalendar(mixed $value, string $context): string
    {
        // Fast path: Temporal objects with an internal calendar slot carry a
        // `calendarId` string — extract it directly (mirrors spec step 1.a).
        if (is_object($value) && property_exists($value, 'calendarId') && is_string($value->calendarId)) {
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
        if ($s === '') {
            throw new RangeError('Calendar ID must not be empty.');
        }
        // Reject minus-zero extended year ("-000000" with no further digits).
        if (preg_match(pattern: '/^-0{6}(?:[^0-9]|$)/', subject: $s) === 1) {
            throw new RangeError("Invalid calendar \"{$s}\": minus-zero year.");
        }
        // Per ParseTemporalCalendarString, a string with a bracket annotation
        // must parse as a Temporal date/time string — i.e. the prefix before
        // '[' must be a valid ISO date or time prefix. Bare bracket annotations
        // and bracket annotations following non-Temporal prefixes are RangeError.
        if (str_contains($s, '[')) {
            $prefix = substr($s, offset: 0, length: (int) strpos($s, needle: '['));
            if (!self::looksLikeIsoDateOrTime($prefix)) {
                throw new RangeError(
                    "Invalid calendar string \"{$s}\": bracket annotation must follow an ISO date or time prefix.",
                );
            }
            $m = null;
            if (preg_match(pattern: '/\[!?u-ca=([^\]]+)\]/', subject: $s, matches: $m) === 1) {
                return self::canonicalize($m[1]);
            }
            // Bracket without u-ca (e.g. timezone annotation) → default iso8601.
            return 'iso8601';
        }
        // ISO date / datetime / time strings (no annotation) → iso8601.
        if (self::looksLikeIsoDateOrTime($s)) {
            return 'iso8601';
        }
        // Plain calendar ID.
        return self::canonicalize($s);
    }

    /**
     * Returns true if $s starts with anything that looks like an ISO date or
     * time prefix: date (YYYY-MM, MM-DD, ±YYYYYY-), datetime (digit-T-digit),
     * or time form (T-prefix, HH:MM, bare HH, compact HHMM/HHMMSS).
     */
    private static function looksLikeIsoDateOrTime(string $s): bool
    {
        if ($s === '') {
            return false;
        }
        // Date / datetime.
        if (
            preg_match(pattern: '/^\d{2}-\d{2}|^\d{4}-\d{2}|^[+-]\d{6}-/', subject: $s) === 1
            || preg_match(pattern: '/\d[Tt]\d/', subject: $s) === 1
        ) {
            return true;
        }
        // Time-only forms.
        if (preg_match(pattern: '/^[Tt]\d/', subject: $s) === 1) {
            return true;
        }
        if (preg_match(pattern: '/^\d{2}:/', subject: $s) === 1) {
            return true;
        }
        // Bare hour: exactly 2 digits.
        if (preg_match(pattern: '/^\d{2}$/', subject: $s) === 1) {
            return true;
        }
        // Compact time HHMM/HHMMSS: 4–6 digits NOT followed by '-DD-'.
        return (
            preg_match(pattern: '/^\d{4,6}(?:[.,]|\+|$)/', subject: $s) === 1
            || preg_match(pattern: '/^\d{4,6}-(?!\d{2}-)/', subject: $s) === 1
        );
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

        return new IntlCalendarBridge($id);
    }
}
