<?php

declare(strict_types=1);

namespace Temporal\Spec\Internal;

use Temporal\Exception\RangeError;
use Temporal\Exception\TypeError;
use Temporal\Spec\Duration;
use Temporal\Spec\Instant;
use Temporal\Spec\Internal\Calendar\CalendarFactory;
use Temporal\Spec\PlainDate;
use Temporal\Spec\PlainDateTime;
use Temporal\Spec\PlainMonthDay;
use Temporal\Spec\PlainTime;
use Temporal\Spec\PlainYearMonth;
use Temporal\Spec\ZonedDateTime;

/**
 * Turns a property bag of calendar fields into a ZonedDateTime — the shared machinery
 * behind `ZonedDateTime::from(bag)` and `ZonedDateTime::with()`.
 *
 * The two entry points differ only in where the unmentioned fields come from: `from()`
 * starts empty and demands year, month-or-monthCode, day and timeZone, while `with()`
 * starts from an existing value and overlays whatever the partial bag names. Everything
 * after that is the same problem, and each piece of it has an ordering the spec pins down:
 *
 *   - Field reads happen in a fixed sequence, because an observable getter must see the
 *     same call order TC39 specifies. That is why monthCode and offset SYNTAX is checked
 *     while the field is read, before the year value is coerced, and monthCode
 *     SUITABILITY only afterwards.
 *   - `month` and `monthCode` may both be present and must then agree; on a non-ISO
 *     calendar the comparison itself needs a resolved year, so it cannot move earlier.
 *   - A stated `offset` can contradict the zone. The `offset` option decides:
 *     'use' takes the offset verbatim, 'ignore' discards it, 'reject' demands agreement,
 *     'prefer' keeps it only when it is valid at the resulting instant. Only once that is
 *     settled does disambiguation get to resolve a DST gap or fold.
 *
 * This class lives in `Temporal\Spec\Internal\` and is therefore not part of the public
 * BC contract. Signatures, behavior, and existence may change between any two releases.
 * External code must not depend on it.
 */
