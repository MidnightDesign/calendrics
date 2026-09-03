<?php

declare(strict_types=1);

namespace Calendrics\Spec\Internal;

use Calendrics\Exception\RangeError;
use Calendrics\Exception\TypeError;
use Calendrics\Spec\Duration;
use Calendrics\Spec\Internal\Calendar\CalendarFactory;
use Calendrics\Spec\PlainDate;
use Calendrics\Spec\ZonedDateTime;

/**
 * Resolution and validation of the `relativeTo` option.
 *
 * A Duration carrying years, months or weeks has no fixed length, so
 * {@see Duration::total()} and {@see Duration::round()} need an anchor before they
 * can convert it to anything. TC39 spells that anchor four different ways — a
 * `PlainDate`, a `ZonedDateTime`, an ISO string, or a property bag — and each
 * spelling carries its own validation rules and its own set of ways to be out of
 * range. This class turns all four into the two shapes the arithmetic consumes:
 *
 *   - a plain `year`/`month`/`day` bag, via {@see self::toPlainDateBag()}; and
 *   - a zoned info record, via {@see self::resolveZdt()}, which is non-null only
 *     when the anchor names an IANA zone that may observe DST. UTC and fixed-offset
 *     anchors resolve to null, because a calendar day is exactly 86 400 seconds there
 *     and the DST-aware arithmetic in {@see AnchorMath} would be wasted work.
 *
 * @internal
 */
final class RelativeTo
{
    /**
     * The calendar fields a `relativeTo` property bag is built from — a ZonedDateTime-
     * shaped anchor, so the full date-and-time set. `era`/`eraYear` are added by
     * {@see FieldBag} for calendars that have eras; `offset`/`timeZone` are non-calendar
     * fields, passed alongside.
     *
     * @var list<string>
     */
    private const array FIELDS = [
        'year',
        'month',
        'monthCode',
        'day',
        'hour',
        'minute',
        'second',
        'millisecond',
        'microsecond',
        'nanosecond',
    ];

    /**
     * Reports whether a valid, non-null `relativeTo` is present in the options,
     * throwing if one is present but malformed.
     *
     * @param array<array-key, mixed> $options An options bag already normalized by {@see Options}.
     * @throws RangeError for invalid relativeTo strings or property bags.
     * @throws TypeError for invalid relativeTo types.
     */
    public static function isPresent(array $options): bool
    {
        if (!array_key_exists('relativeTo', $options)) {
            return false;
        }
        /** @var mixed $rt */
        $rt = $options['relativeTo'];
        // PHP null is treated as the option being absent. test262 fixtures pass `null`
        // in `[null, plainRelativeTo, zonedRelativeTo]` parametric tables (for
        // non-calendar Durations) and expect the round to succeed — collapsing
        // PHP null to "absent" matches that. Genuinely-typed wrong-type fixtures
        // (relativeto-wrong-type) cover the same path: when the calling context
        // does require an anchor, the absent-relativeTo branch raises
        // RangeError ≡ JS RangeError.
        if ($rt === null) {
            return false;
        }
        if ($rt instanceof PlainDate || $rt instanceof ZonedDateTime) {
            return true; // PlainDate and ZonedDateTime objects are valid relativeTo values
        }
        if (is_string($rt)) {
            self::parseString($rt); // throws on invalid
            return true;
        }
        if (is_object($rt)) {
            $rt = self::normalizeBag($rt);
        }
        if (is_array($rt)) {
            self::validatePropertyBag($rt);
            return true;
        }
        throw new TypeError('relativeTo must be a string or property bag array.');
    }

    /**
     * Snapshots a `relativeTo` property bag, reading the anchor fields TC39 prescribes.
     *
     * Every caller that accepts a bag goes through here rather than calling
     * {@see FieldBag::forCalendarType()} itself, so {@see self::FIELDS} has one owner
     * and no call site can drift to a different field list.
     *
     * @param array<array-key, mixed>|object $rt
     * @return array<array-key, mixed>
     */
    public static function normalizeBag(array|object $rt): array
    {
        return FieldBag::forCalendarType($rt, self::FIELDS, ['offset', 'timeZone'], 'relativeTo');
    }

    /**
     * Converts a relativeTo value (PlainDate, ZonedDateTime, string, or property bag)
     * into an array with integer 'year', 'month', 'day' keys.
     *
     * @return array{year: int, month: int, day: int}
     */
    public static function toPlainDateBag(mixed $rt): array
    {
        if ($rt instanceof ZonedDateTime) {
            return self::zdtToPlainDateBag($rt);
        }
        if ($rt instanceof PlainDate) {
            return ['year' => $rt->isoYear, 'month' => $rt->isoMonth, 'day' => $rt->isoDay];
        }
        if (is_string($rt)) {
            $parsed = self::parseString($rt);
            return ['year' => (int) $parsed['year'], 'month' => (int) $parsed['month'], 'day' => (int) $parsed['day']];
        }
        // Property bag — normalize generic objects to arrays first.
        if (is_object($rt)) {
            $rt = self::normalizeBag($rt);
        }
        assert(is_array($rt), description: 'non-string $rt must be a property-bag array at this point');
        [$year, $month, $day] = self::anchorYmd($rt);
        return ['year' => $year, 'month' => $month, 'day' => $day];
    }

