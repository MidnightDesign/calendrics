<?php

declare(strict_types=1);

namespace Calendrics\Spec\Internal;

use Calendrics\Exception\RangeError;
use Calendrics\Exception\TypeError;
use Calendrics\Spec\Internal\Calendar\CalendarFactory;
use Calendrics\Spec\ZonedDateTime;

/**
 * Building a `ZonedDateTime` from calendar fields rather than from an instant.
 *
 * Two entry points share this file because they share the hard part — turning a local
 * wall-clock date and time into an epoch second, which is a lookup that can have zero
 * answers (the hour a spring-forward skips) or two (the hour a fall-back repeats):
 *
 *   - {@see fromBag()} builds from a property bag, the `from()` path; and
 *   - {@see fromLocal()} builds from already-resolved ISO components, the path
 *     `with()` and calendar-unit arithmetic land on.
 *
 * The read order in {@see fromBag()} is load-bearing, not incidental. TC39 fixes when
 * each field is touched and which error each failure raises, and a bag may be backed by
 * accessors that throw, so the sequence is observable: calendar, then the required-field
 * presence checks, then `monthCode`/`offset` *syntax* (a malformed `monthCode` is a
 * RangeError, a non-string one a TypeError), then the `year` *type*, and only then
 * `monthCode` suitability and offset-versus-zone matching. Moving any of those earlier or
 * later changes which exception a caller sees.
 *
 * @internal
 */
