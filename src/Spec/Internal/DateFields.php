<?php

declare(strict_types=1);

namespace Calendrics\Spec\Internal;

use Calendrics\Exception\RangeError;
use Calendrics\Exception\TypeError;
use Calendrics\Spec\Internal\Calendar\CalendarFactory;
use Calendrics\Spec\PlainDate;

/**
 * Construction of a `PlainDate` from a property bag of calendar fields.
 *
 * The bag names a date in *calendar* terms — `year` or `era`+`eraYear`, `month` or
 * `monthCode`, and `day` — and the interesting work is reconciling the redundant
 * spellings before the calendar maps them to an ISO date. The field *read order* is
 * observable through accessor side effects, so checks run in the spec's sequence: a
 * present `monthCode`'s type and syntax are validated before `year` is even coerced,
 * while its *suitability* for the calendar waits until after.
 *
 * `overflow` only decides regulation: `constrain` clamps `month` and `day` to what the
 * resolved year actually has; `reject` lets the out-of-range value surface as a
 * RangeError. Neither can save a `month` or `day` below 1 — there is nothing to clamp
 * up to.
 *
 * @internal
 */
final class DateFields
{
    /**
     * The calendar fields a PlainDate is built from, as passed to
     * PrepareCalendarFields. `era`/`eraYear` are CalendarExtraFields, added by
     * {@see FieldBag} only for calendars that have eras.
     *
     * @var list<string>
     */
    public const array CALENDAR_FIELDS = ['year', 'month', 'monthCode', 'day'];

    /**
     * Creates a PlainDate from a property-bag array.
     *
     * @param array<array-key,mixed> $bag
     * @param string $overflow 'constrain' (clamp) or 'reject' (throw on out-of-range).
     * @throws TypeError if required fields are missing or have wrong type.
     * @throws RangeError if the date is invalid.
     */
    public static function fromBag(array $bag, string $overflow = 'constrain'): PlainDate
    {
        $calendarId = array_key_exists('calendar', $bag)
            ? CalendarFactory::resolveBagCalendar($bag['calendar'], 'PlainDate')
            : null;

        $hasEraAndEraYear = CalendarMath::hasEraAndEraYear($bag, $calendarId, 'PlainDate');
        $calendarSupportsEras = CalendarMath::supportsEras($calendarId);

        // For calendars without eras (ISO, Chinese, Dangi), era+eraYear can't replace year.
        if (!array_key_exists('year', $bag) && (!$hasEraAndEraYear || !$calendarSupportsEras)) {
            throw new TypeError('PlainDate property bag must have a year field.');
        }
        if (!array_key_exists('month', $bag) && !array_key_exists('monthCode', $bag)) {
            throw new TypeError('PlainDate property bag must have a month or monthCode field.');
        }
        if (!array_key_exists('day', $bag)) {
            throw new TypeError('PlainDate property bag must have a day field.');
        }

        $calendar = CalendarFactory::get($calendarId ?? 'iso8601');
        $readsEraFields = CalendarMath::readsEraFields($calendarId);

        // TC39 PrepareCalendarFields: a monthCode value's TYPE (must be a string) and its
        // SYNTAX (well-formedness) are validated before the year value's type is coerced.
        // Its SUITABILITY (e.g. "M13" out of range) is validated later, after the year.
        // Hence: { monthCode: "L99M", year: Symbol() } => RangeError (bad syntax first),
        //   while { monthCode: "M99L", year: Symbol() } => TypeError (year type first).
        $validatedMonthCode = null;
        if (array_key_exists('monthCode', $bag)) {
            $validatedMonthCode = MonthCode::validate($bag['monthCode']);
        }

        // Extract year from the bag, or resolve from era + eraYear.
        $year = 0;
        if (array_key_exists('year', $bag)) {
            /** @var mixed $yearRaw */
            $yearRaw = $bag['year'];
            if ($yearRaw === null) {
                throw new RangeError('PlainDate property bag year field must not be undefined.');
            }
            $year = CalendarMath::toFiniteInt($yearRaw, 'PlainDate year');
        }

        // Resolve era + eraYear if present (overrides year for era-based calendars).
        if ($readsEraFields && array_key_exists('era', $bag) && array_key_exists('eraYear', $bag)) {
            $resolved = CalendarMath::resolveYearFromEra($calendar, $bag['era'], $bag['eraYear'], 'PlainDate');
            if ($resolved !== null) {
                $year = $resolved;
            }
        }

        // Resolve month from monthCode or month field.
        $month = null;
        $monthCode = null;
        $hasMonth = array_key_exists('month', $bag);
        $hasMonthCode = $validatedMonthCode !== null;

        if ($validatedMonthCode !== null) {
            // Type and well-formedness were validated above (before year coercion).
            // Suitability (valid month value) is resolved here, after the year.
            $monthCode = $validatedMonthCode;
            $month = $calendar->monthCodeToMonth($monthCode, $year);
        }

        if ($hasMonth) {
            /** @var mixed $monthRaw */
            $monthRaw = $bag['month'] ?? null;
            if ($monthRaw === null) {
                throw new RangeError('PlainDate property bag month field must not be undefined.');
            }
            $newMonth = CalendarMath::toFiniteInt($monthRaw, 'PlainDate month');
            if ($hasMonthCode && $newMonth !== $month) {
                throw new RangeError('Conflicting month and monthCode fields.');
            }
            $month = $newMonth;
        }

        /** @var int $month */

        /** @var mixed $dayRaw */
        $dayRaw = $bag['day'];
        if ($dayRaw === null) {
            throw new RangeError('PlainDate property bag day field must not be undefined.');
        }
        $day = CalendarMath::toFiniteInt($dayRaw, 'PlainDate day');

        // month < 1 and day < 1 are always invalid (cannot constrain below minimum of 1).
        if ($month < 1) {
            throw new RangeError("Invalid PlainDate: month {$month} must be at least 1.");
        }
        if ($day < 1) {
            throw new RangeError("Invalid PlainDate: day {$day} must be at least 1.");
        }

        [$isoY, $isoM, $isoD] = $monthCode !== null
            ? $calendar->calendarToIsoFromMonthCode($year, $monthCode, $day, $overflow)
            : $calendar->calendarToIso($year, $month, $day, $overflow);

        return new PlainDate($isoY, $isoM, $isoD, $calendarId ?? 'iso8601');
    }
}