    /**
     * Reduces a `relativeTo` of any spelling to the anchor it denotes.
     *
     * Every spelling resolves through here so that the range rules downstream can be
     * asked of the anchor rather than re-derived per spelling; see {@see RelativeAnchor}
     * for why that distinction is the whole point.
     *
     * Not to be confused with {@see self::resolveZdt()}, which answers the narrower
     * question "does this anchor need DST-aware arithmetic" and so reports UTC and
     * fixed-offset anchors as unzoned. Here every zoned anchor counts as zoned.
     *
     * @throws RangeError if the anchor itself is outside the representable range.
     */
    public static function resolveAnchor(mixed $rt): RelativeAnchor
    {
        if (is_object($rt) && !$rt instanceof ZonedDateTime && !$rt instanceof PlainDate) {
            $rt = self::normalizeBag($rt);
        }
        if ($rt instanceof ZonedDateTime) {
            return RelativeAnchor::onInstant(...$rt->epochParts());
        }
        if ($rt instanceof PlainDate) {
            return RelativeAnchor::onDate(AnchorMath::isoDateToEpochDays($rt->isoYear, $rt->isoMonth, $rt->isoDay));
        }
        if (is_string($rt)) {
            $parsed = self::parseString($rt);
            return $parsed['_isZDT'] === true
                ? RelativeAnchor::onInstant((int) $parsed['_utcSec'], 0)
                : RelativeAnchor::onDate((int) $parsed['_epochDays']);
        }
        assert(is_array($rt), description: 'non-string $rt must be a property-bag array at this point');
        $epochDays = self::bagEpochDays($rt);
        if (array_key_exists('timeZone', $rt)) {
            return self::zonedBagAnchor($rt, $epochDays);
        }
        // CreateTemporalDate's range check, which the string spelling gets in parseString().
        if ($epochDays < -100_000_001 || $epochDays > 100_000_000) {
            throw new RangeError('relativeTo property bag is outside the representable date range.');
        }
        return RelativeAnchor::onDate($epochDays);
    }

    /**
     * The local date a `relativeTo` property bag denotes, as a day count.
     *
     * ToRelativeTemporalObject builds the anchor with overflow=constrain, so an
     * out-of-range month or day clamps instead of throwing; only a year outside the
     * ISO limits leaves nothing to clamp towards. Clamping first also keeps the
     * Julian-day arithmetic inside int64, which an unbounded field would blow past.
     *
     * @param array<array-key,mixed> $bag
     * @throws RangeError if the year is outside the ISO date limits.
     */
    private static function bagEpochDays(array $bag): int
    {
        [$year, $month, $day] = self::anchorYmd($bag);
        if ($year < -271_821 || $year > 275_760) {
            throw new RangeError('relativeTo property bag is outside the representable date range.');
        }
        $month = max(1, min(12, $month));
        return AnchorMath::isoDateToEpochDays(
            $year,
            $month,
            max(1, min(CalendarMath::calcDaysInMonth($year, $month), $day)),
        );
    }

    /**
     * The instant a `relativeTo` property bag naming a time zone denotes.
     *
     * @param array<array-key,mixed> $bag
     * @param int $epochDays The bag's local date, as a day count.
     * @throws RangeError if the instant is outside the representable range.
     */
    private static function zonedBagAnchor(array $bag, int $epochDays): RelativeAnchor
    {
        $info = self::resolveZdt($bag);
        if ($info !== null) {
            // An IANA zone, where a wall clock can be ambiguous or non-existent:
            // resolveZdt() has already walked the transition data to settle it.
            return RelativeAnchor::onInstant($info['epochSec'], $info['subNs']);
        }
        /** @var mixed $tz */
        $tz = $bag['timeZone'];
        assert(is_string($tz), description: 'validatePropertyBag() rejects a non-string timeZone');
        // Every other zone holds one constant offset, so the instant is the wall clock
        // shifted by it, with no ambiguity to disambiguate.
        $wallSec =
            ($epochDays * 86_400)
            + (self::bagTimeField($bag, 'hour', 23) * 3_600)
            + (self::bagTimeField($bag, 'minute', 59) * 60)
            + self::bagTimeField($bag, 'second', 59);
        $epochSec = TimeZoneHelper::wallSecToEpochSec($wallSec, TimeZoneHelper::normalizeTimezoneId($tz));
        $subNs =
            (self::bagTimeField($bag, 'millisecond', 999) * 1_000_000)
            + (self::bagTimeField($bag, 'microsecond', 999) * 1_000)
            + self::bagTimeField($bag, 'nanosecond', 999);
        if (
            $epochSec > EpochLimits::MAX_EPOCH_SECONDS
            || $epochSec < -EpochLimits::MAX_EPOCH_SECONDS
            || $epochSec === EpochLimits::MAX_EPOCH_SECONDS && $subNs > 0
        ) {
            throw new RangeError('relativeTo property bag is outside the representable range.');
        }
        return RelativeAnchor::onInstant($epochSec, $subNs);
    }

