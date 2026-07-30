<?php

declare(strict_types=1);

namespace Temporal\Spec;

use Stringable;
use Temporal\Exception\RangeError;
use Temporal\Exception\TypeError;
use Temporal\Spec\Internal\Calendar\CalendarFactory;
use Temporal\Spec\Internal\CalendarMath;
use Temporal\Spec\Internal\EpochLimits;
use Temporal\Spec\Internal\MonthCode;
use Temporal\Spec\Internal\Options;
use Temporal\Spec\Internal\TemporalSerde;
use Temporal\Spec\Internal\TimeZoneHelper;

/**
 * A calendar date combined with a wall-clock time, without a time zone.
 *
 * Only the ISO 8601 calendar is supported. The date range is identical to
 * PlainDate (Apr 19 −271821 … Sep 13 +275760); the time range is
 * 00:00:00.000000000 – 23:59:59.999999999.
 *
 * @see https://tc39.es/proposal-temporal/#sec-temporal-plaindatetime-objects
 */
final class PlainDateTime implements Stringable
{
    use TemporalSerde;

    private const int NS_PER_HOUR = 3_600_000_000_000;
    private const int NS_PER_MINUTE = 60_000_000_000;
    private const int NS_PER_MS = 1_000_000;
    private const int NS_PER_US = 1_000;
    private const int NS_PER_DAY = 86_400_000_000_000;

    // -------------------------------------------------------------------------
    // Virtual (get-only) properties
    // -------------------------------------------------------------------------

    /**
     * @psalm-suppress PropertyNotSetInConstructor — virtual property
     * @psalm-suppress PossiblyUnusedProperty — accessed externally via test262 scripts
     * @psalm-api
     */
    public ?string $era {
        get => CalendarFactory::get($this->calendarId)->era($this->isoYear, $this->isoMonth, $this->isoDay);
    }

    /**
     * @psalm-suppress PropertyNotSetInConstructor — virtual property
     * @psalm-suppress PossiblyUnusedProperty — accessed externally via test262 scripts
     * @psalm-api
     */
    public ?int $eraYear {
        get => CalendarFactory::get($this->calendarId)->eraYear($this->isoYear, $this->isoMonth, $this->isoDay);
    }

    /**
     * @psalm-suppress PropertyNotSetInConstructor — virtual property
     * @psalm-suppress PossiblyUnusedProperty — accessed externally via test262 scripts
     * @psalm-api
     */
    public string $monthCode {
        get => CalendarFactory::get($this->calendarId)->monthCode($this->isoYear, $this->isoMonth, $this->isoDay);
    }

    /**
     * @psalm-suppress PropertyNotSetInConstructor — virtual property
     * @psalm-suppress PossiblyUnusedProperty
     * @psalm-api
     */
    public int $year {
        get => $this->calendarId === 'iso8601'
            ? $this->isoYear
            : CalendarFactory::get($this->calendarId)->year($this->isoYear, $this->isoMonth, $this->isoDay);
    }

    /**
     * @psalm-suppress PropertyNotSetInConstructor — virtual property
     * @psalm-suppress PossiblyUnusedProperty
     * @psalm-api
     */
    public int $month {
        get => $this->calendarId === 'iso8601'
            ? $this->isoMonth
            : CalendarFactory::get($this->calendarId)->month($this->isoYear, $this->isoMonth, $this->isoDay);
    }

    /**
     * @psalm-suppress PropertyNotSetInConstructor — virtual property
     * @psalm-suppress PossiblyUnusedProperty
     * @psalm-api
     */
    public int $day {
        get => $this->calendarId === 'iso8601'
            ? $this->isoDay
            : CalendarFactory::get($this->calendarId)->day($this->isoYear, $this->isoMonth, $this->isoDay);
    }

    /**
     * ISO 8601 day of week: 1 = Monday, 7 = Sunday.
     *
     * @psalm-suppress PropertyNotSetInConstructor — virtual property (get-only hook, no backing store)
     * @psalm-suppress PossiblyUnusedProperty — accessed externally via test262 scripts
     * @psalm-api
     * @var int<1, 7>
     */
    public int $dayOfWeek {
        get => CalendarMath::isoWeekday($this->isoYear, $this->isoMonth, $this->isoDay);
    }

    /**
     * Ordinal day of the year (1-based). Range depends on the calendar system.
     *
     * @psalm-suppress PropertyNotSetInConstructor — virtual property (get-only hook, no backing store)
     * @psalm-suppress PossiblyUnusedProperty — accessed externally via test262 scripts
     * @psalm-api
     */
    public int $dayOfYear {
        get => $this->calendarId === 'iso8601'
            ? CalendarMath::calcDayOfYear($this->isoYear, $this->isoMonth, $this->isoDay)
            : CalendarFactory::get($this->calendarId)->dayOfYear($this->isoYear, $this->isoMonth, $this->isoDay);
    }

    /**
     * ISO 8601 week number: 1–53, or null for non-ISO calendars.
     *
     * @psalm-suppress PropertyNotSetInConstructor — virtual property (get-only hook, no backing store)
     * @psalm-suppress PossiblyUnusedProperty — accessed externally via test262 scripts
     * @psalm-api
     */
    public ?int $weekOfYear {
        get => $this->calendarId === 'iso8601'
            ? CalendarMath::isoWeekInfo($this->isoYear, $this->isoMonth, $this->isoDay)['week']
            : null;
    }

    /**
     * ISO 8601 week-year, or null for non-ISO calendars.
     *
     * @psalm-suppress PropertyNotSetInConstructor — virtual property (get-only hook, no backing store)
     * @psalm-suppress PossiblyUnusedProperty — accessed externally via test262 scripts
     * @psalm-api
     */
    public ?int $yearOfWeek {
        get => $this->calendarId === 'iso8601'
            ? CalendarMath::isoWeekInfo($this->isoYear, $this->isoMonth, $this->isoDay)['year']
            : null;
    }

    /**
     * Number of days in this date's month.
     *
     * @psalm-suppress PropertyNotSetInConstructor — virtual property (get-only hook, no backing store)
     * @psalm-suppress PossiblyUnusedProperty — accessed externally via test262 scripts
     * @psalm-api
     */
    public int $daysInMonth {
        get => CalendarFactory::get($this->calendarId)->daysInMonth($this->isoYear, $this->isoMonth, $this->isoDay);
    }

    /**
     * Always 7 (ISO 8601 calendar).
     *
     * @psalm-suppress PropertyNotSetInConstructor — virtual property (get-only hook, no backing store)
     * @psalm-suppress PossiblyUnusedProperty — accessed externally via test262 scripts
     * @psalm-api
     * @var int<7, 7>
     */
    public int $daysInWeek {
        get => 7;
    }

    /**
     * 365 or 366, depending on whether this date's year is a leap year.
     *
     * @psalm-suppress PropertyNotSetInConstructor — virtual property (get-only hook, no backing store)
     * @psalm-suppress PossiblyUnusedProperty — accessed externally via test262 scripts
     * @psalm-api
     */
    public int $daysInYear {
        get => CalendarFactory::get($this->calendarId)->daysInYear($this->isoYear, $this->isoMonth, $this->isoDay);
    }

    /**
     * Always 12 (ISO 8601 calendar).
     *
     * @psalm-suppress PropertyNotSetInConstructor — virtual property (get-only hook, no backing store)
     * @psalm-suppress PossiblyUnusedProperty — accessed externally via test262 scripts
     * @psalm-api
     */
    public int $monthsInYear {
        get => CalendarFactory::get($this->calendarId)->monthsInYear($this->isoYear, $this->isoMonth, $this->isoDay);
    }

    /**
     * True if this date's year is a leap year.
     *
     * @psalm-suppress PropertyNotSetInConstructor — virtual property (get-only hook, no backing store)
     * @psalm-suppress PossiblyUnusedProperty — accessed externally via test262 scripts
     * @psalm-api
     */
    public bool $inLeapYear {
        get => CalendarFactory::get($this->calendarId)->inLeapYear($this->isoYear, $this->isoMonth, $this->isoDay);
    }

    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    /** @psalm-api */
    public readonly string $calendarId;
    /** @psalm-api */
    public readonly int $isoYear;
    /**
     * @psalm-api
     * @var int<1, 12>
     */
    public readonly int $isoMonth;
    /**
     * @psalm-api
     * @var int<1, 31>
     */
    public readonly int $isoDay;
    /**
     * @psalm-api
     * @var int<0, 23>
     */
    public readonly int $hour;
    /**
     * @psalm-api
     * @var int<0, 59>
     */
    public readonly int $minute;
    /**
     * @psalm-api
     * @var int<0, 59>
     */
    public readonly int $second;
    /**
     * @psalm-api
     * @var int<0, 999>
     */
    public readonly int $millisecond;
    /**
     * @psalm-api
     * @var int<0, 999>
     */
    public readonly int $microsecond;
    /**
     * @psalm-api
     * @var int<0, 999>
     */
    public readonly int $nanosecond;

    /**
     * @param mixed $year         TC39 ToIntegerWithTruncation: int/float/bool/null/string accepted.
     * @param mixed $month        1–12 after coercion.
     * @param mixed $day          1–daysInMonth after coercion.
     * @param mixed $hour         0–23 after coercion; null/omitted → 0.
     * @param mixed $minute       0–59 after coercion; null/omitted → 0.
     * @param mixed $second       0–59 after coercion; null/omitted → 0.
     * @param mixed $millisecond  0–999 after coercion; null/omitted → 0.
     * @param mixed $microsecond  0–999 after coercion; null/omitted → 0.
     * @param mixed $nanosecond   0–999 after coercion; null/omitted → 0.
     * @throws RangeError if any value is infinite, non-integer, or out of range.
     */
    public function __construct(
        mixed $year,
        mixed $month,
        mixed $day,
        mixed $hour = null,
        mixed $minute = null,
        mixed $second = null,
        mixed $millisecond = null,
        mixed $microsecond = null,
        mixed $nanosecond = null,
        mixed $calendar = null,
    ) {
        // An omitted (or null — PHP cannot distinguish JS `undefined` positionally)
        // calendar defaults to ISO 8601; a non-string is a wrong-type TypeError; an
        // unknown calendar string is a RangeError. Shared with PlainDate's constructor.
        $this->calendarId = CalendarFactory::resolveConstructorCalendar($calendar, 'PlainDateTime');
        // TC39 ToIntegerWithTruncation: null/omitted → 0, bool → 0/1, string/float → truncated int.
        $this->isoYear = CalendarMath::toConstructorInt($year, 'PlainDateTime year');
        $monthInt = CalendarMath::toConstructorInt($month, 'PlainDateTime month');
        if ($monthInt < 1 || $monthInt > 12) {
            throw new RangeError("Invalid PlainDateTime: month {$monthInt} is out of range 1–12.");
        }
        $this->isoMonth = $monthInt;
        $dayInt = CalendarMath::toConstructorInt($day, 'PlainDateTime day');
        if ($dayInt < 1) {
            throw new RangeError("Invalid PlainDateTime: day {$dayInt} must be at least 1.");
        }
        $daysInMonth = CalendarMath::calcDaysInMonth($this->isoYear, $this->isoMonth);
        if ($dayInt > $daysInMonth) {
            throw new RangeError(
                "Invalid PlainDateTime: day {$dayInt} exceeds {$daysInMonth} for {$this->isoYear}-{$this->isoMonth}.",
            );
        }
        /** @psalm-suppress InvalidPropertyAssignmentValue — $dayInt <= $daysInMonth <= 31 */
        $this->isoDay = $dayInt;
        $hInt = CalendarMath::toConstructorInt($hour, 'PlainDateTime hour');
        $minInt = CalendarMath::toConstructorInt($minute, 'PlainDateTime minute');
        $secInt = CalendarMath::toConstructorInt($second, 'PlainDateTime second');
        $msInt = CalendarMath::toConstructorInt($millisecond, 'PlainDateTime millisecond');
        $usInt = CalendarMath::toConstructorInt($microsecond, 'PlainDateTime microsecond');
        $nsInt = CalendarMath::toConstructorInt($nanosecond, 'PlainDateTime nanosecond');
        CalendarMath::validateTimeFields($hInt, $minInt, $secInt, $msInt, $usInt, $nsInt);
        // TC39 range: strictly after -271821-04-19T00:00:00 … up to +275760-09-13T23:59:59.999999999.
        // epochDays = days from Unix epoch (1970-01-01 = 0).
        // -271821-04-19 = epochDay -100_000_001; +275760-09-13 = epochDay 100_000_000.
        $epochDays = CalendarMath::toJulianDay($this->isoYear, $this->isoMonth, $this->isoDay) - 2_440_588;
        if ($epochDays < -100_000_001 || $epochDays > 100_000_000) {
            throw new RangeError(
                "Invalid PlainDateTime: {$this->isoYear}-{$this->isoMonth}-{$this->isoDay} is outside the representable range.",
            );
        }
        // Midnight (-271821-04-19 00:00:00.000000000) is itself outside the range.
        // The first valid instant is one nanosecond past midnight on that date.
        if (
            $epochDays === -100_000_001
            && $hInt === 0
            && $minInt === 0
            && $secInt === 0
            && $msInt === 0
            && $usInt === 0
            && $nsInt === 0
        ) {
            throw new RangeError(
                'Invalid PlainDateTime: -271821-04-19T00:00:00 is outside the representable range (use T00:00:00.000000001 or later).',
            );
        }

        $this->hour = $hInt;
        $this->minute = $minInt;
        $this->second = $secInt;
        $this->millisecond = $msInt;
        $this->microsecond = $usInt;
        $this->nanosecond = $nsInt;
    }

