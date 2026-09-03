<?php

declare(strict_types=1);

namespace Calendrics\Spec;

use Calendrics\Exception\RangeError;
use Calendrics\Exception\TypeError;
use Calendrics\Spec\Internal\Calendar\CalendarFactory;
use Calendrics\Spec\Internal\CalendarMath;
use Calendrics\Spec\Internal\DateTimeArithmetic;
use Calendrics\Spec\Internal\DateTimeDifference;
use Calendrics\Spec\Internal\DateTimeFields;
use Calendrics\Spec\Internal\DateTimeParse;
use Calendrics\Spec\Internal\EpochLimits;
use Calendrics\Spec\Internal\EpochRounding;
use Calendrics\Spec\Internal\FieldBag;
use Calendrics\Spec\Internal\HasPlainLocaleString;
use Calendrics\Spec\Internal\HasStringRepresentations;
use Calendrics\Spec\Internal\MonthCode;
use Calendrics\Spec\Internal\Options;
use Calendrics\Spec\Internal\PlainLocaleFormattable;
use Calendrics\Spec\Internal\TimeZoneHelper;
use Stringable;

/**
 * A calendar date combined with a wall-clock time, without a time zone.
 *
 * Only the ISO 8601 calendar is supported. The date range is identical to
 * PlainDate (Apr 19 −271821 … Sep 13 +275760); the time range is
 * 00:00:00.000000000 – 23:59:59.999999999.
 *
 * @see https://tc39.es/proposal-temporal/#sec-temporal-plaindatetime-objects
 */