    /**
     * Reads an optional time field from a property bag, defaulting to zero.
     *
     * Values are finiteness-checked by {@see self::validatePropertyBag()} beforehand, so
     * what is left is ToIntegerWithTruncation and the clamp RegulateTime applies under
     * the overflow=constrain that ToRelativeTemporalObject asks for.
     *
     * @param array<array-key,mixed> $bag
     * @param int $max Largest value the field can hold.
     */
    private static function bagTimeField(array $bag, string $name, int $max): int
    {
        if (!array_key_exists($name, $bag)) {
            return 0;
        }
        return max(0, min($max, self::truncateToInteger($bag[$name])));
    }

    /**
     * Converts a ZonedDateTime to a year/month/day property bag.
     *
     * Uses the ZDT's epochNanoseconds and timezone offset to determine the local date.
     *
     * @return array{year: int, month: int, day: int}
     */
    public static function zdtToPlainDateBag(ZonedDateTime $zdt): array
    {
        // Compute local date from the TRUE epoch parts (sentinel-aware) + timezone
        // offset. Reading the clamped epochNanoseconds field would anchor over-int64
        // relativeTo instants at the year-2262 clamp instead of their real date.
        [$epochSec] = $zdt->epochParts();
        $tzId = $zdt->timeZoneId;
        $m = null;
        if ($tzId === 'UTC') {
            $offsetSec = 0;
        } elseif (preg_match('/^([+\-])(\d{2}):(\d{2})$/', $tzId, $m) === 1) {
            $sign = $m[1] === '+' ? 1 : -1;
            $offsetSec = $sign * (((int) $m[2] * 3600) + ((int) $m[3] * 60));
        } else {
            assert($tzId !== '', description: 'caller guarantees a non-empty timezone id for this branch');
            $tz = new \DateTimeZone($tzId);
            $offsetSec = $tz->getOffset(new \DateTimeImmutable(sprintf('@%d', $epochSec)));
        }
        $localSec = $epochSec + $offsetSec;
        $dt = new \DateTimeImmutable(sprintf('@%d', $localSec));
        return [
            'year' => (int) $dt->format('Y'),
            'month' => (int) $dt->format('n'),
            'day' => (int) $dt->format('j'),
        ];
    }

    /**
     * Extracts the ISO year/month/day anchor from a validated relativeTo property
     * bag. The bag is guaranteed to name a year — as 'year' or, on a calendar with
     * eras, as 'era' + 'eraYear' — plus 'month' or 'monthCode', and 'day' (see
     * {@see self::validatePropertyBag()}); its numeric fields have already been
     * checked for Infinity/NaN, so each value only needs ToIntegerWithTruncation — an
     * int passes through unchanged, every other finite numeric/coercible value goes
     * through PHP's `(int)` cast, which truncates toward zero exactly as the spec
     * requires. When only 'monthCode' is present the month number is the digits after
     * the leading 'M' (a trailing leap-marker 'L', as in "M05L", is dropped by the
     * `(int)` cast).
     *
     * @param array<array-key,mixed> $bag
     * @return array{int, int, int} [year, month, day]
     */
    public static function anchorYmd(array $bag): array
    {
        $year = self::anchorYear($bag);
        if (array_key_exists('month', $bag)) {
            $month = self::truncateToInteger($bag['month']);
        } else {
            /** @var mixed $monthCodeRaw */
            $monthCodeRaw = $bag['monthCode'];
            /** @phpstan-ignore cast.string */
            $month = (int) substr(string: is_string($monthCodeRaw) ? $monthCodeRaw : (string) $monthCodeRaw, offset: 1);
        }
        $day = self::truncateToInteger($bag['day']);
        return [$year, $month, $day];
    }

    /**
     * The year the anchor names: the 'year' field, or — on a calendar with eras —
     * the year its 'era'/'eraYear' pair resolves to. Mirrors {@see DateFields::fromBag()},
     * where a resolved era likewise wins over a 'year' handed in alongside it. The
     * pair resolves to null when either half is null — PHP's spelling of JS
     * `undefined`, which the spec reads as the field being absent.
     *
     * @param array<array-key,mixed> $bag
     * @throws RangeError if 'eraYear' is not a finite number.
     * @throws TypeError if 'era' cannot be coerced to a string.
     */
    private static function anchorYear(array $bag): int
    {
        $calendarId = self::bagCalendarId($bag);
        if ($calendarId !== null && CalendarMath::supportsEras($calendarId)) {
            $resolved = CalendarMath::resolveYearFromEra(
                CalendarFactory::get($calendarId),
                $bag['era'] ?? null,
                $bag['eraYear'] ?? null,
                'relativeTo',
            );
            if ($resolved !== null) {
                return $resolved;
            }
        }

        return self::truncateToInteger($bag['year']);
    }