final class ZonedDateTimeFields
{
    /**
     * The calendar fields a ZonedDateTime is built from, as passed to
     * PrepareCalendarFields. `era`/`eraYear` are CalendarExtraFields, added by
     * {@see FieldBag} only for calendars that have eras; `offset`/`timeZone` are
     * non-calendar fields, supplied per call site.
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
     * Creates a ZonedDateTime from a property-bag array.
     *
     * Required fields: a datetime bag (year/month/day/…), timeZone.
     *
     * @param array<array-key, mixed> $bag
     * @throws TypeError              if required fields are missing or wrong type.
     * @throws RangeError if values are invalid.
     */
    public static function fromBag(
        array $bag,
        string $overflow = 'constrain',
        string $disambiguation = 'compatible',
        string $offsetOption = 'reject',
    ): ZonedDateTime {
        // Validate calendar first (spec validates calendar before required fields).
        $calendarId = 'iso8601';
        if (array_key_exists('calendar', $bag)) {
            $calendarId = CalendarFactory::resolveBagCalendar($bag['calendar'], 'ZonedDateTime');
        }

        // Must have a timeZone key.
        if (!array_key_exists('timeZone', $bag)) {
            throw new TypeError('ZonedDateTime property bag must have a timeZone field.');
        }
        /** @var mixed $tzRaw */
        $tzRaw = $bag['timeZone'];
        // Per TC39 ToTemporalTimeZoneIdentifier: a ZonedDateTime instance contributes its
        // [[TimeZone]] slot directly. Otherwise the value must be a string.
        if ($tzRaw instanceof ZonedDateTime) {
            $tzRaw = $tzRaw->timeZoneId;
        } elseif (!is_string($tzRaw)) {
            throw new TypeError('ZonedDateTime timeZone must be a string.');
        }

        // Expect year/month/day/hour/minute/second fields.
        $hasEraAndEraYear = CalendarMath::hasEraAndEraYear($bag, $calendarId, 'ZonedDateTime');
        $calendarSupportsEras = CalendarMath::supportsEras($calendarId);

        if (!array_key_exists('year', $bag) && (!$hasEraAndEraYear || !$calendarSupportsEras)) {
            throw new TypeError('ZonedDateTime property bag must have a year field.');
        }
        if (!array_key_exists('day', $bag)) {
            throw new TypeError('ZonedDateTime property bag must have a day field.');
        }

        // month can come from 'month' or 'monthCode'.
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

        // monthCode and offset SYNTAX (well-formedness) are validated before the
        // year field's TYPE is coerced (per TC39 PrepareCalendarFields ordering:
        // monthCode/offset string syntax is checked while reading the field, before
        // the year value is converted). A non-string monthCode is itself a TypeError;
        // a malformed monthCode FORMAT string is a RangeError. monthCode SUITABILITY
        // (whether the well-formed code names a real month) is validated later, after
        // the year type has been checked.
        if (array_key_exists('monthCode', $bag)) {
            /** @var mixed $mcRaw */
            $mcRaw = $bag['monthCode'];
            if (!is_string($mcRaw)) {
                throw new TypeError('ZonedDateTime monthCode must be a string.');
            }
            // Well-formed monthCode syntax: 'M' + two digits + optional leap 'L'.
            if (preg_match('/^M(\d{2})(L?)$/', $mcRaw) !== 1) {
                throw new RangeError("Invalid monthCode for ISO calendar: \"{$mcRaw}\".");
            }
        }

        // offset field SYNTAX is validated before the year field's TYPE is coerced;
        // offset MATCHING against the timezone happens later, after year coercion.
        // TC39: syntax validation runs even for offsetOption='ignore' — the offset
        // must be a syntactically valid string regardless of whether it is used.
        if (array_key_exists('offset', $bag)) {
            /** @var mixed $offSyntaxRaw */
            $offSyntaxRaw = $bag['offset'];
            if (!is_string($offSyntaxRaw)) {
                throw new TypeError('ZonedDateTime offset must be a string.');
            }
            if (preg_match('/^[+-]\d{2}:\d{2}(:\d{2})?$/', $offSyntaxRaw) !== 1) {
                throw new RangeError("Invalid offset string \"{$offSyntaxRaw}\": must be ±HH:MM or ±HH:MM:SS.");
            }
        }

        // Validate and cast numeric fields; reject INF/-INF.
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
        /** @psalm-suppress MixedAssignment — array values are all typed as mixed via @var annotations above */
        foreach ($numericFields as $fname => $fval) {
            if (is_float($fval) && is_infinite($fval)) {
                throw new RangeError(sprintf(
                    'ZonedDateTime %s must be finite; got %s.',
                    $fname,
                    $fval > 0 ? 'INF' : '-INF',
                ));
            }
        }

        // Coerce year via toFiniteInt so a non-coercible type (e.g. a JS Symbol)
        // throws TypeError here — this is the year TYPE check the fixtures pin
        // between monthCode/offset syntax (RangeError) and monthCode suitability /
        // offset matching (RangeError).
        $year = $yr !== null ? CalendarMath::toFiniteInt($yr, 'ZonedDateTime year') : 0;
        $day = intval($dy);
        $hour = intval($hr);
        $minute = intval($mn);
        $second = intval($sc);
        $milli = intval($ms);
        $micro = intval($us);
        $nano = intval($ns);

        $calendar = $calendarId !== 'iso8601' ? CalendarFactory::get($calendarId) : null;

        // Resolve era + eraYear if present (overrides year for era-based calendars).
        if ($calendar !== null && array_key_exists('era', $bag) && array_key_exists('eraYear', $bag)) {
            $resolved = CalendarMath::resolveYearFromEra($calendar, $bag['era'], $bag['eraYear'], 'ZonedDateTime');
            if ($resolved !== null) {
                $year = $resolved;
            }
        }

        // Resolve month from 'month' and/or 'monthCode'.
        $month = null;
        $monthCode = null;
        $hasMonth = array_key_exists('month', $bag);
        $hasMC = array_key_exists('monthCode', $bag);

        if (array_key_exists('monthCode', $bag)) {
            /** @var string $mc — well-formedness already validated by the earlier monthCode syntax guard */
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

        // Apply overflow (constrain or reject).
        if ($month < 1) {
            throw new RangeError("Invalid month {$month}: must be at least 1.");
        }
        if ($day < 1) {
            throw new RangeError("Invalid day {$day}: must be at least 1.");
        }

        // Non-ISO calendar: resolve calendar fields to ISO via the calendar protocol.
        if ($calendar !== null) {
            if ($monthCode !== null) {
                [$isoY, $isoM, $isoD] = $calendar->calendarToIsoFromMonthCode($year, $monthCode, $day, $overflow);
            } else {
                [$isoY, $isoM, $isoD] = $calendar->calendarToIso($year, $month, $day, $overflow);
            }
            $year = $isoY;
            $month = $isoM;
            $day = $isoD;
        } else {
            if ($overflow === 'constrain') {
                /**
                 * @psalm-suppress UnnecessaryVarAnnotation — Mago can't narrow min()
                 */
                $month = min(12, $month);
                $maxDay = CalendarMath::calcDaysInMonth($year, $month);
                $day = min($maxDay, $day);
            } else {
                // overflow === 'reject'
                if ($month > 12) {
                    throw new RangeError("Invalid month {$month}: must be 1–12.");
                }
                $maxDay = CalendarMath::calcDaysInMonth($year, $month);
                if ($day > $maxDay) {
                    throw new RangeError("Invalid day {$day}: exceeds {$maxDay} for {$year}-{$month}.");
                }
            }
        }

        // Constrain time fields.
        if ($overflow === 'constrain') {
            $hour = max(0, min(23, $hour));
            $minute = max(0, min(59, $minute));
            $second = max(0, min(59, $second));
            $milli = max(0, min(999, $milli));
            $micro = max(0, min(999, $micro));
            $nano = max(0, min(999, $nano));
        }

        // Use JDN-based computation to handle extreme years (DateTimeImmutable
        // cannot represent years beyond ~9999 or negative years reliably).
        $epochDays = CalendarMath::toJulianDay($year, $month, $day) - 2_440_588;
        $wallSec = ($epochDays * 86_400) + ($hour * 3600) + ($minute * 60) + $second;
        // ISODateTimeWithinLimits check.
        if ($wallSec > EpochLimits::MAX_EPOCH_SECONDS || $wallSec < -EpochLimits::MAX_EPOCH_SECONDS) {
            throw new RangeError('ZonedDateTime property bag: local date-time is outside the representable range.');
        }

        $normalTzId = TimeZoneIdentity::normalize($tzRaw);
        $resolvedTzId = TimeZoneIdentity::canonicalId($normalTzId);
        $epochSec = TimeZoneHelper::wallSecToEpochSec($wallSec, $resolvedTzId, $disambiguation);
        $subNs = ($milli * EpochLimits::NS_PER_MILLISECOND) + ($micro * EpochLimits::NS_PER_MICROSECOND) + $nano;

        // Handle 'offset' field if provided: depends on offset option.
        if (array_key_exists('offset', $bag) && $offsetOption !== 'ignore') {
            /** @var string $offRaw — syntax already validated by the earlier offset guard */
            $offRaw = $bag['offset'];
            $givenOffsetSec = IsoToken::offsetFieldSeconds($offRaw);

            if ($offsetOption === 'use') {
                // Use the offset directly, regardless of timezone rules.
                $epochSec = $wallSec - $givenOffsetSec;
            } else {
                // 'prefer' or 'reject': try using the given offset.
                $epochFromOffset = $wallSec - $givenOffsetSec;
                $actualOffset = TimeZoneHelper::offsetSecondsAt($resolvedTzId, $epochFromOffset);
                if ($actualOffset === $givenOffsetSec) {
                    // The offset is valid at this instant — use it.
                    $epochSec = $epochFromOffset;
                } elseif ($offsetOption === 'reject') {
                    // Offset doesn't match timezone at this wall time → reject.
                    throw new RangeError(
                        "The offset {$offRaw} does not match the timezone {$normalTzId} offset at the given instant.",
                    );
                }

                // 'prefer': keep disambiguation-resolved epochSec.
            }
        }

        return ZonedDateTime::fromEpochParts($epochSec, $subNs, $normalTzId, $calendarId);
    }

    /**
     * Returns a new ZonedDateTime with the specified fields overridden.
     *
     * @param array<array-key,mixed>|object $fields   Property bag with fields to override.
     * @param array<array-key, mixed>|object|null       $options Options bag: ['overflow' => ..., 'disambiguation' => ...]
     * @psalm-api
     */
    public static function with(
        ZonedDateTime $zdt,
        array|object $fields,
        mixed $options = null,
    ): ZonedDateTime {
        // Reject Temporal objects (IsPartialTemporalObject step 2).
        if (
            $fields instanceof PlainDate
            || $fields instanceof PlainDateTime
            || $fields instanceof PlainTime
            || $fields instanceof PlainYearMonth
            || $fields instanceof PlainMonthDay
            || $fields instanceof ZonedDateTime
            || $fields instanceof Instant
            || $fields instanceof Duration
        ) {
            throw new TypeError('ZonedDateTime::with() argument must not be a Temporal object.');
        }

        $fields = FieldBag::forPartial($fields, self::CALENDAR_FIELDS, $zdt->calendarId, ['offset']);

        if (array_key_exists('calendar', $fields) || array_key_exists('timeZone', $fields)) {
            throw new TypeError('ZonedDateTime::with() fields must not contain a calendar or timeZone property.');
        }

        $recognized = [
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
            'offset',
            'era',
            'eraYear',
        ];
        $hasField = false;
        foreach ($recognized as $f) {
            if (!array_key_exists($f, $fields)) {
                continue;
            }

            $hasField = true;
            break;
        }
        if (!$hasField) {
            throw new TypeError('ZonedDateTime::with() requires at least one recognized property.');
        }

        // GetOptionsObject reads every recognized option once, in the spec's
        // alphabetical order. The resolvers below take that snapshot rather than the
        // raw bag, so an accessor fires exactly once and in that order.
        $opts = Options::normalizeOptions($options, ['disambiguation', 'offset', 'overflow']);
        $overflow = Options::overflowFromBag($opts);
        $disambiguation = self::extractDisambiguation($opts);

        // Extract the 'offset' option (default is 'prefer' for with()).
        $offsetOption = 'prefer';
        if (array_key_exists('offset', $opts)) {
            /** @var mixed $offOpt */
            $offOpt = $opts['offset'];
            if ($offOpt !== null) {
                $offOpt = Options::coerceEnumOption($offOpt, 'offset');
                if (!in_array($offOpt, ['prefer', 'use', 'ignore', 'reject'], strict: true)) {
                    throw new RangeError(
                        "Invalid offset option \"{$offOpt}\": must be 'prefer', 'use', 'ignore', or 'reject'.",
                    );
                }
                $offsetOption = $offOpt;
            }
        }

        // Validate the 'offset' field in the property bag.
        $hasOffsetField = array_key_exists('offset', $fields);
        if ($hasOffsetField) {
            /** @var mixed $offVal */
            $offVal = $fields['offset'];
            if (!is_string($offVal)) {
                throw new TypeError('ZonedDateTime::with() offset field must be a string.');
            }
            if (preg_match('/^[+-]\d{2}:\d{2}(:\d{2})?$/', $offVal) !== 1) {
                throw new RangeError("Invalid offset string \"{$offVal}\": must be ±HH:MM or ±HH:MM:SS.");
            }
        }

        $lc = $zdt->localComponents();
        $h = $lc['hour'];
        $min = $lc['minute'];
        $sec = $lc['second'];
        $ms = $lc['millisecond'];
        $us = $lc['microsecond'];
        $ns = $lc['nanosecond'];

        // --- Resolve time fields (shared by ISO and non-ISO paths) ---
        if (array_key_exists('hour', $fields)) {
            $h = CalendarMath::toFiniteInt($fields['hour'], 'ZonedDateTime::with() hour');
        }
        if (array_key_exists('minute', $fields)) {
            $min = CalendarMath::toFiniteInt($fields['minute'], 'ZonedDateTime::with() minute');
        }
        if (array_key_exists('second', $fields)) {
            $sec = CalendarMath::toFiniteInt($fields['second'], 'ZonedDateTime::with() second');
        }
        if (array_key_exists('millisecond', $fields)) {
            $ms = CalendarMath::toFiniteInt($fields['millisecond'], 'ZonedDateTime::with() millisecond');
        }
        if (array_key_exists('microsecond', $fields)) {
            $us = CalendarMath::toFiniteInt($fields['microsecond'], 'ZonedDateTime::with() microsecond');
        }
        if (array_key_exists('nanosecond', $fields)) {
            $ns = CalendarMath::toFiniteInt($fields['nanosecond'], 'ZonedDateTime::with() nanosecond');
        }

        // --- Constrain/reject time fields ---
        if ($overflow === 'constrain') {
            $h = max(0, min(23, $h));
            $min = max(0, min(59, $min));
            $sec = max(0, min(59, $sec));
            $ms = max(0, min(999, $ms));
            $us = max(0, min(999, $us));
            $ns = max(0, min(999, $ns));
        } else {
            if ($h < 0 || $h > 23) {
                throw new RangeError("Invalid hour {$h}: must be 0–23.");
            }
            if ($min < 0 || $min > 59) {
                throw new RangeError("Invalid minute {$min}: must be 0–59.");
            }
            if ($sec < 0 || $sec > 59) {
                throw new RangeError("Invalid second {$sec}: must be 0–59.");
            }
            if ($ms < 0 || $ms > 999) {
                throw new RangeError("Invalid millisecond {$ms}: must be 0–999.");
            }
            if ($us < 0 || $us > 999) {
                throw new RangeError("Invalid microsecond {$us}: must be 0–999.");
            }
            if ($ns < 0 || $ns > 999) {
                throw new RangeError("Invalid nanosecond {$ns}: must be 0–999.");
            }
        }

        $calendar = $zdt->calendarId !== 'iso8601' ? CalendarFactory::get($zdt->calendarId) : null;

        // --- Non-ISO calendar date resolution ---
        if ($calendar !== null) {
            $hasYear = array_key_exists('year', $fields);
            $hasEra = array_key_exists('era', $fields);
            $hasEraYear = array_key_exists('eraYear', $fields);
            $hasMonth = array_key_exists('month', $fields);
            $hasMonthCode = array_key_exists('monthCode', $fields);

            // Chinese/Dangi have no eras — providing era or eraYear is always a TypeError.
            if (($hasEra || $hasEraYear) && in_array($zdt->calendarId, ['chinese', 'dangi'], strict: true)) {
                throw new TypeError('eraYear and era are invalid for this calendar.');
            }

            // TC39: era without eraYear (or vice versa) is TypeError when year is not also provided.
            if ($hasEra && !$hasEraYear && !$hasYear) {
                throw new TypeError('era provided without eraYear in with() fields.');
            }
            if ($hasEraYear && !$hasEra && !$hasYear) {
                throw new TypeError('eraYear provided without era in with() fields.');
            }

            // Resolve year: era+eraYear takes precedence over the current year if both provided.
            // When $hasYear is false, $hasEra implies $hasEraYear (and vice versa) due to checks above.
            $year = $zdt->year;
            if ($hasYear) {
                $year = CalendarMath::toFiniteInt($fields['year'], 'ZonedDateTime::with() year');
            } elseif ($hasEra) {
                $resolved = CalendarMath::resolveYearFromEra(
                    $calendar,
                    $fields['era'],
                    $fields['eraYear'],
                    'ZonedDateTime::with()',
                );
                if ($resolved !== null) {
                    $year = $resolved;
                }
            }

            // Resolve monthCode/month with mutual exclusion.
            // When neither is provided, default to current monthCode (not ordinal month).
            $monthCode = null;
            $month = null;
            $useMonthCode = false;

            if ($hasMonthCode) {
                /** @var mixed $mc */
                $mc = $fields['monthCode'];
                if (!is_string($mc)) {
                    throw new RangeError('ZonedDateTime::with() monthCode must be a string.');
                }
                $monthCode = $mc;
                $useMonthCode = true;
            }
            if ($hasMonth) {
                $month = CalendarMath::toFiniteInt($fields['month'], 'ZonedDateTime::with() month');
                // Validate month/monthCode conflict.
                if ($monthCode !== null) {
                    $monthFromCode = $calendar->monthCodeToMonth($monthCode, $year);
                    if ($month !== $monthFromCode) {
                        throw new RangeError('Conflicting month and monthCode fields.');
                    }
                }
                $useMonthCode = false; // explicit month takes precedence
            }
            if (!$hasMonth && !$hasMonthCode) {
                // Default: preserve current monthCode.
                $monthCode = $zdt->monthCode;
                $useMonthCode = true;
            }

            $day = $zdt->day;
            if (array_key_exists('day', $fields)) {
                $day = CalendarMath::toFiniteInt($fields['day'], 'ZonedDateTime::with() day');
            }

            if ($day < 1) {
                throw new RangeError("Invalid day {$day}: must be at least 1.");
            }

            if ($useMonthCode && $monthCode !== null) {
                [$isoY, $isoM, $isoD] = $calendar->calendarToIsoFromMonthCode($year, $monthCode, $day, $overflow);
            } else {
                /** @var int $month */
                if ($month < 1) {
                    throw new RangeError("Invalid month {$month}: must be at least 1.");
                }
                [$isoY, $isoM, $isoD] = $calendar->calendarToIso($year, $month, $day, $overflow);
            }

            return ZonedDateTime::fromLocalParts(
                $isoY,
                $isoM,
                $isoD,
                $h,
                $min,
                $sec,
                $ms,
                $us,
                $ns,
                $zdt->timeZoneId,
                $zdt->calendarId,
                $disambiguation,
            );
        }

        // --- ISO calendar date resolution ---
        $year = $lc['year'];
        $month = $lc['month'];
        $day = $lc['day'];

        if (array_key_exists('year', $fields)) {
            $year = CalendarMath::toFiniteInt($fields['year'], 'ZonedDateTime::with() year');
        }

        $hasMonth = array_key_exists('month', $fields);
        $hasMonthCode = array_key_exists('monthCode', $fields);
        if ($hasMonthCode) {
            /** @var mixed $mc */
            $mc = $fields['monthCode'];
            if (!is_string($mc)) {
                throw new RangeError('ZonedDateTime::with() monthCode must be a string.');
            }
            $month = CalendarMath::monthCodeToMonth($mc);
        }
        if ($hasMonth) {
            $newMonth = CalendarMath::toFiniteInt($fields['month'], 'ZonedDateTime::with() month');
            if ($hasMonthCode && $newMonth !== $month) {
                throw new RangeError('Conflicting month and monthCode fields.');
            }
            $month = $newMonth;
        }

        if (array_key_exists('day', $fields)) {
            $day = CalendarMath::toFiniteInt($fields['day'], 'ZonedDateTime::with() day');
        }

        if ($month < 1) {
            throw new RangeError("Invalid month {$month}: must be at least 1.");
        }
        if ($day < 1) {
            throw new RangeError("Invalid day {$day}: must be at least 1.");
        }

        if ($overflow === 'constrain') {
            /**
             * @psalm-suppress UnnecessaryVarAnnotation — Mago can't narrow min()
             */
            $month = min(12, $month);
            $maxDay = CalendarMath::calcDaysInMonth($year, $month);
            $day = min($maxDay, $day);
        } else {
            // overflow === 'reject'
            if ($month > 12) {
                throw new RangeError("Invalid month {$month}: must be 1–12.");
            }
            $maxDay = CalendarMath::calcDaysInMonth($year, $month);
            if ($day > $maxDay) {
                throw new RangeError("Day {$day} is out of range for {$year}-{$month} (max {$maxDay}).");
            }
        }

        // If no offset field was provided but offset option requires preserving,
        // use the ZDT's current offset for wall-to-epoch conversion. Per TC39,
        // 'use'/'prefer'/'reject' all preserve the existing offset when possible.
        if (!$hasOffsetField && $offsetOption !== 'ignore') {
            [$curEpochSec] = $zdt->epochParts();
            $currentOffsetSec = TimeZoneHelper::offsetSecondsAt($zdt->resolvedTimeZoneId, $curEpochSec);

            $epochDays = CalendarMath::toJulianDay($year, $month, $day) - 2_440_588;
            $wallSec = ($epochDays * 86_400) + ($h * 3600) + ($min * 60) + $sec;

            if ($offsetOption === 'use') {
                $epochSec = $wallSec - $currentOffsetSec;
                $subNs = ($ms * EpochLimits::NS_PER_MILLISECOND) + ($us * EpochLimits::NS_PER_MICROSECOND) + $ns;
                return ZonedDateTime::fromEpochParts($epochSec, $subNs, $zdt->timeZoneId, $zdt->calendarId);
            }
            // 'prefer'/'reject': check if current offset is valid at new wall time
            $epochFromOffset = $wallSec - $currentOffsetSec;
            $actualOffset = TimeZoneHelper::offsetSecondsAt($zdt->resolvedTimeZoneId, $epochFromOffset);
            if ($actualOffset === $currentOffsetSec) {
                $subNs = ($ms * EpochLimits::NS_PER_MILLISECOND) + ($us * EpochLimits::NS_PER_MICROSECOND) + $ns;
                return ZonedDateTime::fromEpochParts($epochFromOffset, $subNs, $zdt->timeZoneId, $zdt->calendarId);
            }

            // Current offset not valid at new wall time — fall through to disambiguation
        }

        // Handle offset field with offset option (like from()).
        if ($hasOffsetField) {
            /** @var string $offVal */
            $offVal = $fields['offset'];
            $givenOffsetSec = IsoToken::offsetFieldSeconds($offVal);

            if ($offsetOption === 'ignore') {
                // Fall through to normal fromLocalParts().
            } else {
                $epochDays = CalendarMath::toJulianDay($year, $month, $day) - 2_440_588;
                $wallSec = ($epochDays * 86_400) + ($h * 3600) + ($min * 60) + $sec;

                if ($offsetOption === 'use') {
                    // Use the offset directly, regardless of timezone rules.
                    $epochSec = $wallSec - $givenOffsetSec;
                    $subNs = ($ms * EpochLimits::NS_PER_MILLISECOND) + ($us * EpochLimits::NS_PER_MICROSECOND) + $ns;
                    return ZonedDateTime::fromEpochParts($epochSec, $subNs, $zdt->timeZoneId, $zdt->calendarId);
                }

                // 'prefer' or 'reject': try using the given offset.
                $epochFromOffset = $wallSec - $givenOffsetSec;
                $actualOffset = TimeZoneHelper::offsetSecondsAt($zdt->resolvedTimeZoneId, $epochFromOffset);
                if ($actualOffset === $givenOffsetSec) {
                    $subNs = ($ms * EpochLimits::NS_PER_MILLISECOND) + ($us * EpochLimits::NS_PER_MICROSECOND) + $ns;
                    return ZonedDateTime::fromEpochParts($epochFromOffset, $subNs, $zdt->timeZoneId, $zdt->calendarId);
                }
                if ($offsetOption === 'reject') {
                    throw new RangeError(
                        "The offset {$offVal} does not match the timezone offset at the given instant.",
                    );
                }

                // 'prefer': fall through to normal fromLocalParts().
            }
        }

        return ZonedDateTime::fromLocalParts(
            $year,
            $month,
            $day,
            $h,
            $min,
            $sec,
            $ms,
            $us,
            $ns,
            $zdt->timeZoneId,
            $zdt->calendarId,
            $disambiguation,
        );
    }

    /**
     * Extracts and validates the 'disambiguation' option.
     *
     * @param array<array-key, mixed>|object|null $options
     */
    private static function extractDisambiguation(array|object|null $options): string
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
}
