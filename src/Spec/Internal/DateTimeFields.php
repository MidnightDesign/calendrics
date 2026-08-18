<?php

declare(strict_types=1);

namespace Calendrics\Spec\Internal;

use Calendrics\Exception\RangeError;
use Calendrics\Exception\TypeError;
use Calendrics\Spec\Internal\Calendar\CalendarFactory;
use Calendrics\Spec\PlainDateTime;

/**
 * Construction of a `PlainDateTime` from a property bag of calendar fields.
 *
 * This is TC39 InterpretTemporalDateTimeFields: the bag names a date in *calendar*
 * terms (`year` or `era`+`eraYear`; `month` or `monthCode`; `day`) plus optional time
 * fields, and the interesting work is reconciling the redundant spellings — an
 * era-based year against a plain one, an ordinal month against a month code — before
 * the calendar maps them to an ISO date. The field *read order* is observable through
 * accessor side effects, so checks run in the spec's sequence: a present `monthCode`'s
 * type and syntax are validated before `year` is even coerced.
 *
 * `overflow` only decides regulation: `constrain` clamps both the calendar fields (via
 * the calendar protocol) and the time fields; `reject` lets the out-of-range value
 * surface as a RangeError.
 *
 * @internal
 */
final class DateTimeFields
{
    /**
     * The calendar fields a PlainDateTime is built from, as passed to
     * PrepareCalendarFields. `era`/`eraYear` are CalendarExtraFields, added by
     * {@see FieldBag} only for calendars that have eras.
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
     * Creates a PlainDateTime from a property-bag array.
     *
     * Required: year, (month or monthCode), day.
     * Optional: hour, minute, second, millisecond, microsecond, nanosecond.
     *
     * @param array<array-key,mixed> $bag
     * @param string                 $overflow 'constrain' (clamp) or 'reject' (throw on out-of-range).
     * @throws TypeError if required fields are missing or have wrong type.
     * @throws RangeError if the datetime is invalid.
     */
    public static function fromBag(array $bag, string $overflow = 'constrain'): PlainDateTime
    {
        // Validate calendar key if present.
        $calendarId = null;
        if (array_key_exists('calendar', $bag)) {
            $calendarId = CalendarFactory::resolveBagCalendar($bag['calendar'], 'PlainDateTime');
        }

        $hasEraAndEraYear = CalendarMath::hasEraAndEraYear($bag, $calendarId, 'PlainDateTime');
        $calendarSupportsEras = CalendarMath::supportsEras($calendarId);

        if (!array_key_exists('year', $bag) && (!$hasEraAndEraYear || !$calendarSupportsEras)) {
            throw new TypeError('PlainDateTime property bag must have a year field.');
        }
        if (!array_key_exists('month', $bag) && !array_key_exists('monthCode', $bag)) {
            throw new TypeError('PlainDateTime property bag must have a month or monthCode field.');
        }
        if (!array_key_exists('day', $bag)) {
            throw new TypeError('PlainDateTime property bag must have a day field.');
        }

        $calendar = $calendarId !== null && $calendarId !== 'iso8601' ? CalendarFactory::get($calendarId) : null;

        // Per TC39 ToMonthCode, a present monthCode's TYPE (must be a string) is
        // checked first, then its *syntactic* well-formedness (M + 2 digits + optional
        // L) — both before the year field's type is coerced. Only its *suitability*
        // (valid value for this calendar) is checked afterwards. Routing through
        // MonthCode::validate realigns this path with PlainDate/PlainYearMonth's
        // type-then-syntax order, so a non-string monthCode throws TypeError and an
        // ill-formed string throws RangeError before a bad year would throw.
        $monthCodeValidated = null;
        if (array_key_exists('monthCode', $bag)) {
            $monthCodeValidated = MonthCode::validate($bag['monthCode']);
        }

        // Extract year from the bag, or resolve from era + eraYear.
        $year = 0;
        if (array_key_exists('year', $bag)) {
            /** @var mixed $yearRaw */
            $yearRaw = $bag['year'];
            if ($yearRaw === null) {
                throw new TypeError('PlainDateTime property bag year field must not be undefined.');
            }
            $year = CalendarMath::toFiniteInt($yearRaw, 'PlainDateTime year');
        }

        // Resolve era + eraYear if present (overrides year for era-based calendars).
        if ($calendar !== null && array_key_exists('era', $bag) && array_key_exists('eraYear', $bag)) {
            $resolved = CalendarMath::resolveYearFromEra($calendar, $bag['era'], $bag['eraYear'], 'PlainDateTime');
            if ($resolved !== null) {
                $year = $resolved;
            }
        }

        // Resolve month from monthCode or month field.
        $month = null;
        $monthCode = null;
        $hasMonth = array_key_exists('month', $bag);
        $hasMonthCode = array_key_exists('monthCode', $bag);

        if ($monthCodeValidated !== null) {
            $monthCode = $monthCodeValidated;
            $month = $calendar !== null
                ? $calendar->monthCodeToMonth($monthCode, $year)
                : CalendarMath::monthCodeToMonth($monthCode);
        }

        if ($hasMonth) {
            /** @var mixed $monthRaw */
            $monthRaw = $bag['month'] ?? null;
            if ($monthRaw === null) {
                throw new TypeError('PlainDateTime property bag month field must not be undefined.');
            }
            $newMonth = CalendarMath::toFiniteInt($monthRaw, 'PlainDateTime month');
            if ($hasMonthCode && $newMonth !== $month) {
                throw new RangeError('Conflicting month and monthCode fields.');
            }
            $month = $newMonth;
        }

        /** @var int $month */

        /** @var mixed $dayRaw */
        $dayRaw = $bag['day'];
        if ($dayRaw === null) {
            throw new TypeError('PlainDateTime property bag day field must not be undefined.');
        }
        $day = CalendarMath::toFiniteInt($dayRaw, 'PlainDateTime day');

        // Time fields default to 0 when absent.
        $h = CalendarMath::extractIntField($bag, 'hour', 0, 'PlainDateTime');
        $min = CalendarMath::extractIntField($bag, 'minute', 0, 'PlainDateTime');
        $sec = CalendarMath::extractIntField($bag, 'second', 0, 'PlainDateTime');
        $ms = CalendarMath::extractIntField($bag, 'millisecond', 0, 'PlainDateTime');
        $us = CalendarMath::extractIntField($bag, 'microsecond', 0, 'PlainDateTime');
        $ns = CalendarMath::extractIntField($bag, 'nanosecond', 0, 'PlainDateTime');

        if ($month < 1) {
            throw new RangeError("Invalid PlainDateTime: month {$month} must be at least 1.");
        }
        if ($day < 1) {
            throw new RangeError("Invalid PlainDateTime: day {$day} must be at least 1.");
        }

        // Non-ISO calendar: resolve calendar fields to ISO via the calendar protocol.
        if ($calendar !== null) {
            if ($monthCode !== null) {
                [$isoY, $isoM, $isoD] = $calendar->calendarToIsoFromMonthCode($year, $monthCode, $day, $overflow);
            } else {
                [$isoY, $isoM, $isoD] = $calendar->calendarToIso($year, $month, $day, $overflow);
            }
            if ($overflow === 'constrain') {
                $h = max(0, min(23, $h));
                $min = max(0, min(59, $min));
                $sec = max(0, min(59, $sec));
                $ms = max(0, min(999, $ms));
                $us = max(0, min(999, $us));
                $ns = max(0, min(999, $ns));
            }
            return new PlainDateTime($isoY, $isoM, $isoD, $h, $min, $sec, $ms, $us, $ns, $calendarId);
        }

        if ($overflow === 'constrain') {
            /**
             * @psalm-suppress UnnecessaryVarAnnotation — Mago can't narrow min()
             */
            $month = min(12, $month);
            $maxDay = CalendarMath::calcDaysInMonth($year, $month);
            $day = min($maxDay, $day);
            $h = max(0, min(23, $h));
            $min = max(0, min(59, $min));
            $sec = max(0, min(59, $sec));
            $ms = max(0, min(999, $ms));
            $us = max(0, min(999, $us));
            $ns = max(0, min(999, $ns));
        }

        return new PlainDateTime($year, $month, $day, $h, $min, $sec, $ms, $us, $ns, $calendarId ?? 'iso8601');
    }
}