    /**
     * Validates a relativeTo property bag.
     *
     * @param array<array-key,mixed> $rt
     * @throws TypeError if required fields (year or era+eraYear, month/monthCode, day) are missing.
     * @throws RangeError if a field value is out of range.
     */
    public static function validatePropertyBag(array $rt): void
    {
        // The calendar decides whether era/eraYear are fields at all, so it is read
        // before the presence check — as TC39 does, running
        // GetTemporalCalendarIdentifierWithISODefault ahead of PrepareCalendarFields.
        $calendarId = self::bagCalendarId($rt);
        $hasEraAndEraYear = CalendarMath::hasEraAndEraYear($rt, $calendarId, 'relativeTo');
        $hasYear = array_key_exists('year', $rt) || $hasEraAndEraYear && CalendarMath::supportsEras($calendarId);
        $hasMonth = array_key_exists('month', $rt) || array_key_exists('monthCode', $rt);
        $hasDay = array_key_exists('day', $rt);
        if (!$hasYear || !$hasMonth || !$hasDay) {
            throw new TypeError('relativeTo property bag must have year, month/monthCode, and day fields.');
        }
        // Validate Infinity/NaN in numeric fields.
        foreach ([
            'year',
            'eraYear',
            'month',
            'day',
            'hour',
            'minute',
            'second',
            'millisecond',
            'microsecond',
            'nanosecond',
        ] as $field) {
            if (!array_key_exists($field, $rt)) {
                continue;
            }
            /** @var mixed $v */
            $v = $rt[$field];
            if (is_float($v) && is_infinite($v)) {
                throw new RangeError("relativeTo field \"{$field}\" must be a finite number.");
            }
        }
        // timeZone: if present must be a string; null or non-string → TypeError.
        if (array_key_exists('timeZone', $rt)) {
            /** @var mixed $tzVal */
            $tzVal = $rt['timeZone'];
            if (!is_string($tzVal)) {
                throw new TypeError('relativeTo timeZone must be a string.');
            }
            self::validateTimeZoneString($tzVal);
        }
        // offset: if present must be a string in ±HH:MM[[:SS[.nnnnnnnnn]]] format
        // where optional seconds and sub-seconds must be zero.
        if (array_key_exists('offset', $rt)) {
            /** @var mixed $offVal */
            $offVal = $rt['offset'];
            if (!is_string($offVal)) {
                throw new TypeError('relativeTo offset must be a string.');
            }
            // Allow ±HH:MM or ±HH:MM:00[.000...] (seconds and sub-seconds zero).
            $offM = null;
            if (preg_match('/^([+\-])(\d{2}):(\d{2})(?::(\d{2})(?:\.(\d+))?)?$/', $offVal, $offM) !== 1) {
                throw new RangeError("Invalid relativeTo offset string \"{$offVal}\".");
            }
            // Reject non-zero seconds or sub-seconds.
            if (array_key_exists(4, $offM) && (int) $offM[4] !== 0) {
                throw new RangeError("Invalid relativeTo offset string \"{$offVal}\": non-zero seconds.");
            }
            if (array_key_exists(5, $offM) && ltrim($offM[5], characters: '0') !== '') {
                throw new RangeError("Invalid relativeTo offset string \"{$offVal}\": non-zero sub-seconds.");
            }
        }
    }