final class ZonedFields
{
    /**
     * The calendar fields a ZonedDateTime is built from, as passed to PrepareCalendarFields.
     *
     * `era`/`eraYear` are CalendarExtraFields, added by {@see FieldBag} only for calendars
     * that have eras; `offset`/`timeZone` are non-calendar fields, supplied per call site.
     *
     * @var list<string>
     */
    public const array CALENDAR_FIELDS = [
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
     * Builds a ZonedDateTime from a property bag.
     *
     * @param array<array-key, mixed> $bag Prepared calendar fields plus `timeZone` and optionally `offset`.
     * @throws TypeError  if a required field is absent or a field has the wrong type.
     * @throws RangeError if a field value is out of range, or `offset` contradicts the zone
     *                    under `offsetOption: 'reject'`.
     */
    public static function fromBag(
        array $bag,
        string $overflow = 'constrain',
        string $disambiguation = 'compatible',
        string $offsetOption = 'reject',
    ): ZonedDateTime {
        // The calendar is validated before the required-field checks, so a bad calendar
        // reports as such even on a bag that is also missing a year.
        $calendarId = 'iso8601';
        if (array_key_exists('calendar', $bag)) {
            $calendarId = CalendarFactory::resolveBagCalendar($bag['calendar'], 'ZonedDateTime');
        }

        if (!array_key_exists('timeZone', $bag)) {
            throw new TypeError('ZonedDateTime property bag must have a timeZone field.');
        }
        /** @var mixed $tzRaw */
        $tzRaw = $bag['timeZone'];
        // ToTemporalTimeZoneIdentifier: a ZonedDateTime contributes its [[TimeZone]] slot
        // directly; anything else must be a string.
        if ($tzRaw instanceof ZonedDateTime) {
            $tzRaw = $tzRaw->timeZoneId;
        } elseif (!is_string($tzRaw)) {
            throw new TypeError('ZonedDateTime timeZone must be a string.');
        }

        $hasEraAndEraYear = CalendarMath::hasEraAndEraYear($bag, $calendarId, 'ZonedDateTime');
        $calendarSupportsEras = CalendarMath::supportsEras($calendarId);

        if (!array_key_exists('year', $bag) && (!$hasEraAndEraYear || !$calendarSupportsEras)) {
            throw new TypeError('ZonedDateTime property bag must have a year field.');
        }
        if (!array_key_exists('day', $bag)) {
            throw new TypeError('ZonedDateTime property bag must have a day field.');
        }
        if (!array_key_exists('month', $bag) && !array_key_exists('monthCode', $bag)) {
            throw new TypeError('ZonedDateTime property bag must have a month or monthCode field.');
        }

        /** @var int|float|string|null $yr */
        $yr = $bag['year'] ?? null;
        /** @var int|float|string $dy */
        $dy = $bag['day'];
        /** @var int|float|string $hr */
        $hr = $bag['hour'] ?? 0;
        /** @var int|float|string $mn */
        $mn = $bag['minute'] ?? 0;
        /** @var int|float|string $sc */
        $sc = $bag['second'] ?? 0;
        /** @var int|float|string $ms */
        $ms = $bag['millisecond'] ?? 0;
        /** @var int|float|string $us */
        $us = $bag['microsecond'] ?? 0;
        /** @var int|float|string $ns */
        $ns = $bag['nanosecond'] ?? 0;

        self::validateMonthCodeSyntax($bag);
        self::validateOffsetSyntax($bag);

        $numericFields = [
            'day' => $dy,
            'hour' => $hr,
            'minute' => $mn,
            'second' => $sc,
            'millisecond' => $ms,
            'microsecond' => $us,
            'nanosecond' => $ns,
        ];
        if ($yr !== null) {
            $numericFields['year'] = $yr;
        }
        /** @psalm-suppress MixedAssignment — values are typed mixed via the @var annotations above */
        foreach ($numericFields as $fname => $fval) {
            if (is_float($fval) && is_infinite($fval)) {
                throw new RangeError(sprintf(
                    'ZonedDateTime %s must be finite; got %s.',
                    $fname,
                    $fval > 0 ? 'INF' : '-INF',
                ));
            }
        }

        // Coercing year through toFiniteInt is what makes a non-coercible year (a Symbol,
        // say) a TypeError here — between the syntax RangeErrors above and the suitability
        // RangeErrors below, which is the ordering the fixtures pin down.
        $year = $yr !== null ? CalendarMath::toFiniteInt($yr, 'ZonedDateTime year') : 0;
        $day = intval($dy);
        $hour = intval($hr);
        $minute = intval($mn);
        $second = intval($sc);
        $milli = intval($ms);
        $micro = intval($us);
        $nano = intval($ns);

        $calendar = $calendarId !== 'iso8601' ? CalendarFactory::get($calendarId) : null;

        if ($calendar !== null && array_key_exists('era', $bag) && array_key_exists('eraYear', $bag)) {
            $resolved = CalendarMath::resolveYearFromEra($calendar, $bag['era'], $bag['eraYear'], 'ZonedDateTime');
            if ($resolved !== null) {
                $year = $resolved;
            }
        }

        $monthCode = null;
        $month = null;
        $hasMonth = array_key_exists('month', $bag);
        $hasMC = array_key_exists('monthCode', $bag);

        if ($hasMC) {
            /** @var string $mc — well-formedness established by validateMonthCodeSyntax() */
            $mc = $bag['monthCode'];
            $monthCode = $mc;
            $month = $calendar !== null ? $calendar->monthCodeToMonth($mc, $year) : CalendarMath::monthCodeToMonth($mc);
        }
        if ($hasMonth) {
            $newMonth = CalendarMath::toFiniteInt($bag['month'] ?? null, 'ZonedDateTime month');
            if ($hasMC && $newMonth !== $month) {
                throw new RangeError('Conflicting month and monthCode fields.');
            }
            $month = $newMonth;
        }
        /** @var int $month */

        if ($month < 1) {
            throw new RangeError("Invalid month {$month}: must be at least 1.");
        }
        if ($day < 1) {
            throw new RangeError("Invalid day {$day}: must be at least 1.");
        }

        if ($calendar !== null) {
            [$year, $month, $day] = $monthCode !== null
                ? $calendar->calendarToIsoFromMonthCode($year, $monthCode, $day, $overflow)
                : $calendar->calendarToIso($year, $month, $day, $overflow);
        } elseif ($overflow === 'constrain') {
            /** @psalm-suppress UnnecessaryVarAnnotation — Mago can't narrow min() */
            $month = min(12, $month);
            $day = min(CalendarMath::calcDaysInMonth($year, $month), $day);
        } else {
            if ($month > 12) {
                throw new RangeError("Invalid month {$month}: must be 1–12.");
            }
            $maxDay = CalendarMath::calcDaysInMonth($year, $month);
            if ($day > $maxDay) {
                throw new RangeError("Invalid day {$day}: exceeds {$maxDay} for {$year}-{$month}.");
            }
        }

        if ($overflow === 'constrain') {
            $hour = max(0, min(23, $hour));
            $minute = max(0, min(59, $minute));
            $second = max(0, min(59, $second));
            $milli = max(0, min(999, $milli));
            $micro = max(0, min(999, $micro));
            $nano = max(0, min(999, $nano));
        }

        // Julian-day arithmetic rather than DateTimeImmutable, which cannot represent
        // years past ~9999 or negative years reliably.
        $epochDays = CalendarMath::toJulianDay($year, $month, $day) - 2_440_588;
        $wallSec = ($epochDays * 86_400) + ($hour * 3600) + ($minute * 60) + $second;
        if ($wallSec > EpochLimits::MAX_EPOCH_SECONDS || $wallSec < -EpochLimits::MAX_EPOCH_SECONDS) {
            throw new RangeError('ZonedDateTime property bag: local date-time is outside the representable range.');
        }

        $normalTzId = TimeZoneHelper::normalizeTimezoneId($tzRaw);
        $resolvedTzId = ZoneOffsets::canonicalize($normalTzId);
        $epochSec = TimeZoneHelper::wallSecToEpochSec($wallSec, $resolvedTzId, $disambiguation);
        $subNs = ($milli * EpochLimits::NS_PER_MILLISECOND) + ($micro * EpochLimits::NS_PER_MICROSECOND) + $nano;

        if (array_key_exists('offset', $bag) && $offsetOption !== 'ignore') {
            /** @var string $offRaw — syntax established by validateOffsetSyntax() */
            $offRaw = $bag['offset'];
            $givenOffsetSec = self::offsetStringToSeconds($offRaw);

            if ($offsetOption === 'use') {
                $epochSec = $wallSec - $givenOffsetSec;
            } else {
                $epochFromOffset = $wallSec - $givenOffsetSec;
                if (ZoneOffsets::offsetAt($epochFromOffset, $resolvedTzId) === $givenOffsetSec) {
                    $epochSec = $epochFromOffset;
                } elseif ($offsetOption === 'reject') {
                    throw new RangeError(
                        "The offset {$offRaw} does not match the timezone {$normalTzId} offset at the given instant.",
                    );
                }

                // 'prefer': keep the disambiguation-resolved epochSec.
            }
        }

        return ZonedDateTime::fromEpochParts($epochSec, $subNs, $normalTzId, $calendarId);
    }

    /**
     * Builds a ZonedDateTime from resolved local ISO date and time components.
     *
     * Uses Julian-day arithmetic to reach wall-clock seconds so extreme years survive, then
     * hands the wall clock to the zone, where $disambiguation decides what a skipped or
     * repeated local time resolves to.
     */
    public static function fromLocal(
        int $year,
        int $month,
        int $day,
        int $h,
        int $min,
        int $sec,
        int $ms,
        int $us,
        int $ns,
        string $tzId,
        string $calendarId,
        string $disambiguation,
    ): ZonedDateTime {
        $epochDays = CalendarMath::toJulianDay($year, $month, $day) - 2_440_588;
        $wallSec = ($epochDays * 86_400) + ($h * 3600) + ($min * 60) + $sec;
        $epochSec = TimeZoneHelper::wallSecToEpochSec($wallSec, ZoneOffsets::canonicalize($tzId), $disambiguation);
        $subNs = ($ms * EpochLimits::NS_PER_MILLISECOND) + ($us * EpochLimits::NS_PER_MICROSECOND) + $ns;

        return ZonedDateTime::fromEpochParts($epochSec, $subNs, $tzId, $calendarId);
    }

    /**
     * Converts a validated `±HH:MM` / `±HH:MM:SS` offset string to signed seconds.
     */
    public static function offsetStringToSeconds(string $offset): int
    {
        $sign = $offset[0] === '+' ? 1 : -1;
        $parts = explode(separator: ':', string: substr(string: $offset, offset: 1));
        return (
            $sign
            * (((int) $parts[0] * 3600) + ((int) $parts[1] * 60) + (array_key_exists(2, $parts) ? (int) $parts[2] : 0))
        );
    }

    /**
     * GetOptionsObject for `ZonedDateTime::from()`: reads the three recognized options
     * once, in the spec's alphabetical order, and validates each keyword.
     *
     * All three branches of ToTemporalZonedDateTime share this step but reach it at
     * different points, so it lives apart from the branches themselves: a property bag
     * is read first (PrepareCalendarFields precedes GetOptionsObject), a string is
     * parsed first ({@see ZonedParse::parse()} calls this only once the text has
     * parsed), and an existing ZonedDateTime reaches it straight away.
     *
     * Returns the snapshot with each keyword replaced by its coerced string, so callers
     * resolve values from it without touching the original bag — or re-running ToString
     * on a value that supplies it through an accessor — a second time.
     *
     * @return array<array-key, mixed>
     * @throws RangeError if a keyword is present but not one of its recognized values.
     */
    public static function fromOptions(mixed $options): array
    {
        $opts = Options::normalizeOptions($options, ['disambiguation', 'offset', 'overflow']);

        if (array_key_exists('disambiguation', $opts)) {
            $dv = Options::coerceEnumOption($opts['disambiguation'], 'disambiguation');
            if (!in_array(needle: $dv, haystack: ['compatible', 'earlier', 'later', 'reject'], strict: true)) {
                throw new RangeError(
                    "Invalid disambiguation value \"{$dv}\"; must be 'compatible', 'earlier', 'later', or 'reject'.",
                );
            }
            $opts = array_merge($opts, ['disambiguation' => $dv]);
        }

        if (array_key_exists('overflow', $opts)) {
            $opts = array_merge($opts, ['overflow' => Options::overflowOption($opts['overflow'])]);
        }

        if (array_key_exists('offset', $opts)) {
            /** @var mixed $offOpt */
            $offOpt = $opts['offset'];
            if ($offOpt !== null) {
                $offOpt = Options::coerceEnumOption($offOpt, 'offset');
                if (!in_array(needle: $offOpt, haystack: ['use', 'ignore', 'prefer', 'reject'], strict: true)) {
                    throw new RangeError(
                        "Invalid offset option \"{$offOpt}\"; must be 'use', 'ignore', 'prefer', or 'reject'.",
                    );
                }
                $opts = array_merge($opts, ['offset' => $offOpt]);
            }
        }

        return $opts;
    }

    /**
     * Resolves the `disambiguation` option, defaulting to `'compatible'`.
     *
     * @param array<array-key, mixed>|object|null $options
     * @throws RangeError if the value is not one of the four keywords.
     */
    public static function disambiguationFromBag(array|object|null $options): string
    {
        if ($options === null) {
            return 'compatible';
        }
        $options = Options::normalizeOptions($options, ['disambiguation']);
        if (!array_key_exists('disambiguation', $options)) {
            return 'compatible';
        }
        /** @var mixed $val */
        $val = $options['disambiguation'];
        $val = Options::coerceEnumOption($val, 'disambiguation');
        if (!in_array(needle: $val, haystack: ['compatible', 'earlier', 'later', 'reject'], strict: true)) {
            throw new RangeError(
                "Invalid disambiguation value \"{$val}\"; must be 'compatible', 'earlier', 'later', or 'reject'.",
            );
        }
        return $val;
    }

    /**
     * Checks that a `monthCode`, if present, is a string of the form `M` + two digits +
     * an optional leap `L`.
     *
     * Only well-formedness — whether the code names a month that exists in the calendar's
     * year is settled later, once the year has been coerced.
     *
     * @param array<array-key, mixed> $bag
     */
    private static function validateMonthCodeSyntax(array $bag): void
    {
        if (!array_key_exists('monthCode', $bag)) {
            return;
        }
        /** @var mixed $raw */
        $raw = $bag['monthCode'];
        if (!is_string($raw)) {
            throw new TypeError('ZonedDateTime monthCode must be a string.');
        }
        if (preg_match('/^M(\d{2})(L?)$/', $raw) !== 1) {
            throw new RangeError("Invalid monthCode for ISO calendar: \"{$raw}\".");
        }
    }

    /**
     * Checks that an `offset`, if present, is a `±HH:MM` or `±HH:MM:SS` string.
     *
     * Runs even under `offsetOption: 'ignore'` — the field must be well-formed whether or
     * not its value ends up being used.
     *
     * @param array<array-key, mixed> $bag
     */
    private static function validateOffsetSyntax(array $bag): void
    {
        if (!array_key_exists('offset', $bag)) {
            return;
        }
        /** @var mixed $raw */
        $raw = $bag['offset'];
        if (!is_string($raw)) {
            throw new TypeError('ZonedDateTime offset must be a string.');
        }
        if (preg_match('/^[+-]\d{2}:\d{2}(:\d{2})?$/', $raw) !== 1) {
            throw new RangeError("Invalid offset string \"{$raw}\": must be ±HH:MM or ±HH:MM:SS.");
        }
    }
}