    // -------------------------------------------------------------------------
    // Static factory / comparison methods
    // -------------------------------------------------------------------------

    /**
     * Creates a PlainDateTime from another PlainDateTime, an ISO 8601 datetime string,
     * or a property-bag array.
     *
     * @param self|string|array<array-key, mixed>|object $item    PlainDateTime, ISO 8601 datetime string, or property-bag array.
     * @param mixed $options Options bag (['overflow' => 'constrain'|'reject']), null/primitive (TypeError), or omitted.
     * @throws RangeError if the string is invalid or any field is out of range.
     * @throws TypeError if the type cannot be interpreted as a PlainDateTime.
     * @psalm-api
     */
    public static function from(string|array|object $item, mixed $options = []): self
    {
        // Overflow is validated in item-type-dependent order, per ToTemporalDateTime
        // (sec-temporal-totemporaldatetime) and Temporal.PlainDateTime.from
        // (sec-temporal.plaindatetime.from):
        //   - PlainDateTime instance: step 2.a does ToTemporalOverflow, then clones.
        //   - String: step 3 parses (ParseISODateTime) first, then ToTemporalOverflow,
        //     so a malformed string raises RangeError even when options is a bad
        //     primitive (the options TypeError is raised AFTER the parse).
        //   - Property bag: step 2.g InterpretTemporalDateTimeFields reads the fields
        //     first (PrepareCalendarFields), and only then validates overflow.
        // This mirrors PlainTime's parse-then-validate ordering rather than validating
        // overflow up front for every branch.
        if ($item instanceof self) {
            Options::overflowFromValue($options);
            return new self(
                $item->isoYear,
                $item->isoMonth,
                $item->isoDay,
                $item->hour,
                $item->minute,
                $item->second,
                $item->millisecond,
                $item->microsecond,
                $item->nanosecond,
                $item->calendarId,
            );
        }
        if (is_string($item)) {
            $result = self::fromString($item);
            Options::overflowFromValue($options);
            return $result;
        }
        if (is_object($item)) {
            $item = get_object_vars($item);
        }
        $overflow = Options::overflowFromValue($options);
        return self::fromPropertyBag($item, $overflow);
    }

    /**
     * Compares two PlainDateTimes chronologically.
     *
     * Returns -1, 0, or +1 (or a value with the same sign).
     *
     * @param self|string|array<array-key, mixed>|object $one PlainDateTime or ISO 8601 datetime string.
     * @param self|string|array<array-key, mixed>|object $two PlainDateTime or ISO 8601 datetime string.
     * @psalm-api
     */
    public static function compare(string|array|object $one, string|array|object $two): int
    {
        $a = $one instanceof self ? $one : self::from($one);
        $b = $two instanceof self ? $two : self::from($two);

        if ($a->isoYear !== $b->isoYear) {
            return $a->isoYear <=> $b->isoYear;
        }
        if ($a->isoMonth !== $b->isoMonth) {
            return $a->isoMonth <=> $b->isoMonth;
        }
        if ($a->isoDay !== $b->isoDay) {
            return $a->isoDay <=> $b->isoDay;
        }
        // Compare time fields: convert each to nanoseconds since midnight.
        $aNs = self::timeToNs($a->hour, $a->minute, $a->second, $a->millisecond, $a->microsecond, $a->nanosecond);
        $bNs = self::timeToNs($b->hour, $b->minute, $b->second, $b->millisecond, $b->microsecond, $b->nanosecond);
        return $aNs <=> $bNs;
    }

    // -------------------------------------------------------------------------
    // Instance methods
    // -------------------------------------------------------------------------

    /**
     * Returns a new PlainDateTime with the specified fields overridden.
     *
     * Recognized date fields: year, month, monthCode, day.
     * Recognized time fields: hour, minute, second, millisecond, microsecond, nanosecond.
     * The 'calendar' and 'timeZone' keys must not be present.
     *
     * @param array<array-key,mixed>|object $fields   Property bag with fields to override.
     * @param mixed       $options Options bag (['overflow' => ...]), null/primitive (TypeError), or omitted.
     * @throws TypeError             if $fields contains 'calendar' or 'timeZone'.
     * @throws RangeError if the resulting datetime is invalid (overflow: reject).
     * @psalm-api
     */
    public function with(array|object $fields, mixed $options = []): self
    {
        // Reject Temporal objects (IsPartialTemporalObject step 2).
        if (
            $fields instanceof self
            || $fields instanceof PlainDate
            || $fields instanceof PlainTime
            || $fields instanceof PlainYearMonth
            || $fields instanceof PlainMonthDay
            || $fields instanceof ZonedDateTime
            || $fields instanceof Instant
            || $fields instanceof Duration
        ) {
            throw new TypeError('PlainDateTime::with() argument must not be a Temporal object.');
        }

        $fields = is_object($fields) ? get_object_vars($fields) : $fields;

        if (array_key_exists('calendar', $fields) || array_key_exists('timeZone', $fields)) {
            throw new TypeError('PlainDateTime::with() fields must not contain a calendar or timeZone property.');
        }

        // At least one recognized property must be present.
        /** @var list<string> $recognized */
        static $recognized = [
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
            'era',
            'eraYear',
        ];
        $hasRecognized = false;
        foreach ($recognized as $key) {
            if (!array_key_exists($key, $fields)) {
                continue;
            }

            $hasRecognized = true;
            break;
        }
        if (!$hasRecognized) {
            throw new TypeError('PlainDateTime::with() requires at least one recognized temporal property.');
        }

        $calendar = $this->calendarId !== 'iso8601' ? CalendarFactory::get($this->calendarId) : null;

        // --- Non-ISO calendar path --- (withNonIso resolves the overflow option after
        // reading its own fields, per TC39 PrepareCalendarFields-before-GetOptionsObject.)
        if ($calendar !== null) {
            return $this->withNonIso($fields, $options, $calendar);
        }

        // --- ISO calendar path --- TC39 PrepareCalendarFields/ToTemporalTimeRecord read
        // and coerce the partial fields BEFORE GetOptionsObject validates the options
        // argument's type, so a bad field value's RangeError precedes a primitive options
        // TypeError. The overflow keyword (which only drives regulation) is resolved after.
        $year = $this->isoYear;
        if (array_key_exists('year', $fields)) {
            $year = CalendarMath::toFiniteInt($fields['year'], 'PlainDateTime::with() year');
        }

        $month = $this->isoMonth;
        $hasMonth = array_key_exists('month', $fields);
        $hasMonthCode = array_key_exists('monthCode', $fields);
        if ($hasMonthCode) {
            // MonthCode::validate: non-string TYPE => TypeError, ill-formed STRING =>
            // RangeError (type-then-syntax, before month suitability is resolved).
            $month = CalendarMath::monthCodeToMonth(MonthCode::validate($fields['monthCode']));
        }
        if ($hasMonth) {
            $newMonth = CalendarMath::toFiniteInt($fields['month'], 'PlainDateTime::with() month');
            if ($hasMonthCode && $newMonth !== $month) {
                throw new RangeError('Conflicting month and monthCode fields.');
            }
            $month = $newMonth;
        }

        $day = $this->isoDay;
        if (array_key_exists('day', $fields)) {
            $day = CalendarMath::toFiniteInt($fields['day'], 'PlainDateTime::with() day');
        }

        // Merge time fields.
        [$h, $min, $sec, $ms, $us, $ns] = $this->mergeTimeFields($fields);

        // month < 1 and day < 1 are always invalid (cannot constrain below minimum).
        if ($month < 1) {
            throw new RangeError("Invalid month {$month}: must be at least 1.");
        }
        if ($day < 1) {
            throw new RangeError("Invalid day {$day}: must be at least 1.");
        }

        // GetOptionsObject + GetTemporalOverflowOption: explicit null / primitive /
        // Symbol => TypeError; omitted ([]) defaults to 'constrain'.
        $overflow = Options::overflowFromValue($options);

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

        return new self($year, $month, $day, $h, $min, $sec, $ms, $us, $ns, $this->calendarId);
    }