    /**
     * Parses a relativeTo ISO date string into a property bag.
     * Validates format, calendar, bracket offsets, and ZonedDateTime/PlainDate range limits.
     *
     * ZonedDateTime strings (have both an inline Z/offset AND a timezone bracket):
     *   - Local date must be ≥ -271821-04-20 (epoch-days ≥ −100 000 000).
     *   - UTC instant must be at midnight (offsetSec must exactly cancel localTimeSec mod 86400).
     *   - UTC instant must be within ±8 640 000 000 000 seconds.
     *
     * PlainDate strings (no inline offset or no timezone bracket):
     *   - Date must be within [−271821-04-19, +275760-09-13] (epoch-days in [−100 000 001, +100 000 000]).
     *
     * @return array<string,int|bool> Bag with 'year', 'month', 'day', '_epochDays', '_isZDT', '_utcSec', '_localTimeSec'.
     * @throws RangeError for invalid or unsupported strings.
     */
    public static function parseString(string $s): array
    {
        if ($s === '') {
            throw new RangeError('relativeTo string must not be empty.');
        }
        // Fractional hours: T12.5 or fractional minutes: T12:34.5 are not allowed.
        if (preg_match('/T\d{2}\.\d/', $s) === 1 || preg_match('/T\d{2}:\d{2}\.\d{1,3}(?:Z|[+\-\[]|$)/i', $s) === 1) {
            throw new RangeError('relativeTo string must not have fractional hours or minutes.');
        }
        // Validate calendar annotation.
        $calMatch = null;
        if (preg_match('/\[u-ca=([^\]]+)\]/', $s, $calMatch) === 1) {
            if (!CalendarFactory::isKnownCalendar($calMatch[1])) {
                throw new RangeError("Unknown calendar \"{$calMatch[1]}\".");
            }
        }
        // Reject minus-zero extended year (-000000).
        if (preg_match('/^-0{6}(?:[^0-9]|$)/', $s) === 1) {
            throw new RangeError('Cannot use negative zero as extended year.');
        }

        // Detect inline Z/offset and timezone bracket annotation.
        $hasInlineOffset = preg_match('/T\d{2}:?\d{2}(?::?\d{2}(?:\.\d+)?)?([+\-]|Z)/i', $s) === 1;
        $hasTzBracket = preg_match('/\[(?!u-ca=)[^\]]+\]/', $s) === 1;

        // TC39: ToTemporalRelativeTo:
        // - Z + no bracket → invalid (must have a timezone bracket for ZonedDateTime).
        // - Numeric offset with VALID format (±HH:MM[:SS]) + no bracket → treat as PlainDate.
        // - Numeric offset with INVALID format + no bracket → throw.
        if ($hasInlineOffset && !$hasTzBracket) {
            $hasZOffset = preg_match('/T\d{2}:?\d{2}(?::?\d{2}(?:\.\d+)?)?Z(?!\s*\[)/i', $s) === 1;
            if ($hasZOffset) {
                throw new RangeError(
                    "relativeTo string \"{$s}\" has a UTC (Z) offset but no timezone bracket annotation.",
                );
            }
            // Numeric offset: validate that the offset format is ±HH:MM[:SS[.frac]] followed by
            // end-of-string, '[', or whitespace (not extra digits).  Invalid formats (e.g. +00:0000) must throw.
            $offMatch = null;
            if (
                preg_match(
                    '/T\d{2}:?\d{2}(?::?\d{2}(?:\.\d+)?)?([+\-]\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?)(?:\[|$)/i',
                    $s,
                    $offMatch,
                ) !== 1
            ) {
                throw new RangeError("relativeTo string \"{$s}\" has an invalid UTC offset format.");
            }
            // Valid numeric offset without bracket: treat as PlainDate (ignore the time+offset part).
            $hasInlineOffset = false;
        }

        // Validate the timezone bracket annotation.
        $bracketMatch = null;
        if (preg_match('/\[([^\]]+)\]/', $s, $bracketMatch) === 1 && !str_starts_with($bracketMatch[1], 'u-ca=')) {
            $bracket = $bracketMatch[1];
            // Sub-minute bracket offset (has seconds component): invalid.
            if (preg_match('/^[+\-]\d{2}:\d{2}:\d{2}/', $bracket) === 1) {
                throw new RangeError('relativeTo string must not have sub-minute offset in bracket annotation.');
            }
            // Bracket is a numeric UTC offset (±HH:MM or ±HHMM): must match the inline offset
            // UNLESS the inline offset is Z (UTC instant — any timezone bracket is allowed).
            $bOff = null;
            if (preg_match('/^([+\-])(\d{2}):?(\d{2})$/', $bracket, $bOff) === 1) {
                $bMin = ((int) $bOff[2] * 60) + (int) $bOff[3];
                $bMin = $bOff[1] === '-' ? -$bMin : $bMin;
                $iOff = null;
                if (preg_match('/T\d{2}:?\d{2}(?::?\d{2})?([+\-]\d{2}:?\d{2}|Z)/i', $s, $iOff) === 1) {
                    if ($iOff[1] === 'Z' || $iOff[1] === 'z') {
                        // Z inline offset: any bracket timezone is allowed (no matching required).
                    } else {
                        $iOffParts = null;
                        preg_match('/^([+\-])(\d{2}):?(\d{2})/', $iOff[1], $iOffParts);
                        /**
                         * @var array{non-falsy-string, '+'|'-', non-falsy-string, non-falsy-string} $iOffParts
                         */
                        $iMin = ((int) $iOffParts[2] * 60) + (int) $iOffParts[3];
                        $iMin = $iOffParts[1] === '-' ? -$iMin : $iMin;
                        if ($bMin !== $iMin) {
                            throw new RangeError('relativeTo string bracket offset does not match inline UTC offset.');
                        }
                    }
                }
            } elseif (strtoupper($bracket) === 'UTC') {
                $iOff = null;
                if (preg_match('/T\d{2}:?\d{2}(?::?\d{2})?([+\-]\d{2}:?\d{2}|Z)/i', $s, $iOff) === 1) {
                    if ($iOff[1] !== 'Z' && $iOff[1] !== 'z') {
                        $iOffParts = null;
                        preg_match('/^([+\-])(\d{2}):?(\d{2})/', $iOff[1], $iOffParts);
                        /** @var array{non-falsy-string, '+'|'-', non-falsy-string, non-falsy-string} $iOffParts */
                        $iMin = ((int) $iOffParts[2] * 60) + (int) $iOffParts[3];
                        if ($iMin !== 0) {
                            throw new RangeError('relativeTo string bracket offset does not match inline UTC offset.');
                        }
                    }
                }
            }
        }

        // Extract date part: ±YYYY-MM-DD or YYYYMMDD.
        $dateMatch = null;
        if (
            preg_match('/^([+\-]?\d{4,6})-(\d{2})-(\d{2})/', $s, $dateMatch) !== 1
            && preg_match('/^(\d{4})(\d{2})(\d{2})/', $s, $dateMatch) !== 1
        ) {
            throw new RangeError("Invalid relativeTo date string \"{$s}\".");
        }
        $year = (int) $dateMatch[1];
        $month = (int) $dateMatch[2];
        $day = (int) $dateMatch[3];

        // Compute the proleptic Gregorian epoch-day count.
        $epochDays = AnchorMath::isoDateToEpochDays($year, $month, $day);

        // Defaults for the extended return metadata (set inside ZDT branch only).
        $localTimeSec = 0;
        $hasFracSec = false;
        $utcSec = 0;

        if ($hasInlineOffset) {
            // ZonedDateTime string: validate local date range.

            // Local date must be at or after -271821-04-20 (epochDays ≥ -100 000 000).
            if ($epochDays < -100_000_000) {
                throw new RangeError(
                    "relativeTo ZonedDateTime \"{$s}\" local date is before the minimum (-271821-04-20).",
                );
            }

            // Extract local time (hours, minutes, seconds) and detect sub-second fraction.
            $tm = null;
            if (preg_match('/T(\d{2}):?(\d{2})(?::?(\d{2})(\.\d+)?)?/i', $s, $tm) === 1) {
                $localTimeSec =
                    ((int) $tm[1] * 3_600) + ((int) $tm[2] * 60) + (array_key_exists(3, $tm) ? (int) $tm[3] : 0);
                // @phpstan-ignore notIdentical.alwaysTrue
                $hasFracSec = array_key_exists(4, $tm) && $tm[4] !== '';
            }

            // Extract the inline UTC offset in seconds.
            $offsetSec = 0;
            $iOff = null;
            if (preg_match('/T\d{2}:?\d{2}(?::?\d{2}(?:\.\d+)?)?([+\-]\d{2}:?\d{2}|Z)/i', $s, $iOff) === 1) {
                if ($iOff[1] !== 'Z' && $iOff[1] !== 'z') {
                    $offParts = null;
                    preg_match('/^([+\-])(\d{2}):?(\d{2})/', $iOff[1], $offParts);
                    /** @var array{non-falsy-string, '+'|'-', non-falsy-string, non-falsy-string} $offParts */
                    $offsetSec = ((int) $offParts[2] * 3_600) + ((int) $offParts[3] * 60);
                    if ($offParts[1] === '-') {
                        $offsetSec = -$offsetSec;
                    }
                }
            }

            // Sub-second fractional components are not allowed.
            if ($hasFracSec) {
                throw new RangeError("relativeTo ZonedDateTime \"{$s}\" has a sub-second component.");
            }

            // Compute UTC instant.
            $utcSec = ($epochDays * 86_400) + $localTimeSec - $offsetSec;

            // UTC instant must be within ±8 640 000 000 000 seconds.
            if ($utcSec > EpochLimits::MAX_EPOCH_SECONDS || $utcSec < -EpochLimits::MAX_EPOCH_SECONDS) {
                throw new RangeError(
                    "relativeTo ZonedDateTime \"{$s}\" UTC instant is outside the representable range.",
                );
            }
        } else {
            // PlainDate string: valid range is [-271821-04-19, +275760-09-13]
            // (epoch-days in [-100 000 001, +100 000 000]).
            if ($epochDays < -100_000_001 || $epochDays > 100_000_000) {
                throw new RangeError("relativeTo PlainDate \"{$s}\" is outside the representable range.");
            }
        }

        $isZDT = $hasInlineOffset;
        return [
            'year' => $year,
            'month' => $month,
            'day' => $day,
            '_epochDays' => $epochDays,
            '_isZDT' => $isZDT,
            '_utcSec' => $utcSec,
            '_localTimeSec' => $localTimeSec,
        ];
    }