final class PlainDateTime implements PlainLocaleFormattable, Stringable
{
    use HasPlainLocaleString;
    use HasStringRepresentations;

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
        get => CalendarFactory::get($this->calendarId)->year($this->isoYear, $this->isoMonth, $this->isoDay);
    }

    /**
     * @psalm-suppress PropertyNotSetInConstructor — virtual property
     * @psalm-suppress PossiblyUnusedProperty
     * @psalm-api
     */
    public int $month {
        get => CalendarFactory::get($this->calendarId)->month($this->isoYear, $this->isoMonth, $this->isoDay);
    }

    /**
     * @psalm-suppress PropertyNotSetInConstructor — virtual property
     * @psalm-suppress PossiblyUnusedProperty
     * @psalm-api
     */
    public int $day {
        get => CalendarFactory::get($this->calendarId)->day($this->isoYear, $this->isoMonth, $this->isoDay);
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
        get => CalendarFactory::get($this->calendarId)->dayOfYear($this->isoYear, $this->isoMonth, $this->isoDay);
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
        // @mago-ignore analysis:invalid-property-assignment-value
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
            $result = DateTimeParse::parse($item);
            Options::overflowFromValue($options);
            return $result;
        }
        $item = FieldBag::forCalendarType($item, DateTimeFields::CALENDAR_FIELDS, [], 'PlainDateTime');
        $overflow = Options::overflowFromValue($options);
        return DateTimeFields::fromBag($item, $overflow);
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
        $aNs = CalendarMath::timeToNs(
            $a->hour,
            $a->minute,
            $a->second,
            $a->millisecond,
            $a->microsecond,
            $a->nanosecond,
        );
        $bNs = CalendarMath::timeToNs(
            $b->hour,
            $b->minute,
            $b->second,
            $b->millisecond,
            $b->microsecond,
            $b->nanosecond,
        );
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

        $fields = FieldBag::forPartial($fields, DateTimeFields::CALENDAR_FIELDS, $this->calendarId);

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

        $hasMonth = array_key_exists('month', $fields);
        $hasMonthCode = array_key_exists('monthCode', $fields);
        // MonthCode::validate is field preparation: TYPE (non-stringifiable => TypeError)
        // then SYNTAX (ill-formed => RangeError). Whether the code names a month this
        // calendar has is CalendarDateFromFields, resolved after the options are read.
        $monthCode = $hasMonthCode ? MonthCode::validate($fields['monthCode']) : null;
        $newMonth = $hasMonth ? CalendarMath::toFiniteInt($fields['month'], 'PlainDateTime::with() month') : null;

        $day = $this->isoDay;
        if (array_key_exists('day', $fields)) {
            $day = CalendarMath::toFiniteInt($fields['day'], 'PlainDateTime::with() day');
        }

        // Merge time fields.
        [$h, $min, $sec, $ms, $us, $ns] = $this->mergeTimeFields($fields);

        // `month` and `day` are read with ToPositiveIntegerWithTruncation, so a
        // non-positive value is rejected during field preparation — before the options
        // are read. (Below the minimum there is nothing to constrain to, either.)
        if ($newMonth !== null && $newMonth < 1) {
            throw new RangeError("Invalid month {$newMonth}: must be at least 1.");
        }
        if ($day < 1) {
            throw new RangeError("Invalid day {$day}: must be at least 1.");
        }

        // GetOptionsObject + GetTemporalOverflowOption: explicit null / primitive /
        // Symbol => TypeError; omitted ([]) defaults to 'constrain'.
        $overflow = Options::overflowFromValue($options);

        $month = $this->isoMonth;
        if ($monthCode !== null) {
            $month = CalendarMath::monthCodeToMonth($monthCode);
        }
        if ($newMonth !== null) {
            if ($monthCode !== null && $newMonth !== $month) {
                throw new RangeError('Conflicting month and monthCode fields.');
            }
            $month = $newMonth;
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
    public function add(string|array|object $duration, mixed $options = []): self
    {
        $dur = $duration instanceof Duration ? $duration : Duration::from($duration);
        return DateTimeArithmetic::add($this, 1, $dur, $options);
    }

    /**
     * Returns a new PlainDateTime with the given duration subtracted.
     *
     * @param Duration|string|array<array-key, mixed>|object $duration
     * @param array<array-key, mixed>|object                 $options ['overflow' => 'constrain'|'reject']
     * @psalm-api
     */
    public function subtract(string|array|object $duration, mixed $options = []): self
    {
        $dur = $duration instanceof Duration ? $duration : Duration::from($duration);
        return DateTimeArithmetic::add($this, -1, $dur, $options);
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
    public function since(string|array|object $other, mixed $options = null): Duration
    {
        $o = $other instanceof self ? $other : self::from($other);
        if ($this->calendarId !== $o->calendarId) {
            throw new RangeError(
                "Cannot compute since() between different calendars: \"{$this->calendarId}\" and \"{$o->calendarId}\".",
            );
        }
        return DateTimeDifference::between($this, $o, 'since', $options);
    }

    /**
     * Returns the Duration from this datetime to $other (other − this).
     *
     * @param self|string|array<array-key, mixed>|object $other   PlainDateTime or ISO 8601 datetime string.
     * @param array<array-key, mixed>|object|null $options ['largestUnit' => ..., 'smallestUnit' => ..., 'roundingMode' => ..., 'roundingIncrement' => ...]
     * @psalm-api
     */
    public function until(string|array|object $other, mixed $options = null): Duration
    {
        $o = $other instanceof self ? $other : self::from($other);
        if ($this->calendarId !== $o->calendarId) {
            throw new RangeError(
                "Cannot compute until() between different calendars: \"{$this->calendarId}\" and \"{$o->calendarId}\".",
            );
        }
        return DateTimeDifference::between($this, $o, 'until', $options);
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
            $options = Options::requireObject($options, ['roundingIncrement', 'roundingMode', 'smallestUnit']);
        }

        /** @var mixed $suRaw */
        $suRaw = $options['smallestUnit'] ?? null;
        if ($suRaw === null) {
            throw new RangeError('Calendrics\\PlainDateTime::round() requires smallestUnit.');
        }
        $suRaw = Options::coerceEnumOption($suRaw, 'smallestUnit');

        // ns-per-unit and max increment (exclusive) for each unit.
        // For 'day', max = 1 (only increment 1 is valid).
        $unitMap = [
            'day' => [EpochLimits::NS_PER_DAY, 2], // only increment=1 is valid for day
            'days' => [EpochLimits::NS_PER_DAY, 2],
            'hour' => [EpochLimits::NS_PER_HOUR, 24],
            'hours' => [EpochLimits::NS_PER_HOUR, 24],
            'minute' => [EpochLimits::NS_PER_MINUTE, 60],
            'minutes' => [EpochLimits::NS_PER_MINUTE, 60],
            'second' => [EpochLimits::NS_PER_SECOND, 60],
            'seconds' => [EpochLimits::NS_PER_SECOND, 60],
            'millisecond' => [EpochLimits::NS_PER_MILLISECOND, 1_000],
            'milliseconds' => [EpochLimits::NS_PER_MILLISECOND, 1_000],
            'microsecond' => [EpochLimits::NS_PER_MICROSECOND, 1_000],
            'microseconds' => [EpochLimits::NS_PER_MICROSECOND, 1_000],
            'nanosecond' => [1, 1_000],
            'nanoseconds' => [1, 1_000],
        ];
        if (!array_key_exists($suRaw, $unitMap)) {
            throw new RangeError("Invalid smallestUnit \"{$suRaw}\" for Calendrics\\PlainDateTime::round().");
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
        $timeNs = CalendarMath::timeToNs(
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
        $roundedTimeNs = EpochRounding::roundAsIfPositive($timeNs, $nsIncrement, $roundingMode);

        // Determine how many days of overflow result from rounding (0 or 1).
        $overflowDays = intdiv(num1: $roundedTimeNs, num2: EpochLimits::NS_PER_DAY);
        $newTimeNs = $roundedTimeNs % EpochLimits::NS_PER_DAY;

        $newJdn = $jdn + $overflowDays;

        // Range check.
        $minJdn = CalendarMath::toJulianDay(-271_821, 4, 19);
        $maxJdn = CalendarMath::toJulianDay(275_760, 9, 13);
        if ($newJdn < $minJdn || $newJdn > $maxJdn) {
            throw new RangeError('PlainDateTime rounding result is outside the representable range.');
        }

        [$newYear, $newMonth, $newDay] = CalendarMath::fromJulianDay($newJdn);

        [$h, $min, $sec, $ms, $us, $ns] = CalendarMath::nsToTime($newTimeNs);

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
    public function toString(mixed $options = []): string
    {
        // GetOptionsObject: PHP null (the spec layer's representation of an omitted/
        // `undefined` options argument) resolves to the empty-array default; a Symbol
        // sentinel is rejected; a bag is normalized to an array.
        $options = Options::requireObject($options ?? [], [
            'calendarName',
            'fractionalSecondDigits',
            'roundingMode',
            'smallestUnit',
        ]);

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
        $timeNs = CalendarMath::timeToNs(
            $this->hour,
            $this->minute,
            $this->second,
            $this->millisecond,
            $this->microsecond,
            $this->nanosecond,
        );

        $roundedTimeNs = $increment === 1 ? $timeNs : EpochRounding::roundAsIfPositive($timeNs, $increment, $roundMode);

        // Determine overflow days from rounding (0 or 1).
        $overflowDays = intdiv(num1: $roundedTimeNs, num2: EpochLimits::NS_PER_DAY);
        $newTimeNs = $roundedTimeNs % EpochLimits::NS_PER_DAY;

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

        $hour = intdiv(num1: $newTimeNs, num2: EpochLimits::NS_PER_HOUR);
        $rem = $newTimeNs % EpochLimits::NS_PER_HOUR;
        $min = intdiv(num1: $rem, num2: EpochLimits::NS_PER_MINUTE);
        $rem %= EpochLimits::NS_PER_MINUTE;
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
    public function toZonedDateTime(string $timeZone, mixed $options = []): ZonedDateTime
    {
        // GetOptionsObject: PHP null (the spec layer's representation of an omitted/
        // `undefined` options argument) resolves to the empty-array default; a Symbol
        // sentinel is rejected; a bag is normalized to an array.
        $opts = Options::requireObject($options ?? [], ['disambiguation']);

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

        $subNs =
            ($this->millisecond * EpochLimits::NS_PER_MILLISECOND)
            + ($this->microsecond * EpochLimits::NS_PER_MICROSECOND)
            + $this->nanosecond;

        // Route through fromEpochParts(): it performs the Instant range check
        // (throwing RangeError for |epochNs| > 8.64e21) AND preserves the
        // true over-int64 epoch in trueEpochSec/trueSubNs, so later ops on a max/min-year
        // ZDT decode the real instant and throw correctly when the arithmetic overflows.
        return ZonedDateTime::fromEpochParts($epochSec, $subNs, $normalTzId, $this->calendarId);
    }

    /**
     * Returns a new PlainDateTime with the specified calendar.
     *
     * Per TC39 ToTemporalCalendarIdentifier, $calendar may be a bare calendar ID,
     * an ISO date string, or a Temporal date-bearing object whose `calendarId`
     * slot is read directly.
     *
     * @throws TypeError if $calendar is neither a string nor a calendar-bearing Temporal object.
     * @throws RangeError if the calendar is unsupported.
     * @psalm-api
     */
    public function withCalendar(mixed $calendar): self
    {
        $calId = CalendarFactory::resolveBagCalendar($calendar, 'PlainDateTime');
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
    protected function localeCalendarId(): string
    {
        return $this->calendarId;
    }

    #[\Override]
    protected function toLocaleTimestamp(): int|float
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
        $subNs = ($this->millisecond * 1_000_000) + ($this->microsecond * 1_000) + $this->nanosecond;
        if ($subNs === 0) {
            return $dt->getTimestamp();
        }
        return (float) $dt->getTimestamp() + ((float) $subNs / 1e9);
    }
}