    /**
     * Implements with() for non-ISO calendars following TC39 CalendarDateMergeFields.
     *
     * Handles era/eraYear, monthCode defaults, and month/monthCode conflict
     * resolution, then carries through unchanged time fields. Resolves the overflow
     * option AFTER reading its own fields, matching TC39's PrepareCalendarFields-
     * before-GetOptionsObject ordering.
     *
     * @param array<array-key,mixed> $fields
     * @param Internal\Calendar\CalendarProtocol $calendar
     */
    private function withNonIso(array $fields, mixed $options, Internal\Calendar\CalendarProtocol $calendar): self
    {
        $hasYear = array_key_exists('year', $fields);
        $hasEra = array_key_exists('era', $fields);
        $hasEraYear = array_key_exists('eraYear', $fields);
        $hasMonth = array_key_exists('month', $fields);
        $hasMonthCode = array_key_exists('monthCode', $fields);

        // Chinese/Dangi have no eras — providing era or eraYear is always a TypeError.
        if (($hasEra || $hasEraYear) && in_array($this->calendarId, ['chinese', 'dangi'], strict: true)) {
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
        $year = $this->year;
        if ($hasYear) {
            $year = CalendarMath::toFiniteInt($fields['year'], 'PlainDateTime::with() year');
        } elseif ($hasEra) {
            $resolved = CalendarMath::resolveYearFromEra(
                $calendar,
                $fields['era'],
                $fields['eraYear'],
                'PlainDateTime::with()',
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
            // MonthCode::validate: non-string TYPE => TypeError, ill-formed STRING => RangeError.
            $monthCode = MonthCode::validate($fields['monthCode']);
            $useMonthCode = true;
        }
        if ($hasMonth) {
            $month = CalendarMath::toFiniteInt($fields['month'], 'PlainDateTime::with() month');
            // Validate month/monthCode conflict.
            if ($hasMonthCode) {
                /** @var string $monthCode */
                $monthFromCode = $calendar->monthCodeToMonth($monthCode, $year);
                if ($month !== $monthFromCode) {
                    throw new RangeError('Conflicting month and monthCode fields.');
                }
            }
            $useMonthCode = false; // explicit month takes precedence
        }
        if (!$hasMonth && !$hasMonthCode) {
            // Default: preserve current monthCode.
            $monthCode = $this->monthCode;
            $useMonthCode = true;
        }

        $day = $this->day;
        if (array_key_exists('day', $fields)) {
            $day = CalendarMath::toFiniteInt($fields['day'], 'PlainDateTime::with() day');
        }

        if ($day < 1) {
            throw new RangeError("Invalid day {$day}: must be at least 1.");
        }

        // Merge (read/coerce) time fields before resolving options, so a bad time field's
        // RangeError precedes a primitive options TypeError (ToTemporalTimeRecord first).
        [$h, $min, $sec, $ms, $us, $ns] = $this->mergeTimeFields($fields);

        // GetOptionsObject + GetTemporalOverflowOption: resolved after the fields have
        // been read/coerced (PrepareCalendarFields precedes GetOptionsObject in TC39).
        $overflow = Options::overflowFromValue($options);

        if ($useMonthCode && $monthCode !== null) {
            [$isoY, $isoM, $isoD] = $calendar->calendarToIsoFromMonthCode($year, $monthCode, $day, $overflow);
        } else {
            /** @var int $month */
            if ($month < 1) {
                throw new RangeError("Invalid month {$month}: must be at least 1.");
            }
            [$isoY, $isoM, $isoD] = $calendar->calendarToIso($year, $month, $day, $overflow);
        }

        // Constrain time fields if needed.
        if ($overflow === 'constrain') {
            $h = max(0, min(23, $h));
            $min = max(0, min(59, $min));
            $sec = max(0, min(59, $sec));
            $ms = max(0, min(999, $ms));
            $us = max(0, min(999, $us));
            $ns = max(0, min(999, $ns));
        }

        return new self($isoY, $isoM, $isoD, $h, $min, $sec, $ms, $us, $ns, $this->calendarId);
    }

    /**
     * Extracts time fields from $fields, defaulting to the current instance values.
     *
     * @param array<array-key,mixed> $fields
     * @return array{int,int,int,int,int,int} [hour, minute, second, ms, us, ns]
     */
    private function mergeTimeFields(array $fields): array
    {
        $h = $this->hour;
        $min = $this->minute;
        $sec = $this->second;
        $ms = $this->millisecond;
        $us = $this->microsecond;
        $ns = $this->nanosecond;

        if (array_key_exists('hour', $fields)) {
            $h = CalendarMath::toFiniteInt($fields['hour'], 'PlainDateTime::with() hour');
        }
        if (array_key_exists('minute', $fields)) {
            $min = CalendarMath::toFiniteInt($fields['minute'], 'PlainDateTime::with() minute');
        }
        if (array_key_exists('second', $fields)) {
            $sec = CalendarMath::toFiniteInt($fields['second'], 'PlainDateTime::with() second');
        }
        if (array_key_exists('millisecond', $fields)) {
            $ms = CalendarMath::toFiniteInt($fields['millisecond'], 'PlainDateTime::with() millisecond');
        }
        if (array_key_exists('microsecond', $fields)) {
            $us = CalendarMath::toFiniteInt($fields['microsecond'], 'PlainDateTime::with() microsecond');
        }
        if (array_key_exists('nanosecond', $fields)) {
            $ns = CalendarMath::toFiniteInt($fields['nanosecond'], 'PlainDateTime::with() nanosecond');
        }

        return [$h, $min, $sec, $ms, $us, $ns];
    }

    /**
     * Returns a new PlainDateTime with the given duration added.
     *
     * @param Duration|string|array<array-key, mixed>|object $duration
     * @param array<array-key, mixed>|object                 $options ['overflow' => 'constrain'|'reject']
     * @psalm-api
     */
    public function add(string|array|object $duration, array|object $options = []): self
    {
        $dur = $duration instanceof Duration ? $duration : Duration::from($duration);
        return $this->addDuration(1, $dur, $options);
    }

    /**
     * Returns a new PlainDateTime with the given duration subtracted.
     *
     * @param Duration|string|array<array-key, mixed>|object $duration
     * @param array<array-key, mixed>|object                 $options ['overflow' => 'constrain'|'reject']
     * @psalm-api
     */
    public function subtract(string|array|object $duration, array|object $options = []): self
    {
        $dur = $duration instanceof Duration ? $duration : Duration::from($duration);
        return $this->addDuration(-1, $dur, $options);
    }

    /**
     * Returns the Duration from $other to this datetime (this − other).
     *
     * Default largestUnit is 'day' (matches TC39 PlainDateTime spec).
     *
     * @param self|string|array<array-key, mixed>|object $other   PlainDateTime or ISO 8601 datetime string.
     * @param array<array-key, mixed>|object|null $options ['largestUnit' => ..., 'smallestUnit' => ..., 'roundingMode' => ..., 'roundingIncrement' => ...]
     * @psalm-api
     */
    public function since(string|array|object $other, array|object|null $options = null): Duration
    {
        $o = $other instanceof self ? $other : self::from($other);
        if ($this->calendarId !== $o->calendarId) {
            throw new RangeError(
                "Cannot compute since() between different calendars: \"{$this->calendarId}\" and \"{$o->calendarId}\".",
            );
        }
        return self::diffDateTime($this, $o, 'since', $options);
    }

    /**
     * Returns the Duration from this datetime to $other (other − this).
     *
     * @param self|string|array<array-key, mixed>|object $other   PlainDateTime or ISO 8601 datetime string.
     * @param array<array-key, mixed>|object|null $options ['largestUnit' => ..., 'smallestUnit' => ..., 'roundingMode' => ..., 'roundingIncrement' => ...]
     * @psalm-api
     */
    public function until(string|array|object $other, array|object|null $options = null): Duration
    {
        $o = $other instanceof self ? $other : self::from($other);
        if ($this->calendarId !== $o->calendarId) {
            throw new RangeError(
                "Cannot compute until() between different calendars: \"{$this->calendarId}\" and \"{$o->calendarId}\".",
            );
        }
        return self::diffDateTime($this, $o, 'until', $options);
    }

    /**
     * Returns a new PlainDateTime rounded to the given unit and increment.
     *
     * @param string|array<array-key, mixed>|object $options string smallestUnit or array with keys:
     *   - smallestUnit (required): 'day'|'hour'|'minute'|'second'|'millisecond'|'microsecond'|'nanosecond'
     *   - roundingMode (default 'halfExpand')
     *   - roundingIncrement (default 1)
     * @throws TypeError if options are not a string, array, or object.
     * @throws RangeError for invalid option values.
     * @psalm-api
     */
    public function round(string|array|object $options): self
    {
        if (is_string($options)) {
            $options = ['smallestUnit' => $options];
        } elseif (is_object($options)) {
            // TC39: if options is undefined, throw TypeError (required arg).
            if ($options instanceof \Stringable) {
                $str = (string) $options; // JsSymbol: throws; JsUndefined: returns 'undefined'
                if ($str === 'undefined') {
                    throw new TypeError('PlainDateTime::round() requires a non-undefined options argument.');
                }
            }
            $options = Options::requireObject($options);
        }

        /** @var mixed $suRaw */
        $suRaw = $options['smallestUnit'] ?? null;
        if ($suRaw === null) {
            throw new RangeError('Temporal\\PlainDateTime::round() requires smallestUnit.');
        }
        $suRaw = Options::coerceEnumOption($suRaw, 'smallestUnit');

        // ns-per-unit and max increment (exclusive) for each unit.
        // For 'day', max = 1 (only increment 1 is valid).
        $unitMap = [
            'day' => [self::NS_PER_DAY, 2], // only increment=1 is valid for day
            'days' => [self::NS_PER_DAY, 2],
            'hour' => [self::NS_PER_HOUR, 24],
            'hours' => [self::NS_PER_HOUR, 24],
            'minute' => [self::NS_PER_MINUTE, 60],
            'minutes' => [self::NS_PER_MINUTE, 60],
            'second' => [EpochLimits::NS_PER_SECOND, 60],
            'seconds' => [EpochLimits::NS_PER_SECOND, 60],
            'millisecond' => [self::NS_PER_MS, 1_000],
            'milliseconds' => [self::NS_PER_MS, 1_000],
            'microsecond' => [self::NS_PER_US, 1_000],
            'microseconds' => [self::NS_PER_US, 1_000],
            'nanosecond' => [1, 1_000],
            'nanoseconds' => [1, 1_000],
        ];
        if (!array_key_exists($suRaw, $unitMap)) {
            throw new RangeError("Invalid smallestUnit \"{$suRaw}\" for Temporal\\PlainDateTime::round().");
        }
        [$nsPerUnit, $maxIncrement] = $unitMap[$suRaw];

        $roundingMode = 'halfExpand';
        if (array_key_exists('roundingMode', $options) && $options['roundingMode'] !== null) {
            $roundingMode = Options::coerceEnumOption($options['roundingMode'], 'roundingMode');
        }

        $increment = 1;
        if (array_key_exists('roundingIncrement', $options) && $options['roundingIncrement'] !== null) {
            /** @psalm-suppress MixedArgument */
            $rawIncrement = (int) $options['roundingIncrement'];
            if ($rawIncrement < 1) {
                throw new RangeError('roundingIncrement must be a positive integer.');
            }
            $increment = $rawIncrement;
        }
        // Increment must be strictly less than maxIncrement (for sub-day) and must divide it.
        // For 'day', increment must be exactly 1 (maxIncrement = 1).
        if ($increment >= $maxIncrement || ($maxIncrement % $increment) !== 0) {
            throw new RangeError("roundingIncrement {$increment} is invalid for unit \"{$suRaw}\".");
        }

        // Total ns since epoch midnight: use Julian Day Number to count days.
        $jdn = CalendarMath::toJulianDay($this->isoYear, $this->isoMonth, $this->isoDay);
        $timeNs = self::timeToNs(
            $this->hour,
            $this->minute,
            $this->second,
            $this->millisecond,
            $this->microsecond,
            $this->nanosecond,
        );

        // For day rounding, increment wraps in units of a full day relative to the
        // day boundary (midnight), so we simply round the time-of-day ns.
        $nsIncrement = $nsPerUnit * $increment;

        // Round time-of-day ns (always non-negative) using the given mode.
        $roundedTimeNs = self::roundPositiveNs($timeNs, $nsIncrement, $roundingMode);

        // Determine how many days of overflow result from rounding (0 or 1).
        $overflowDays = intdiv(num1: $roundedTimeNs, num2: self::NS_PER_DAY);
        $newTimeNs = $roundedTimeNs % self::NS_PER_DAY;

        $newJdn = $jdn + $overflowDays;

        // Range check.
        $minJdn = CalendarMath::toJulianDay(-271_821, 4, 19);
        $maxJdn = CalendarMath::toJulianDay(275_760, 9, 13);
        if ($newJdn < $minJdn || $newJdn > $maxJdn) {
            throw new RangeError('PlainDateTime rounding result is outside the representable range.');
        }

        [$newYear, $newMonth, $newDay] = CalendarMath::fromJulianDay($newJdn);

        $h = intdiv(num1: $newTimeNs, num2: self::NS_PER_HOUR);
        $rem = $newTimeNs % self::NS_PER_HOUR;
        $min = intdiv(num1: $rem, num2: self::NS_PER_MINUTE);
        $rem %= self::NS_PER_MINUTE;
        $sec = intdiv(num1: $rem, num2: EpochLimits::NS_PER_SECOND);
        $rem %= EpochLimits::NS_PER_SECOND;
        $ms = intdiv(num1: $rem, num2: self::NS_PER_MS);
        $rem %= self::NS_PER_MS;
        $us = intdiv(num1: $rem, num2: self::NS_PER_US);
        $ns = $rem % self::NS_PER_US;

        return new self($newYear, $newMonth, $newDay, $h, $min, $sec, $ms, $us, $ns);
    }

    /**
     * Returns true if this PlainDateTime represents the same date and time as $other.
     *
     * @param self|string|array<array-key, mixed>|object $other A PlainDateTime or ISO 8601 datetime string.
     * @psalm-api
     */
    public function equals(string|array|object $other): bool
    {
        $o = $other instanceof self ? $other : self::from($other);
        return (
            $this->isoYear === $o->isoYear
            && $this->isoMonth === $o->isoMonth
            && $this->isoDay === $o->isoDay
            && $this->hour === $o->hour
            && $this->minute === $o->minute
            && $this->second === $o->second
            && $this->millisecond === $o->millisecond
            && $this->microsecond === $o->microsecond
            && $this->nanosecond === $o->nanosecond
            && $this->calendarId === $o->calendarId
        );
    }

    /**
     * Returns an ISO 8601 datetime string: YYYY-MM-DDTHH:MM:SS[.fraction][calendar?]
     *
     * Options:
     *   - calendarName: 'auto' (default) | 'always' | 'never' | 'critical'
     *   - fractionalSecondDigits: 'auto' (default) | 0–9
     *
     * @param array<array-key, mixed>|object|null $options null or array of options.
     * @throws RangeError for invalid option values.
     * @psalm-api
     */
    #[\Override]
    public function toString(array|object|null $options = []): string
    {
        // GetOptionsObject: PHP null (the spec layer's representation of an omitted/
        // `undefined` options argument) resolves to the empty-array default; a Symbol
        // sentinel is rejected; a bag is normalized to an array.
        $options = Options::requireObject($options ?? []);

        $calendarName = 'auto';
        $digits = -2; // -2 = 'auto'
        $isMinute = false;
        $roundMode = 'trunc';

        if ($options !== []) {
            if (array_key_exists('calendarName', $options)) {
                $cn = Options::coerceEnumOption($options['calendarName'], 'calendarName');
                $calendarName = $cn;
            }

            // fractionalSecondDigits
            if (array_key_exists('fractionalSecondDigits', $options)) {
                $fsd = Options::fractionalSecondDigits($options['fractionalSecondDigits']);
                if ($fsd !== null) {
                    $digits = $fsd;
                }
            }

            // smallestUnit overrides fractionalSecondDigits.
            if (array_key_exists('smallestUnit', $options) && $options['smallestUnit'] !== null) {
                $su = Options::coerceEnumOption($options['smallestUnit'], 'smallestUnit');
                [$digits, $isMinute] = match ($su) {
                    'minute', 'minutes' => [-1, true],
                    'second', 'seconds' => [0, false],
                    'millisecond', 'milliseconds' => [3, false],
                    'microsecond', 'microseconds' => [6, false],
                    'nanosecond', 'nanoseconds' => [9, false],
                    default => throw new RangeError("Invalid smallestUnit \"{$su}\"."),
                };
            }

            // roundingMode (default 'trunc' for toString).
            if (array_key_exists('roundingMode', $options) && $options['roundingMode'] !== null) {
                $rm = Options::coerceEnumOption($options['roundingMode'], 'roundingMode');
                $roundMode = $rm;
            }
        }

        // Compute rounding increment in nanoseconds.
        if ($isMinute) {
            $increment = 60_000_000_000;
        } else {
            $increment = match ($digits) {
                0 => 1_000_000_000,
                1 => 100_000_000,
                2 => 10_000_000,
                3 => 1_000_000,
                4 => 100_000,
                5 => 10_000,
                6 => 1_000,
                7 => 100,
                8 => 10,
                default => 1,
            };
        }

        // Round time-of-day nanoseconds.
        $timeNs = self::timeToNs(
            $this->hour,
            $this->minute,
            $this->second,
            $this->millisecond,
            $this->microsecond,
            $this->nanosecond,
        );

        $roundedTimeNs = $increment === 1 ? $timeNs : self::roundPositiveNs($timeNs, $increment, $roundMode);

        // Determine overflow days from rounding (0 or 1).
        $overflowDays = intdiv(num1: $roundedTimeNs, num2: self::NS_PER_DAY);
        $newTimeNs = $roundedTimeNs % self::NS_PER_DAY;

        // Apply overflow days to date via Julian Day Number.
        $jdn = CalendarMath::toJulianDay($this->isoYear, $this->isoMonth, $this->isoDay) + $overflowDays;

        // Range check the rounded result.
        $minJdn = CalendarMath::toJulianDay(-271_821, 4, 19);
        $maxJdn = CalendarMath::toJulianDay(275_760, 9, 13);
        if ($jdn < $minJdn || $jdn > $maxJdn) {
            throw new RangeError('PlainDateTime rounding result is outside the representable range.');
        }
        // Midnight at the min boundary is outside the range.
        if ($jdn === $minJdn && $newTimeNs === 0) {
            throw new RangeError('PlainDateTime rounding result is outside the representable range.');
        }

        [$year, $month, $day] = CalendarMath::fromJulianDay($jdn);

        $hour = intdiv(num1: $newTimeNs, num2: self::NS_PER_HOUR);
        $rem = $newTimeNs % self::NS_PER_HOUR;
        $min = intdiv(num1: $rem, num2: self::NS_PER_MINUTE);
        $rem %= self::NS_PER_MINUTE;
        $sec = intdiv(num1: $rem, num2: EpochLimits::NS_PER_SECOND);
        $rem %= EpochLimits::NS_PER_SECOND;

        $subNs = $rem;

        // Format date part.
        if ($year < 0) {
            $yearStr = sprintf('-%06d', abs($year));
        } elseif ($year > 9999) {
            $yearStr = sprintf('+%06d', $year);
        } else {
            $yearStr = sprintf('%04d', $year);
        }
        $dateStr = sprintf('%s-%02d-%02d', $yearStr, $month, $day);

        // Format time part.
        if ($isMinute) {
            $timeStr = sprintf('%02d:%02d', $hour, $min);
        } elseif ($digits === -2) {
            // 'auto': strip trailing zeros; omit fraction entirely if zero.
            $timeBase = sprintf('%02d:%02d:%02d', $hour, $min, $sec);
            if ($subNs === 0) {
                $timeStr = $timeBase;
            } else {
                $fraction = rtrim(sprintf('%09d', $subNs), characters: '0');
                $timeStr = "{$timeBase}.{$fraction}";
            }
        } elseif ($digits === 0) {
            $timeStr = sprintf('%02d:%02d:%02d', $hour, $min, $sec);
        } else {
            $fraction = substr(string: sprintf('%09d', $subNs), offset: 0, length: $digits);
            $timeStr = sprintf('%02d:%02d:%02d.%s', $hour, $min, $sec, $fraction);
        }

        $base = "{$dateStr}T{$timeStr}";

        return match ($calendarName) {
            'auto' => $this->calendarId !== 'iso8601' ? sprintf('%s[u-ca=%s]', $base, $this->calendarId) : $base,
            'never' => $base,
            'always' => sprintf('%s[u-ca=%s]', $base, $this->calendarId),
            'critical' => sprintf('%s[!u-ca=%s]', $base, $this->calendarId),
            default => throw new RangeError("Invalid calendarName value: \"{$calendarName}\"."),
        };
    }

    /**
     * Returns the date part as a PlainDate.
     *
     * @psalm-api
     */
    public function toPlainDate(): PlainDate
    {
        return new PlainDate($this->isoYear, $this->isoMonth, $this->isoDay, $this->calendarId);
    }

    /**
     * Returns the time part as a PlainTime.
     *
     * @psalm-api
     */
    public function toPlainTime(): PlainTime
    {
        return new PlainTime(
            $this->hour,
            $this->minute,
            $this->second,
            $this->millisecond,
            $this->microsecond,
            $this->nanosecond,
        );
    }

    /**
     * Returns a new PlainDateTime with the time part replaced by $time.
     *
     * When called with no argument, the time defaults to midnight (00:00:00).
     *
     * @param PlainTime|string|array<array-key, mixed>|object|int $time
     * @psalm-api
     */
    public function withPlainTime(string|array|object|int $time = PHP_INT_MIN): self
    {
        // PHP_INT_MIN sentinel distinguishes no-argument from explicit null.
        if ($time === PHP_INT_MIN) {
            // No argument provided: default to midnight.
            return new self($this->isoYear, $this->isoMonth, $this->isoDay, 0, 0, 0, 0, 0, 0, $this->calendarId);
        }
        if (is_int($time)) {
            throw new TypeError(sprintf(
                'PlainDateTime::withPlainTime() expects a PlainTime, ISO 8601 time string, or property-bag array; got int (%d).',
                $time,
            ));
        }
        $t = $time instanceof PlainTime ? $time : PlainTime::from($time);
        return new self(
            $this->isoYear,
            $this->isoMonth,
            $this->isoDay,
            $t->hour,
            $t->minute,
            $t->second,
            $t->millisecond,
            $t->microsecond,
            $t->nanosecond,
            $this->calendarId,
        );
    }

    /**
     * Returns a ZonedDateTime by interpreting this date-time in the given timezone.
     *
     * @param array<array-key, mixed>|object|null $options Options bag; supports 'disambiguation' key.
     * @throws RangeError if the timezone or disambiguation option is invalid,
     *                                  or the resulting instant is out of range.
     * @psalm-api
     */
    public function toZonedDateTime(string $timeZone, array|object|null $options = []): ZonedDateTime
    {
        // GetOptionsObject: PHP null (the spec layer's representation of an omitted/
        // `undefined` options argument) resolves to the empty-array default; a Symbol
        // sentinel is rejected; a bag is normalized to an array.
        $opts = Options::requireObject($options ?? []);

        // Validate disambiguation option if present.
        $disambiguation = 'compatible';
        if (array_key_exists('disambiguation', $opts)) {
            $disamb = Options::coerceEnumOption($opts['disambiguation'], 'disambiguation');
            if (!in_array($disamb, ['compatible', 'earlier', 'later', 'reject'], strict: true)) {
                throw new RangeError(
                    'PlainDateTime::toZonedDateTime() disambiguation must be one of: compatible, earlier, later, reject.',
                );
            }
            $disambiguation = $disamb;
        }

        $normalTzId = TimeZoneHelper::normalizeTimezoneId($timeZone);

        // Compute wall-clock seconds from epoch days + time-of-day (avoids DateTimeImmutable
        // year-formatting issues with extended years > 9999 or negative years).
        $epochDays = CalendarMath::toJulianDay($this->isoYear, $this->isoMonth, $this->isoDay) - 2_440_588;
        $wallSec = ($epochDays * 86_400) + ($this->hour * 3600) + ($this->minute * 60) + $this->second;
        $epochSec = TimeZoneHelper::wallSecToEpochSec($wallSec, $normalTzId, $disambiguation);

        $subNs = ($this->millisecond * self::NS_PER_MS) + ($this->microsecond * self::NS_PER_US) + $this->nanosecond;

        // Route through createFromEpochParts(): it performs the Instant range check
        // (throwing RangeError for |epochNs| > 8.64e21) AND preserves the
        // true over-int64 epoch in trueEpochSec/trueSubNs, so later ops on a max/min-year
        // ZDT decode the real instant and throw correctly when the arithmetic overflows.
        return ZonedDateTime::createFromEpochParts($epochSec, $subNs, $normalTzId, $this->calendarId);
    }

    /**
     * Returns a new PlainDateTime with the specified calendar.
     *
     * @throws RangeError if the calendar is unsupported.
     * @psalm-api
     */
    public function withCalendar(string $calendar): self
    {
        $calId = CalendarFactory::extractCalendarFromString($calendar);
        return new self(
            $this->isoYear,
            $this->isoMonth,
            $this->isoDay,
            $this->hour,
            $this->minute,
            $this->second,
            $this->millisecond,
            $this->microsecond,
            $this->nanosecond,
            $calId,
        );
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Parses an ISO 8601 string into a PlainDateTime.
     *
     * Accepts:
     *   - Full datetime: date T time [offset?] [annotations?]
     *       Time formats: HH:MM[:SS[.frac]] (extended) or HHMM[SS[.frac]] (basic)
     *       Separator style must be consistent within time (no mixing).
     *       UTC offset (±HH:MM etc.) is accepted and ignored.
     *       UTC designator Z is rejected (PlainDateTime has no timezone).
     *   - Date-only: YYYY-MM-DD or ±YYYYYY-MM-DD [annotations?] — time defaults to 00:00:00.
     *   - Bracket annotations: validated per TC39 rules.
     *
     * @throws RangeError for invalid or out-of-range values.
     */
    private static function fromString(string $s): self
    {
        if ($s === '') {
            throw new RangeError('PlainDateTime::from() received an empty string.');
        }
        // Reject non-ASCII minus sign (U+2212).
        if (str_contains($s, "\u{2212}")) {
            throw new RangeError("PlainDateTime::from() cannot parse \"{$s}\": non-ASCII minus sign is not allowed.");
        }
        // Reject more than 9 fractional-second digits.
        if (preg_match('/[.,]\d{10,}/', $s) === 1) {
            throw new RangeError(
                "PlainDateTime::from() cannot parse \"{$s}\": fractional seconds may have at most 9 digits.",
            );
        }

        // UTC offset sub-pattern (Z excluded — captured separately).
        $offsetHH = '(?:[01]\d|2[0-3])';
        $offsetMM = '[0-5]\d';
        $offsetSS = '[0-5]\d';
        $offsetNonZ = sprintf(
            '[+-]%s(?::%s(?::%s(?:[.,]\d+)?)?|%s(?:%s(?:[.,]\d+)?)?)?',
            $offsetHH,
            $offsetMM,
            $offsetSS,
            $offsetMM,
            $offsetSS,
        );

        // Full datetime pattern (T/t/space separator required).
        // Time section: three mutually exclusive branches to enforce separator consistency:
        //   extended = HH:MM[:SS[.frac]]   (groups 3–6)
        //   basic    = HHMM[SS[.frac]]     (groups 7–10)
        //   hour-only = HH                 (group 13)
        // Group 11 captures a Z designator (which is then rejected).
        // Group 12 captures bracket annotations.
        // groups: 1=year, 2=dateRest, 3-6=ext time, 7-10=basic time, 13=hour-only, 11=Z, 12=annotations
        $dtPattern = sprintf(
            '/^([+-]\d{6}|\d{4})(-\d{2}-\d{2}|\d{4})[Tt ](?:(\d{2}):(\d{2})(?::(\d{2})([.,]\d+)?)?|(\d{2})(\d{2})(?:(\d{2})([.,]\d+)?)?|(\d{2}))(Z)?(?:%s)?((?:\[[^\]]*\])*)$/i',
            $offsetNonZ,
        );

        // Date-only pattern: YYYY-MM-DD or ±YYYYYY-MM-DD or YYYYMMDD, plus optional annotations.
        // Groups: 1=year, 2=dateRest, 3=annotations.
        $dateOnlyPattern = '/^([+-]\d{6}|\d{4})(-\d{2}-\d{2}|\d{4})((?:\[[^\]]*\])*)$/i';

        /** @var list<string> $m */
        $m = [];
        $hourNum = 0;
        $minNum = 0;
        $secNum = 0;
        $fracRaw = '';

        if (preg_match($dtPattern, $s, $m) === 1) {
            // UTC designator Z is not allowed for PlainDateTime.
            if ($m[12] !== '') {
                throw new RangeError("PlainDateTime::from() cannot parse \"{$s}\": UTC designator (Z) is not allowed.");
            }
            $yearRaw = $m[1];
            $dateRest = $m[2];
            $annotations = $m[13];
            // Determine which time branch matched (extended uses group 3, basic uses group 7, hour-only uses group 11).
            if ($m[3] !== '') {
                // Extended format: HH:MM[:SS[.frac]]
                $hourNum = (int) $m[3];
                $minNum = (int) $m[4];
                $secNum = $m[5] !== '' ? (int) $m[5] : 0;
                $fracRaw = $m[6];
            } elseif ($m[7] !== '') {
                // Basic format: HHMM[SS[.frac]]
                $hourNum = (int) $m[7];
                $minNum = (int) $m[8];
                $secNum = $m[9] !== '' ? (int) $m[9] : 0;
                $fracRaw = $m[10];
            } else {
                // Hour-only format: HH
                $hourNum = (int) $m[11];
                $minNum = 0;
                $secNum = 0;
                $fracRaw = '';
            }
            // Leap second 60 → 59.
            if ($secNum === 60) {
                $secNum = 59;
            }
            // Validate time ranges.
            if ($hourNum > 23) {
                throw new RangeError("PlainDateTime::from() cannot parse \"{$s}\": hour {$hourNum} out of range.");
            }
            if ($minNum > 59) {
                throw new RangeError("PlainDateTime::from() cannot parse \"{$s}\": minute {$minNum} out of range.");
            }
            if ($secNum > 59) {
                throw new RangeError("PlainDateTime::from() cannot parse \"{$s}\": second {$secNum} out of range.");
            }
        } elseif (preg_match($dateOnlyPattern, $s, $m) === 1) {
            // Date-only string: time defaults to midnight (all zeros).
            $yearRaw = $m[1];
            $dateRest = $m[2];
            $annotations = $m[3];
        } else {
            throw new RangeError("PlainDateTime::from() cannot parse \"{$s}\": invalid ISO 8601 datetime string.");
        }

        // Reject minus-zero extended year (-000000).
        if (preg_match('/^-0{6}$/', $yearRaw) === 1) {
            throw new RangeError(
                "PlainDateTime::from() cannot parse \"{$s}\": cannot use negative zero as extended year.",
            );
        }

        // Parse date components.
        if (!str_starts_with($dateRest, '-')) {
            $month = (int) substr(string: $dateRest, offset: 0, length: 2);
            $day = (int) substr(string: $dateRest, offset: 2, length: 2);
        } else {
            $month = (int) substr(string: $dateRest, offset: 1, length: 2);
            $day = (int) substr(string: $dateRest, offset: 4, length: 2);
        }
        $year = (int) $yearRaw;

        // Validate bracket annotations and extract calendar ID.
        $calendarId = CalendarMath::validateAnnotations($annotations, $s);

        // Decompose sub-second nanoseconds.
        $subNs = $fracRaw !== '' ? self::parseFraction($fracRaw) : 0;
        $ms = intdiv(num1: $subNs, num2: self::NS_PER_MS);
        $us = intdiv(num1: $subNs % self::NS_PER_MS, num2: self::NS_PER_US);
        $ns = $subNs % self::NS_PER_US;

        return new self($year, $month, $day, $hourNum, $minNum, $secNum, $ms, $us, $ns, $calendarId ?? 'iso8601');
    }

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
    private static function fromPropertyBag(array $bag, string $overflow = 'constrain'): self
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
            return new self($isoY, $isoM, $isoD, $h, $min, $sec, $ms, $us, $ns, $calendarId);
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

        return new self($year, $month, $day, $h, $min, $sec, $ms, $us, $ns, $calendarId ?? 'iso8601');
    }

    /**
     * Core implementation for since() and until().
     *
     * TC39 CalendarDateUntil is always called as (temporalDate, other). For
     * "since", the final result is negated.
     *
     * @param string $operation 'since' or 'until'
     * @param array<array-key, mixed>|object|null $options ['largestUnit' => ..., 'smallestUnit' => ..., 'roundingMode' => ..., 'roundingIncrement' => ...]
     */
    private static function diffDateTime(
        self $temporalDate,
        self $other,
        string $operation,
        array|object|null $options,
    ): Duration {
        /** @var list<string> $validUnits */
        static $validUnits = [
            'auto',
            'day',
            'days',
            'week',
            'weeks',
            'month',
            'months',
            'year',
            'years',
            'hour',
            'hours',
            'minute',
            'minutes',
            'second',
            'seconds',
            'millisecond',
            'milliseconds',
            'microsecond',
            'microseconds',
            'nanosecond',
            'nanoseconds',
        ];
        /** @var array<string, int> $unitRank */
        static $unitRank = [
            'year' => 9,
            'years' => 9,
            'month' => 8,
            'months' => 8,
            'week' => 7,
            'weeks' => 7,
            'day' => 6,
            'days' => 6,
            'auto' => 6,
            'hour' => 5,
            'hours' => 5,
            'minute' => 4,
            'minutes' => 4,
            'second' => 3,
            'seconds' => 3,
            'millisecond' => 2,
            'milliseconds' => 2,
            'microsecond' => 1,
            'microseconds' => 1,
            'nanosecond' => 0,
            'nanoseconds' => 0,
        ];

        $largestUnit = 'day'; // default per TC39 PlainDateTime spec
        $largestUnitExplicit = false;
        $smallestUnit = null;
        $roundingMode = 'trunc';
        $roundingIncrement = 1;

        if ($options !== null) {
            $opts = Options::requireObject($options);

            if (array_key_exists('largestUnit', $opts)) {
                /** @var mixed $lu */
                $lu = $opts['largestUnit'];
                if ($lu !== null) {
                    $lu = Options::coerceEnumOption($lu, 'largestUnit');
                }
                if (is_string($lu)) {
                    if (!in_array($lu, $validUnits, strict: true)) {
                        throw new RangeError("Invalid largestUnit value: \"{$lu}\".");
                    }
                    $largestUnit = $lu;
                    $largestUnitExplicit = true;
                }
            }

            if (array_key_exists('roundingIncrement', $opts)) {
                /** @var mixed $ri */
                $ri = $opts['roundingIncrement'];
                if ($ri !== null) {
                    $roundingIncrement = CalendarMath::validateRoundingIncrement($ri);
                }
            }

            if (array_key_exists('roundingMode', $opts)) {
                /** @var mixed $rm */
                $rm = $opts['roundingMode'];
                if ($rm !== null) {
                    $rm = Options::coerceEnumOption($rm, 'roundingMode');
                }
                if (is_string($rm)) {
                    $roundingMode = Options::roundingMode($rm);
                }
            }

            if (array_key_exists('smallestUnit', $opts)) {
                /** @var mixed $su */
                $su = $opts['smallestUnit'];
                if ($su !== null) {
                    $su = Options::coerceEnumOption($su, 'smallestUnit');
                }
                if (is_string($su)) {
                    if (!in_array($su, $validUnits, strict: true)) {
                        throw new RangeError("Invalid smallestUnit value: \"{$su}\".");
                    }
                    $smallestUnit = $su;
                }
            }
        }

        if ($smallestUnit === null) {
            $smallestUnit = 'nanosecond';
        }

        // Normalize plural/auto to canonical singular.
        $normLargest = match ($largestUnit) {
            'years' => 'year',
            'months' => 'month',
            'weeks' => 'week',
            'days', 'auto' => 'day',
            'hours' => 'hour',
            'minutes' => 'minute',
            'seconds' => 'second',
            'milliseconds' => 'millisecond',
            'microseconds' => 'microsecond',
            'nanoseconds' => 'nanosecond',
            default => $largestUnit,
        };
        $normSmallest = match ($smallestUnit) {
            'years' => 'year',
            'months' => 'month',
            'weeks' => 'week',
            'days', 'auto' => 'day',
            'hours' => 'hour',
            'minutes' => 'minute',
            'seconds' => 'second',
            'milliseconds' => 'millisecond',
            'microseconds' => 'microsecond',
            'nanoseconds' => 'nanosecond',
            default => $smallestUnit,
        };

        $suRank = $unitRank[$normSmallest];
        $luRank = $unitRank[$normLargest];

        if ($suRank > $luRank) {
            if ($largestUnitExplicit) {
                throw new RangeError(
                    "smallestUnit \"{$normSmallest}\" cannot be larger than largestUnit \"{$normLargest}\".",
                );
            }
            $normLargest = $normSmallest;
            $luRank = $suRank;
        }

        // Validate roundingIncrement for time units: must divide evenly into next higher unit.
        if ($roundingIncrement > 1) {
            /** @var array<string, int> $maxIncrementMap */
            static $maxIncrementMap = [
                'hour' => 24,
                'minute' => 60,
                'second' => 60,
                'millisecond' => 1000,
                'microsecond' => 1000,
                'nanosecond' => 1000,
            ];
            $maxInc = $maxIncrementMap[$normSmallest] ?? 0;
            if ($maxInc > 0 && ($roundingIncrement >= $maxInc || ($maxInc % $roundingIncrement) !== 0)) {
                throw new RangeError(
                    "roundingIncrement {$roundingIncrement} does not divide evenly into the next highest unit for \"{$normSmallest}\".",
                );
            }
        }

        // Compute the raw date and time differences: other − temporalDate.
        // Positive when other > temporalDate (the "until" direction).
        $tdJdn = CalendarMath::toJulianDay($temporalDate->isoYear, $temporalDate->isoMonth, $temporalDate->isoDay);
        $otherJdn = CalendarMath::toJulianDay($other->isoYear, $other->isoMonth, $other->isoDay);
        $tdNs = self::timeToNs(
            $temporalDate->hour,
            $temporalDate->minute,
            $temporalDate->second,
            $temporalDate->millisecond,
            $temporalDate->microsecond,
            $temporalDate->nanosecond,
        );
        $otherNs = self::timeToNs(
            $other->hour,
            $other->minute,
            $other->second,
            $other->millisecond,
            $other->microsecond,
            $other->nanosecond,
        );

        $dateDiff = $otherJdn - $tdJdn;
        $timeDiffNs = $otherNs - $tdNs;

        // The overall sign is determined by the combined date+time diff.
        $sign = 0;
        if ($dateDiff > 0 || $dateDiff === 0 && $timeDiffNs > 0) {
            $sign = 1;
        } elseif ($dateDiff < 0 || $timeDiffNs < 0) {
            $sign = -1;
        }

        // For "since", negate the output sign per TC39 spec.
        $outputSign = $operation === 'since' ? -$sign : $sign;

        // Work in the positive direction; assign earlier/later.
        if ($sign >= 0) {
            $earlier = $temporalDate;
            $later = $other;
        } else {
            $earlier = $other;
            $later = $temporalDate;
        }
        $earlierJdn = CalendarMath::toJulianDay($earlier->isoYear, $earlier->isoMonth, $earlier->isoDay);
        $dateDiff = CalendarMath::toJulianDay($later->isoYear, $later->isoMonth, $later->isoDay) - $earlierJdn;
        $timeDiffNs =
            self::timeToNs(
                $later->hour,
                $later->minute,
                $later->second,
                $later->millisecond,
                $later->microsecond,
                $later->nanosecond,
            )
            - self::timeToNs(
                $earlier->hour,
                $earlier->minute,
                $earlier->second,
                $earlier->millisecond,
                $earlier->microsecond,
                $earlier->nanosecond,
            );

        // Borrow one day from the date component when the time part is negative.
        if ($timeDiffNs < 0) {
            $dateDiff--;
            $timeDiffNs += self::NS_PER_DAY;
        }
        // Both $dateDiff and $timeDiffNs are now non-negative.

        $isCalendarLargest = $luRank >= 6; // day or above

        if ($isCalendarLargest) {
            // The adjusted other date after borrowing: earlierJdn + dateDiff.
            $adjOtherJdn = $earlierJdn + $dateDiff;
            [$adjY2, $adjM2, $adjD2] = CalendarMath::fromJulianDay($adjOtherJdn);
            $calId = $temporalDate->calendarId;
            $nonIsoAdjJdn = 0;

            if ($normLargest === 'day') {
                $days = $dateDiff;
                [$years, $months, $weeks] = [0, 0, 0];
            } elseif ($normLargest === 'week') {
                $weeks = intdiv(num1: $dateDiff, num2: 7);
                $days = $dateDiff - ($weeks * 7);
                [$years, $months] = [0, 0];
            } else {
                if ($calId !== 'iso8601') {
                    // For non-ISO calendars, use CalendarDateUntil(temporalDate,
                    // adjustedOther) in (this, other) order per TC39 spec.
                    // Compute the adjusted other JDN by borrowing from the date
                    // component when the time difference and date difference have
                    // different signs.
                    $rawDateDiff = $otherJdn - $tdJdn;
                    $rawTimeDiff = $otherNs - $tdNs;
                    $nonIsoAdjJdn = $otherJdn;
                    if ($rawDateDiff !== 0 && $rawTimeDiff !== 0) {
                        $dateSign = $rawDateDiff > 0 ? 1 : -1;
                        $timeSign = $rawTimeDiff > 0 ? 1 : -1;
                        if ($dateSign !== $timeSign) {
                            // Borrow one day in the direction of the date diff.
                            $nonIsoAdjJdn = $otherJdn - $dateSign;
                        }
                    }
                    [$adjY2b, $adjM2b, $adjD2b] = CalendarMath::fromJulianDay($nonIsoAdjJdn);
                    $cal = CalendarFactory::get($calId);
                    [$years, $months, , $days] = $cal->dateUntil(
                        $temporalDate->isoYear,
                        $temporalDate->isoMonth,
                        $temporalDate->isoDay,
                        $adjY2b,
                        $adjM2b,
                        $adjD2b,
                        $normLargest,
                    );
                    // Take absolute values — the output sign is applied later.
                    $years = abs($years);
                    $months = abs($months);
                    $days = abs($days);
                } else {
                    // ISO calendar: calendarDiff expects (smaller, larger).
                    $receiverIsLater = $sign < 0;
                    [$years, $months, $days] = self::calendarDiff(
                        $earlier->isoYear,
                        $earlier->isoMonth,
                        $earlier->isoDay,
                        $adjY2,
                        $adjM2,
                        $adjD2,
                        $receiverIsLater,
                    );
                    // Convert years to months when largestUnit is 'month'.
                    if ($normLargest === 'month') {
                        $months = ($years * 12) + $months;
                        $years = 0;
                    }
                }
                $weeks = 0;
            }

            $isSmallestCalendar = in_array($normSmallest, ['year', 'month', 'week', 'day'], strict: true);

            // The receiver (temporalDate) is the later date when sign < 0.
            $receiverIsLater = $sign < 0;

            if ($isSmallestCalendar) {
                // Calendar-unit rounding: zero out time and round the calendar part.
                if ($normSmallest === 'year') {
                    $totalMonths = ($years * 12) + $months;
                    $roundedYears = self::roundCalendarYears(
                        $years,
                        $totalMonths,
                        $days,
                        $timeDiffNs,
                        $temporalDate,
                        $roundingIncrement,
                        $roundingMode,
                        $receiverIsLater,
                        $outputSign,
                    );
                    return new Duration(years: $outputSign * $roundedYears);
                }
                if ($normSmallest === 'month') {
                    $totalMonths = ($years * 12) + $months;
                    $roundedMonths = self::roundCalendarMonths(
                        $totalMonths,
                        $days,
                        $timeDiffNs,
                        $temporalDate,
                        $roundingIncrement,
                        $roundingMode,
                        $receiverIsLater,
                        $outputSign,
                    );
                    if ($normLargest === 'year') {
                        $roundedYears = intdiv(num1: $roundedMonths, num2: 12);
                        $roundedMonths -= $roundedYears * 12;
                        return new Duration(years: $outputSign * $roundedYears, months: $outputSign * $roundedMonths);
                    }
                    return new Duration(months: $outputSign * $roundedMonths);
                }
                if ($normSmallest === 'week') {
                    $totalDays = ($weeks * 7) + $days;
                    $weekIncrement = $roundingIncrement * 7;
                    $roundedDays = self::roundDaysWithTime(
                        $totalDays,
                        $timeDiffNs,
                        $weekIncrement,
                        $roundingMode,
                        $outputSign,
                    );
                    // Preserve the years/months from the date difference. Per TC39
                    // NudgeToCalendarUnit (unit=week), the years+months portion is held fixed
                    // (AdjustDateDurationRecord(duration.[[Date]], 0, 0)) and only the
                    // weeks+days remainder is rounded. With largestUnit=month/year these can be
                    // nonzero; dropping them lost a whole month (e.g. P1M weeks..months → 0).
                    // For largestUnit=week they are already 0, so this is a no-op there.
                    return new Duration(
                        years: $outputSign * $years,
                        months: $outputSign * $months,
                        weeks: $outputSign * intdiv(num1: $roundedDays, num2: 7),
                    );
                }
                // normSmallest === 'day'
                $roundedDays = self::roundDaysWithTime(
                    $days,
                    $timeDiffNs,
                    $roundingIncrement,
                    $roundingMode,
                    $outputSign,
                );
                if ($normLargest === 'day') {
                    return new Duration(days: $outputSign * $roundedDays);
                }
                if ($normLargest === 'week') {
                    $totalDays = ($weeks * 7) + $roundedDays;
                    $roundedWeeks = intdiv(num1: $totalDays, num2: 7);
                    $remDays = $totalDays - ($roundedWeeks * 7);
                    return new Duration(weeks: $outputSign * $roundedWeeks, days: $outputSign * $remDays);
                }
                return new Duration(
                    years: $outputSign * $years,
                    months: $outputSign * $months,
                    days: $outputSign * $roundedDays,
                );
            }

            // smallestUnit is a time unit but largestUnit is a calendar unit.
            $nsPerSmallest = match ($normSmallest) {
                'hour' => self::NS_PER_HOUR,
                'minute' => self::NS_PER_MINUTE,
                'second' => EpochLimits::NS_PER_SECOND,
                'millisecond' => self::NS_PER_MS,
                'microsecond' => self::NS_PER_US,
                default => 1,
            };
            /** @psalm-var int<1, 1000> $roundingIncrement */
            $nsIncrement = $nsPerSmallest * $roundingIncrement;
            // For negative output diffs, flip floor/ceil.
            $effTimeMode = $roundingMode;
            if ($outputSign < 0) {
                $effTimeMode = match ($roundingMode) {
                    'floor' => 'ceil',
                    'ceil' => 'floor',
                    'halfFloor' => 'halfCeil',
                    'halfCeil' => 'halfFloor',
                    default => $roundingMode,
                };
            }
            $absTimeNs = self::roundPositiveNs($timeDiffNs, $nsIncrement, $effTimeMode);

            // Handle day overflow from rounding time (e.g., 23:59 rounds up to 24:00).
            $overflowDays = intdiv(num1: $absTimeNs, num2: self::NS_PER_DAY);
            $absTimeNs %= self::NS_PER_DAY;

            // When time overflow produces extra days, recompute the calendar diff
            // from the updated position to properly rebalance months/years.
            if ($overflowDays > 0 && $normLargest !== 'day' && $normLargest !== 'week') {
                // Overflow from time rounding: recompute calendar diff.
                if ($calId !== 'iso8601') {
                    // Non-ISO: shift nonIsoAdjJdn by overflow in the diff direction.
                    $tc39Jdn2 = $nonIsoAdjJdn + ($sign >= 0 ? $overflowDays : -$overflowDays);
                    [$adjY3, $adjM3, $adjD3] = CalendarMath::fromJulianDay($tc39Jdn2);
                    [$years, $months, , $days] = CalendarFactory::get($calId)->dateUntil(
                        $temporalDate->isoYear,
                        $temporalDate->isoMonth,
                        $temporalDate->isoDay,
                        $adjY3,
                        $adjM3,
                        $adjD3,
                        $normLargest,
                    );
                    $years = abs($years);
                    $months = abs($months);
                    $days = abs($days);
                } else {
                    // ISO: add overflow to the swap-based adjOtherJdn.
                    $isoAdjJdn2 = $adjOtherJdn + $overflowDays;
                    [$adjY3, $adjM3, $adjD3] = CalendarMath::fromJulianDay($isoAdjJdn2);
                    [$years, $months, $days] = self::calendarDiff(
                        $earlier->isoYear,
                        $earlier->isoMonth,
                        $earlier->isoDay,
                        $adjY3,
                        $adjM3,
                        $adjD3,
                        $sign < 0,
                    );
                    if ($normLargest === 'month') {
                        $months = ($years * 12) + $months;
                        $years = 0;
                    }
                }
            } else {
                $days += $overflowDays;
            }

            $h = intdiv(num1: $absTimeNs, num2: self::NS_PER_HOUR);
            $rem = $absTimeNs % self::NS_PER_HOUR;
            $min = intdiv(num1: $rem, num2: self::NS_PER_MINUTE);
            $rem %= self::NS_PER_MINUTE;
            $sec = intdiv(num1: $rem, num2: EpochLimits::NS_PER_SECOND);
            $rem %= EpochLimits::NS_PER_SECOND;
            $ms = intdiv(num1: $rem, num2: self::NS_PER_MS);
            $rem %= self::NS_PER_MS;
            $us = intdiv(num1: $rem, num2: self::NS_PER_US);
            $ns = $rem % self::NS_PER_US;

            return new Duration(
                years: $outputSign * $years,
                months: $outputSign * $months,
                weeks: $outputSign * $weeks,
                days: $outputSign * $days,
                hours: $outputSign * $h,
                minutes: $outputSign * $min,
                seconds: $outputSign * $sec,
                milliseconds: $outputSign * $ms,
                microseconds: $outputSign * $us,
                nanoseconds: $outputSign * $ns,
            );
        }

        // largestUnit is a time unit (hour or smaller): accumulate all days into ns.
        $totalAbsNs = ($dateDiff * self::NS_PER_DAY) + $timeDiffNs;

        $nsPerSmallest = match ($normSmallest) {
            'hour' => self::NS_PER_HOUR,
            'minute' => self::NS_PER_MINUTE,
            'second' => EpochLimits::NS_PER_SECOND,
            'millisecond' => self::NS_PER_MS,
            'microsecond' => self::NS_PER_US,
            default => 1,
        };
        /** @psalm-var int<1, 1000> $roundingIncrement */
        $nsIncrement = $nsPerSmallest * $roundingIncrement;
        // For negative output diffs, flip floor/ceil so they retain their directional meaning.
        $effectiveRoundMode = $roundingMode;
        if ($outputSign < 0) {
            $effectiveRoundMode = match ($roundingMode) {
                'floor' => 'ceil',
                'ceil' => 'floor',
                'halfFloor' => 'halfCeil',
                'halfCeil' => 'halfFloor',
                default => $roundingMode,
            };
        }
        $roundedAbsNs = self::roundPositiveNs($totalAbsNs, $nsIncrement, $effectiveRoundMode);

        // Decompose based on largest unit (no conversion to higher units).
        /** @var array<string, int> $timeUnitNs */
        static $timeUnitNs = [
            'hour' => 3_600_000_000_000,
            'minute' => 60_000_000_000,
            'second' => 1_000_000_000,
            'millisecond' => 1_000_000,
            'microsecond' => 1_000,
            'nanosecond' => 1,
        ];
        /** @var list<'hour'|'minute'|'second'|'millisecond'|'microsecond'|'nanosecond'> $timeUnitOrder */
        static $timeUnitOrder = ['hour', 'minute', 'second', 'millisecond', 'microsecond', 'nanosecond'];

        $rem = $roundedAbsNs;
        $h = 0;
        $min = 0;
        $sec = 0;
        $ms = 0;
        $us = 0;
        $ns = 0;
        $started = false;
        foreach ($timeUnitOrder as $unit) {
            if ($unit === $normLargest) {
                $started = true;
            }
            if (!$started) {
                continue;
            }
            $perUnit = $timeUnitNs[$unit];
            $val = intdiv(num1: $rem, num2: $perUnit);
            $rem %= $perUnit;
            match ($unit) {
                'hour' => $h = $val,
                'minute' => $min = $val,
                'second' => $sec = $val,
                'millisecond' => $ms = $val,
                'microsecond' => $us = $val,
                'nanosecond' => $ns = $val,
            };
        }

        return new Duration(
            hours: $outputSign * $h,
            minutes: $outputSign * $min,
            seconds: $outputSign * $sec,
            milliseconds: $outputSign * $ms,
            microseconds: $outputSign * $us,
            nanoseconds: $outputSign * $ns,
        );
    }

    /**
     * Shared implementation for add() and subtract().
     *
     * Time units are balanced into nanoseconds, then converted to overflow days.
     * Day + calendar units are applied to the date part using PlainDate-style arithmetic.
     *
     * @param array<array-key, mixed>|object $options
     */
    private function addDuration(int $sign, Duration $dur, array|object $options): self
    {
        // GetOptionsObject + GetTemporalOverflowOption: omitted ([]) and a bag without
        // 'overflow' default to 'constrain'; an explicit null / non-object primitive /
        // Symbol sentinel => TypeError; an 'overflow' value is coerced/validated (an
        // explicit `overflow => null` value => RangeError).
        $overflow = Options::overflowFromValue($options);

        $years = $sign * (int) $dur->years;
        $months = $sign * (int) $dur->months;
        $days = $sign * (((int) $dur->weeks * 7) + (int) $dur->days);

        // Balance time units to nanoseconds, then extract whole days.
        $hours = $sign * (int) $dur->hours;
        $minutes = $sign * (int) $dur->minutes;
        $seconds = $sign * (int) $dur->seconds;
        $ms = $sign * (int) $dur->milliseconds;
        $us = $sign * (int) $dur->microseconds;
        $ns = $sign * (int) $dur->nanoseconds;

        // Balance time units using the same step-by-step carry approach as PlainDate,
        // to avoid int64 overflow with large Duration field values.
        // Each step extracts whole days and passes the remainder to the next smaller unit.

        // hours → full days + remainder hours
        $hDays = intdiv(num1: $hours, num2: 24);
        $hRem = $hours % 24;

        // carry + minutes → full days + remainder minutes
        $totalMin = ($hRem * 60) + $minutes;
        $mDays = intdiv(num1: $totalMin, num2: 1_440);
        $mRem = $totalMin % 1_440;

        // carry + seconds → full days + remainder seconds
        $totalSec = ($mRem * 60) + $seconds;
        $sDays = intdiv(num1: $totalSec, num2: 86_400);
        $sRem = $totalSec % 86_400;

        // carry + milliseconds → full days + remainder ms
        $totalMs = ($sRem * 1_000) + $ms;
        $msDays = intdiv(num1: $totalMs, num2: 86_400_000);
        $msRem = $totalMs % 86_400_000;

        // carry + microseconds → full days + remainder μs
        $totalUs = ($msRem * 1_000) + $us;
        $usDays = intdiv(num1: $totalUs, num2: 86_400_000_000);
        $usRem = $totalUs % 86_400_000_000;

        // carry + nanoseconds → full days + remainder ns
        $totalNs = ($usRem * 1_000) + $ns;
        $nsDays = intdiv(num1: $totalNs, num2: 86_400_000_000_000);
        $nsRem = $totalNs % 86_400_000_000_000;

        $days += $hDays + $mDays + $sDays + $msDays + $usDays + $nsDays;

        // Reconstruct time-of-day from the accumulated remainders.
        // $nsRem is the total sub-day nanoseconds; it may be negative when the
        // duration is negative. Normalise to [0, NS_PER_DAY) using floor-div.
        $currentTimeNs = self::timeToNs(
            $this->hour,
            $this->minute,
            $this->second,
            $this->millisecond,
            $this->microsecond,
            $this->nanosecond,
        );
        $newTimeNs = $currentTimeNs + $nsRem;

        // Carry overflow days from the time component.
        if ($newTimeNs < 0) {
            $overflowDays = (int) floor($newTimeNs / self::NS_PER_DAY);
            $newTimeNs -= $overflowDays * self::NS_PER_DAY;
        } else {
            $overflowDays = intdiv(num1: $newTimeNs, num2: self::NS_PER_DAY);
            $newTimeNs %= self::NS_PER_DAY;
        }

        $days += $overflowDays;

        // Delegate date arithmetic to the calendar protocol.
        $cal = CalendarFactory::get($this->calendarId);
        [$newYear, $newMonth, $newDay] = $cal->dateAdd(
            $this->isoYear,
            $this->isoMonth,
            $this->isoDay,
            $years,
            $months,
            0,
            $days,
            $overflow,
        );

        $minJdn = CalendarMath::toJulianDay(-271_821, 4, 19);
        $maxJdn = CalendarMath::toJulianDay(275_760, 9, 13);
        $jdn = CalendarMath::toJulianDay($newYear, $newMonth, $newDay);
        if ($jdn < $minJdn || $jdn > $maxJdn) {
            throw new RangeError('PlainDateTime arithmetic result is outside the representable range.');
        }

        // Decompose new time.
        $h = intdiv(num1: $newTimeNs, num2: self::NS_PER_HOUR);
        $rem = $newTimeNs % self::NS_PER_HOUR;
        $min = intdiv(num1: $rem, num2: self::NS_PER_MINUTE);
        $rem %= self::NS_PER_MINUTE;
        $sec = intdiv(num1: $rem, num2: EpochLimits::NS_PER_SECOND);
        $rem %= EpochLimits::NS_PER_SECOND;
        $msR = intdiv(num1: $rem, num2: self::NS_PER_MS);
        $rem %= self::NS_PER_MS;
        $usR = intdiv(num1: $rem, num2: self::NS_PER_US);
        $nsR = $rem % self::NS_PER_US;

        return new self($newYear, $newMonth, $newDay, $h, $min, $sec, $msR, $usR, $nsR, $this->calendarId);
    }

    /**
     * Parses fractional-second string (".123456789" or ",123") into nanoseconds.
     * Pads or truncates to exactly 9 digits.
     *
     * @return int<0, 999999999>
     */
    private static function parseFraction(string $fractionRaw): int
    {
        $digits = substr(string: $fractionRaw, offset: 1); // strip leading '.' or ','
        /** @var int<0, 999999999> — 9 decimal digits, range 000000000–999999999 */
        return (int) str_pad(substr(string: $digits, offset: 0, length: 9), length: 9, pad_string: '0');
    }

    /**
     * Converts time fields to total nanoseconds since midnight.
     */
    private static function timeToNs(int $h, int $min, int $sec, int $ms, int $us, int $ns): int
    {
        return (
            ($h * self::NS_PER_HOUR)
            + ($min * self::NS_PER_MINUTE)
            + ($sec * EpochLimits::NS_PER_SECOND)
            + ($ms * self::NS_PER_MS)
            + ($us * self::NS_PER_US)
            + $ns
        );
    }

    /**
     * Calendar-aware year/month/day breakdown between two dates, as used by since()/until().
     *
     * @param int<1, 12> $m1
     * @param int<1, 12> $m2
     * @return array{0: int, 1: int, 2: int}
     */
    private static function calendarDiff(
        int $y1,
        int $m1,
        int $d1,
        int $y2,
        int $m2,
        int $d2,
        bool $receiverIsY2 = true,
    ): array {
        // Both call sites pass (y1,m1,d1) = the earlier endpoint and (y2,m2,d2)
        // derived from earlierJdn + a non-negative day count, so (y2,m2,d2) is always
        // >= (y1,m1,d1) lexicographically. The diff is therefore always non-negative and
        // the swap path a smaller second operand would need is unreachable here.
        $years = $y2 - $y1;
        $months = $m2 - $m1;

        if ($months < 0) {
            $years--;
            $months += 12;
        }

        if ($d2 < $d1) {
            if ($months > 0) {
                $months--;
            } else {
                $years--;
                $months = 11;
            }
        }

        if ($receiverIsY2) {
            $anchorMonth = $m2 - $months;
            $anchorYear = $y2 - $years;
            if ($anchorMonth <= 0) {
                $anchorYear--;
                $anchorMonth += 12;
            }
            $anchorMaxDay = CalendarMath::calcDaysInMonth($anchorYear, $anchorMonth);
            $anchorDay = min($d2, $anchorMaxDay);
            $days =
                CalendarMath::toJulianDay($anchorYear, $anchorMonth, $anchorDay)
                - CalendarMath::toJulianDay($y1, $m1, $d1);
        } else {
            $anchorMonth = $m1 + $months;
            $anchorYear = $y1 + $years;
            if ($anchorMonth > 12) {
                $anchorYear++;
                $anchorMonth -= 12;
            }
            $anchorMaxDay = CalendarMath::calcDaysInMonth($anchorYear, $anchorMonth);
            $anchorDay = min($d1, $anchorMaxDay);
            $days =
                CalendarMath::toJulianDay($y2, $m2, $d2)
                - CalendarMath::toJulianDay($anchorYear, $anchorMonth, $anchorDay);
        }

        return [$years, $months, $days];
    }

    /**
     * Rounds days (non-negative) plus remaining time-of-day nanoseconds using the given
     * rounding mode. The time ns acts as fractional progress toward the next day.
     */
    private static function roundDaysWithTime(int $days, int $timeNs, int $increment, string $mode, int $sign = 1): int
    {
        $progress = $timeNs > 0 ? (float) $timeNs / (float) self::NS_PER_DAY : 0.0;
        $roundUp = CalendarMath::applyCalendarRoundingProgress($days, $progress, $increment, $mode, $sign);
        $q = intdiv(num1: $days, num2: $increment);
        return $roundUp ? ($q + 1) * $increment : $q * $increment;
    }

    /**
     * Calendar-aware rounding for months (NudgeToCalendarUnit, unit=months).
     *
     * Rounds $totalMonths (non-negative) + $remainingDays + $remainingTimeNs to the
     * nearest $increment months, anchored from the later date.
     *
     * @throws RangeError if the rounded date is out of the valid ISO range.
     */
    private static function roundCalendarMonths(
        int $totalMonths,
        int $remainingDays,
        int $remainingTimeNs,
        self $receiver,
        int $increment,
        string $mode,
        bool $receiverIsLater,
        int $sign = 1,
    ): int {
        $dir = $receiverIsLater ? -1 : 1;

        // floor-count (rounded down to nearest multiple of increment).
        $floorCount = intdiv(num1: $totalMonths, num2: $increment) * $increment;

        $anchorJdn = self::addSignedMonths($receiver, $dir * $floorCount);
        $nextJdn = self::addSignedMonths($receiver, $dir * ($floorCount + $increment));

        $intervalDays = abs($nextJdn - $anchorJdn);

        // Total fractional progress: remaining days + remaining time as fraction of a day.
        $totalRemNs = ($remainingDays * self::NS_PER_DAY) + $remainingTimeNs;
        $progress = $intervalDays > 0 ? (float) $totalRemNs / ((float) $intervalDays * (float) self::NS_PER_DAY) : 0.0;

        $roundUp = CalendarMath::applyCalendarRoundingProgress($totalMonths, $progress, $increment, $mode, $sign);

        $roundedAbsMonths = $roundUp ? $floorCount + $increment : $floorCount;

        // Validate: the rounded result must not exceed the valid PlainDate range.
        self::addSignedMonths($receiver, $dir * $roundedAbsMonths);

        return $roundedAbsMonths;
    }

    /**
     * Calendar-aware rounding for years (NudgeToCalendarUnit, unit=years).
     *
     * @throws RangeError if the rounded date is out of the valid ISO range.
     */
    private static function roundCalendarYears(
        int $years,
        int $totalMonths,
        int $remainingDays,
        int $remainingTimeNs,
        self $receiver,
        int $increment,
        string $mode,
        bool $receiverIsLater,
        int $sign = 1,
    ): int {
        $dir = $receiverIsLater ? -1 : 1;

        $floorCount = intdiv(num1: $years, num2: $increment) * $increment;

        // For year rounding, we go by year increments (12 months each).
        $anchorJdn = self::addSignedMonths($receiver, $dir * $floorCount * 12);
        $nextJdn = self::addSignedMonths($receiver, $dir * ($floorCount + $increment) * 12);

        $intervalDays = abs($nextJdn - $anchorJdn);

        // Compute the total distance from anchor (floorCount years) to actual position.
        $remMonths = $totalMonths - ($floorCount * 12);
        $monthsJdn = self::addSignedMonths($receiver, $dir * (($floorCount * 12) + $remMonths));
        $remDaysFromMonths = abs($monthsJdn - $anchorJdn);
        $totalRemNs = (($remDaysFromMonths + $remainingDays) * self::NS_PER_DAY) + $remainingTimeNs;
        $progress = $intervalDays > 0 ? (float) $totalRemNs / ((float) $intervalDays * (float) self::NS_PER_DAY) : 0.0;

        $roundUp = CalendarMath::applyCalendarRoundingProgress($years, $progress, $increment, $mode, $sign);

        $roundedAbsYears = $roundUp ? $floorCount + $increment : $floorCount;

        // Validate range.
        self::addSignedMonths($receiver, $dir * $roundedAbsYears * 12);

        return $roundedAbsYears;
    }

    /**
     * Adds $signedMonths months to $receiver's date and returns the resulting Julian Day Number.
     *
     * @throws RangeError if the resulting date is outside the valid ISO range.
     */
    private static function addSignedMonths(self $receiver, int $signedMonths): int
    {
        $cal = CalendarFactory::get($receiver->calendarId);
        [$y, $m, $d] = $cal->dateAdd(
            $receiver->isoYear,
            $receiver->isoMonth,
            $receiver->isoDay,
            0,
            $signedMonths,
            0,
            0,
            'constrain',
        );

        $jdn = CalendarMath::toJulianDay($y, $m, $d);
        $minJdn = CalendarMath::toJulianDay(-271_821, 4, 19);
        $maxJdn = CalendarMath::toJulianDay(275_760, 9, 13);
        if ($jdn < $minJdn || $jdn > $maxJdn) {
            throw new RangeError('Rounded PlainDateTime is outside the representable range.');
        }

        return $jdn;
    }

    /**
     * Rounds a non-negative nanosecond value to the nearest multiple of $increment.
     *
     * @throws RangeError for unknown rounding modes.
     */
    private static function roundPositiveNs(int $ns, int $increment, string $mode): int
    {
        $q = intdiv(num1: $ns, num2: $increment);
        $rem = $ns - ($q * $increment);
        $r1 = $q * $increment; // floor multiple
        $r2 = $r1 + $increment; // ceil multiple
        if ($mode === 'halfEven') {
            $cmp = $rem * 2;
            if ($cmp < $increment) {
                return $r1;
            }
            if ($cmp > $increment) {
                return $r2;
            }
            return ($q % 2) === 0 ? $r1 : $r2;
        }
        return match ($mode) {
            'trunc', 'floor' => $r1,
            'ceil', 'expand' => $rem === 0 ? $r1 : $r2,
            'halfExpand', 'halfCeil' => ($rem * 2) >= $increment ? $r2 : $r1,
            'halfTrunc', 'halfFloor' => ($rem * 2) > $increment ? $r2 : $r1,
            default => throw new RangeError("Invalid roundingMode \"{$mode}\"."),
        };
    }

    #[\Override]
    protected function localeDefaultComponents(): string
    {
        return 'datetime';
    }

    #[\Override]
    protected function localeIsDateOnly(): bool
    {
        return false;
    }

    #[\Override]
    protected function localeIsTimeOnly(): bool
    {
        return false;
    }

    #[\Override]
    protected function localeDefaultCalendarId(): string
    {
        return $this->calendarId;
    }

    #[\Override]
    protected function toLocaleTimestamp(): int
    {
        $dt = new \DateTime(
            sprintf(
                '%04d-%02d-%02dT%02d:%02d:%02d',
                $this->isoYear,
                $this->isoMonth,
                $this->isoDay,
                $this->hour,
                $this->minute,
                $this->second,
            ),
            new \DateTimeZone('UTC'),
        );
        return $dt->getTimestamp();
    }
}