    /**
     * Resolves a relativeTo value to ZDT info if it represents a ZonedDateTime with
     * an IANA timezone (i.e. one that may observe DST). Returns null for PlainDate,
     * UTC, or fixed-offset timezones.
     *
     * @return null|array{epochSec: int, subNs: int, tzId: string, year: int, month: int, day: int, hour: int, minute: int, second: int}
     */
    public static function resolveZdt(mixed $rt): ?array
    {
        // Normalize plain-object property bags (but keep Temporal instances intact).
        if (is_object($rt) && !$rt instanceof ZonedDateTime && !$rt instanceof PlainDate) {
            $rt = self::normalizeBag($rt);
        }
        if ($rt instanceof ZonedDateTime) {
            $tzId = $rt->timeZoneId;
            if ($tzId === '' || $tzId === 'UTC' || preg_match('/^[+\-]\d{2}:\d{2}$/', $tzId) === 1) {
                return null;
            }
            // Read the TRUE epoch parts (sentinel-aware) rather than the clamped
            // epochNanoseconds field, so over-int64 relativeTo anchors resolve to
            // their real calendar date instead of the year-2262 clamp.
            [$epochSec, $subNs] = $rt->epochParts();
            // Compute local components via offset.
            $tz = new \DateTimeZone($tzId);
            $offsetSec = $tz->getOffset(new \DateTimeImmutable(sprintf('@%d', $epochSec)));
            $localSec = $epochSec + $offsetSec;
            $dt = new \DateTimeImmutable(sprintf('@%d', $localSec));
            return [
                'epochSec' => $epochSec,
                'subNs' => $subNs,
                'tzId' => $tzId,
                'year' => (int) $dt->format('Y'),
                'month' => (int) $dt->format('n'),
                'day' => (int) $dt->format('j'),
                'hour' => (int) $dt->format('G'),
                'minute' => (int) $dt->format('i'),
                'second' => (int) $dt->format('s'),
            ];
        }
        if (is_string($rt)) {
            // Parse the string to check for IANA timezone.
            $m = null;
            if (preg_match('/\[([^\]=]+)\]\s*$/', $rt, $m) === 1) {
                $tzId = $m[1];
                if ($tzId !== 'UTC' && preg_match('/^[+\-]\d{2}:\d{2}$/', $tzId) !== 1) {
                    // It's an IANA timezone string. Construct a ZDT and recurse.
                    $zdt = ZonedDateTime::from($rt);
                    return self::resolveZdt($zdt);
                }
            }
            return null;
        }
        if (is_array($rt) && array_key_exists('timeZone', $rt)) {
            /** @var mixed $tzVal */
            $tzVal = $rt['timeZone'];
            // Only treat as IANA timezone if it looks like one (contains '/' or is a known single-word zone).
            // Datetime strings passed as timeZone (e.g. "2021-08-19T17:30-0700") are not IANA.
            $isIanaTz = false;
            if (
                is_string($tzVal)
                && $tzVal !== ''
                && $tzVal !== 'UTC'
                && preg_match('/^[+\-]\d{2}:\d{2}$/', $tzVal) !== 1
                && !str_contains($tzVal, 'T')
                && preg_match('/^\d{4}-/', $tzVal) !== 1
            ) {
                try {
                    $isIanaTz = new \DateTimeZone($tzVal)->getName() === $tzVal;
                } catch (\Exception) {
                    $isIanaTz = false;
                }
            }
            if ($isIanaTz) {
                // Property bag with IANA timezone. Use ZonedDateTime::from() so offset
                // validation (including sub-minute mismatch rejection) is handled.
                $zdt = ZonedDateTime::from($rt);
                return self::resolveZdt($zdt);
            }
            return null;
        }
        return null;
    }

    /**
     * Tests whether anchoring the given duration to a ZonedDateTime epoch
     * (epochSec, subNs) lands outside the representable Temporal range
     * (±8.64e21 ns ≙ ±8.64e12 s). The comparison is done in (seconds, sub-ns)
     * integer space so that an over-int64 epoch plus a one-nanosecond duration
     * is detected exactly — float seconds would lose the +1 ns at this scale.
     *
     * Only the time-and-day fields contribute (calendar fields are handled on
     * the calendar paths before this is reached).
     */
    public static function zdtTargetOutOfRange(int $epochSec, int $subNs, Duration $d): bool
    {
        // Duration contribution as whole seconds + residual nanoseconds.
        // Day/hour/minute/second are whole-second contributions; ms/µs/ns are sub-second.
        $durSec =
            ((float) $d->days * 86_400.0)
            + ((float) $d->hours * 3_600.0)
            + ((float) $d->minutes * 60.0)
            + (float) $d->seconds;
        $durNs =
            ((float) $d->milliseconds * 1_000_000.0) + ((float) $d->microseconds * 1_000.0) + (float) $d->nanoseconds;

        // Fold whole seconds out of the nanosecond residual.
        $carrySec = floor($durNs / 1_000_000_000.0);
        $durSec += $carrySec;
        $residualNs = $durNs - ($carrySec * 1_000_000_000.0);

        // Target = (epochSec + durSec) seconds and (subNs + residualNs) sub-seconds.
        $targetSec = (float) $epochSec + $durSec;
        $targetSubNs = (float) $subNs + $residualNs;
        $carry = floor($targetSubNs / 1_000_000_000.0);
        $targetSec += $carry;
        $targetSubNs -= $carry * 1_000_000_000.0;

        // Out of range when target > (8.64e12 s, 0 ns) or < (-8.64e12 s, 0 ns).
        if ($targetSec > EpochLimits::MAX_EPOCH_SECONDS) {
            return true;
        }
        if ($targetSec === (float) EpochLimits::MAX_EPOCH_SECONDS && $targetSubNs > 0.0) {
            return true;
        }
        // The floor-based carry leaves $targetSubNs in [0, 1e9), so exactly -MAX seconds is
        // always in range — hence no sub-nanosecond mirror of the +MAX check above.
        if ($targetSec < -EpochLimits::MAX_EPOCH_SECONDS) {
            return true;
        }

        return false;
    }

    /**
     * The canonical id of the calendar a property bag names, or null when it names
     * none (which leaves the anchor on ISO 8601).
     *
     * TC39 ToTemporalCalendarSlotValue: a calendar in a property bag may be
     * a Temporal object with a calendarId slot (fast path: read the slot directly),
     * or a string / Stringable (coerce via ToString, then validate). Any other type
     * is a TypeError; only an unknown calendar string is a RangeError, raised by
     * canonicalize().
     *
     * @param array<array-key,mixed> $bag
     * @throws TypeError if the calendar field is present but not string-like.
     * @throws RangeError if it names an unknown calendar.
     */
    private static function bagCalendarId(array $bag): ?string
    {
        if (!array_key_exists('calendar', $bag)) {
            return null;
        }
        /** @var mixed $calVal */
        $calVal = $bag['calendar'];
        if (is_object($calVal) && property_exists($calVal, 'calendarId') && is_string($calVal->calendarId)) {
            $calVal = $calVal->calendarId;
        } elseif ($calVal instanceof \Stringable) {
            $calVal = (string) $calVal;
        }
        if (!is_string($calVal)) {
            throw new TypeError('relativeTo calendar must be a string.');
        }

        return CalendarFactory::canonicalize($calVal);
    }

    /**
     * TC39 ToIntegerWithTruncation for an already-finiteness-validated property-bag
     * field: an int passes through unchanged, every other finite numeric/coercible
     * value goes through PHP's `(int)` cast, which truncates toward zero exactly as
     * the spec requires. Used only by {@see self::anchorYmd()} and {@see self::anchorYear()}.
     */
    private static function truncateToInteger(mixed $value): int
    {
        /** @phpstan-ignore cast.int */
        return is_int($value) ? $value : (int) $value;
    }

    /**
     * Validates a timezone identifier string (used for the timeZone property-bag field).
     *
     * Rules (from TC39 Temporal spec):
     *   - Minus-zero extended year (-000000) → reject.
     *   - Bracket annotation with a seconds offset (e.g. [+23:59:60]) → reject.
     *   - Pure UTC-offset strings (start with ±HH, no T): must be ±HH:MM or ±HHMM (no seconds).
     *   - Datetime strings (contain T): must have Z, an inline offset, or a bracket annotation;
     *     inline offsets must not include a seconds component (e.g. -07:00:01 is invalid).
     *
     * @throws RangeError for invalid timezone strings.
     */
    private static function validateTimeZoneString(string $tz): void
    {
        // Reject empty string.
        if ($tz === '') {
            throw new RangeError('Invalid timeZone "": empty string is not a valid timezone identifier.');
        }
        // Reject minus-zero extended year.
        if (preg_match('/^-0{6}(?:[^0-9]|$)/', $tz) === 1) {
            throw new RangeError("Invalid timeZone \"{$tz}\": minus-zero year.");
        }
        // Reject bracket annotation with a seconds component (e.g. [+23:59:60]).
        $bm = null;
        if (preg_match('/\[([^\]]+)\]/', $tz, $bm) === 1) {
            if (preg_match('/^[+\-]\d{2}:\d{2}:\d{2}/', $bm[1]) === 1) {
                throw new RangeError("Invalid timeZone \"{$tz}\": sub-minute seconds in bracket annotation.");
            }
        }
        // Pure UTC-offset strings (no T date/time part): must be ±HH:MM or ±HHMM.
        if (preg_match('/^[+\-]\d{2}/', $tz) === 1 && !str_contains($tz, 'T') && !str_contains($tz, 't')) {
            if (preg_match('/^[+\-]\d{2}:\d{2}(?:$|[^:\d])/', $tz) !== 1 && preg_match('/^[+\-]\d{4}$/', $tz) !== 1) {
                throw new RangeError("Invalid timeZone \"{$tz}\": offset contains seconds or is in an invalid format.");
            }
            return;
        }
        // Datetime strings: must have Z, an inline offset, or a bracket annotation.
        if (preg_match('/\d{4,}-\d{2}-\d{2}[Tt]|\d{8}[Tt]/', $tz) === 1) {
            if (preg_match('/T\d{2}:?\d{2}(?::?\d{2})?(?:\.\d+)?(?:Z|[+\-]|\[)/i', $tz) !== 1) {
                throw new RangeError("Invalid timeZone \"{$tz}\": bare datetime without Z, offset, or bracket.");
            }
            // Inline offset must not include a seconds component (e.g. -07:00:01).
            if (preg_match('/[+\-]\d{2}:\d{2}:\d{2}(?!\])/i', $tz) === 1) {
                throw new RangeError("Invalid timeZone \"{$tz}\": inline offset contains a seconds component.");
            }
        }
    }
}
