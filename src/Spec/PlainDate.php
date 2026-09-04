<?php

declare(strict_types=1);

namespace Calendrics\Spec;

use Calendrics\Exception\RangeError;
use Calendrics\Exception\TypeError;
use Calendrics\Spec\Internal\AnchorMath;
use Calendrics\Spec\Internal\Calendar\CalendarFactory;
use Calendrics\Spec\Internal\CalendarMath;
use Calendrics\Spec\Internal\DateArithmetic;
use Calendrics\Spec\Internal\DateDifference;
use Calendrics\Spec\Internal\DateFields;
use Calendrics\Spec\Internal\DateParse;
use Calendrics\Spec\Internal\EpochLimits;
use Calendrics\Spec\Internal\FieldBag;
use Calendrics\Spec\Internal\HasPlainLocaleString;
use Calendrics\Spec\Internal\HasStringRepresentations;
use Calendrics\Spec\Internal\MonthCode;
use Calendrics\Spec\Internal\Options;
use Calendrics\Spec\Internal\PlainLocaleFormattable;
use Calendrics\Spec\Internal\TimeZoneHelper;
use Stringable;

/**
 * A calendar date without a time or time zone.
 *
 * Only the ISO 8601 calendar is supported. Years must fit in the range
 * representable by PHP integers.
 *
 * @see https://tc39.es/proposal-temporal/#sec-temporal-plaindate-objects
 */
final class PlainDate implements PlainLocaleFormattable, Stringable
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
     * Number of days in this date's month. Range depends on the calendar system.
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
     */
    public int $daysInWeek {
        get => 7;
    }

    /**
     * Number of days in this date's year. Range depends on the calendar system.
     *
     * @psalm-suppress PropertyNotSetInConstructor — virtual property (get-only hook, no backing store)
     * @psalm-suppress PossiblyUnusedProperty — accessed externally via test262 scripts
     * @psalm-api
     */
    public int $daysInYear {
        get => CalendarFactory::get($this->calendarId)->daysInYear($this->isoYear, $this->isoMonth, $this->isoDay);
    }

    /**
     * Number of months in this date's year. Range depends on the calendar system.
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
     * @param mixed $year     TC39 ToIntegerWithTruncation: int/float/bool/null/string accepted.
     * @param mixed $month    1–12 after coercion.
     * @param mixed $day      1–daysInMonth after coercion.
     * @param string|int|float|bool|object|null $calendar Calendar id string (defaults to "iso8601").
     * @throws TypeError if calendar is provided but is not a string.
     * @throws RangeError if year/month/day form an invalid ISO date or are infinite.
     * @throws RangeError if calendar is provided and is not "iso8601" (case-insensitive, ASCII-only).
     */
    public function __construct(
        mixed $year,
        mixed $month,
        mixed $day,
        string|int|float|bool|object|null $calendar = 'iso8601',
    ) {
        $this->calendarId = CalendarFactory::resolveConstructorCalendar($calendar, 'PlainDate');
        // TC39 ToIntegerWithTruncation: null → 0, bool → 0/1, string/float → truncated int.
        $this->isoYear = CalendarMath::toConstructorInt($year, 'PlainDate year');
        $monthInt = CalendarMath::toConstructorInt($month, 'PlainDate month');
        if ($monthInt < 1 || $monthInt > 12) {
            throw new RangeError("Invalid PlainDate: month {$monthInt} is out of range 1–12.");
        }
        $this->isoMonth = $monthInt;
        $dayInt = CalendarMath::toConstructorInt($day, 'PlainDate day');
        if ($dayInt < 1) {
            throw new RangeError("Invalid PlainDate: day {$dayInt} must be at least 1.");
        }
        $daysInMonth = CalendarMath::calcDaysInMonth($this->isoYear, $this->isoMonth);
        if ($dayInt > $daysInMonth) {
            throw new RangeError(
                "Invalid PlainDate: day {$dayInt} exceeds {$daysInMonth} for {$this->isoYear}-{$this->isoMonth}.",
            );
        }
        /** @psalm-suppress InvalidPropertyAssignmentValue — $dayInt <= $daysInMonth <= 31 */
        // @mago-ignore analysis:invalid-property-assignment-value
        $this->isoDay = $dayInt;
        // TC39 range: Apr 19 −271821 … Sep 13 +275760.
        $epochDays = CalendarMath::toJulianDay($this->isoYear, $this->isoMonth, $this->isoDay) - 2_440_588;
        if ($epochDays < -100_000_001 || $epochDays > 100_000_000) {
            throw new RangeError(
                "Invalid PlainDate: {$this->isoYear}-{$this->isoMonth}-{$this->isoDay} is outside the representable range.",
            );
        }
    }

    // -------------------------------------------------------------------------
    // Static factory / comparison methods
    // -------------------------------------------------------------------------

    /**
     * Creates a PlainDate from another PlainDate, an ISO 8601 string, or a
     * property-bag array with 'year', 'month'/'monthCode', and 'day' fields.
     *
     * @param self|string|array<array-key, mixed>|object $item     PlainDate, ISO 8601 date string, or property-bag array.
     * @param mixed $options Options bag (['overflow' => 'constrain'|'reject']), null/primitive (TypeError), or omitted.
     * @throws RangeError if the string is invalid or overflow option is invalid.
     * @throws TypeError if the type cannot be interpreted as a PlainDate.
     * @psalm-api
     */
    public static function from(string|array|object $item, mixed $options = []): self
    {
        if (is_string($item)) {
            // ToTemporalDate (string branch): ParseISODateTime (step 14) runs BEFORE
            // GetOptionsObject (step 18) and GetTemporalOverflowOption (step 19), so a
            // malformed string raises RangeError even when the options argument is a bad
            // value. Overflow is irrelevant to a string but is still validated.
            $result = DateParse::parse($item);
            Options::overflowFromValue($options);
            return $result;
        }

        // An existing PlainDate is copied wholesale — no fields are read — but the
        // options argument is still put through GetOptionsObject.
        if ($item instanceof self) {
            Options::overflowFromValue($options);
            return new self($item->isoYear, $item->isoMonth, $item->isoDay, $item->calendarId);
        }

        // Property-bag branch: PrepareCalendarFields (step 16) reads the bag BEFORE
        // GetOptionsObject (step 18), so an accessor on the bag runs — and can throw —
        // ahead of any complaint about the options argument. Both precede the
        // algorithmic field validation in CalendarDateFromFields (steps 19-20).
        $item = FieldBag::forCalendarType($item, DateFields::CALENDAR_FIELDS, [], 'PlainDate');
        $overflow = Options::overflowFromValue($options);
        return DateFields::fromBag($item, $overflow);
    }

    /**
     * Compares two PlainDates chronologically.
     *
     * Returns -1, 0, or +1 (or a value with the same sign).
     *
     * @param self|string|array<array-key, mixed>|object $one
     * @param self|string|array<array-key, mixed>|object $two
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
        return $a->isoDay <=> $b->isoDay;
    }

    // -------------------------------------------------------------------------
    // Instance methods
    // -------------------------------------------------------------------------

    /**
     * Returns a new PlainDate with the specified fields overridden.
     *
     * Only 'year', 'month', 'monthCode', and 'day' fields are recognized.
     * Time fields are silently ignored. The 'calendar' and 'timeZone' keys
     * must not be present.
     *
     * @param array<array-key,mixed>|object $fields   Property bag with fields to override.
     * @param mixed       $options Options bag (['overflow' => ...]), null/primitive (TypeError), or omitted.
     * @throws TypeError             if $fields contains 'calendar' or 'timeZone'.
     * @throws RangeError if the resulting date is invalid (overflow: reject).
     * @psalm-api
     */
    public function with(array|object $fields, mixed $options = []): self
    {
        // Reject Temporal objects (IsPartialTemporalObject step 2).
        if (
            $fields instanceof self
            || $fields instanceof PlainDateTime
            || $fields instanceof PlainTime
            || $fields instanceof PlainYearMonth
            || $fields instanceof PlainMonthDay
            || $fields instanceof ZonedDateTime
            || $fields instanceof Instant
            || $fields instanceof Duration
        ) {
            throw new TypeError('PlainDate::with() argument must not be a Temporal object.');
        }

        $fields = FieldBag::forPartial($fields, DateFields::CALENDAR_FIELDS, $this->calendarId);

        if (array_key_exists('calendar', $fields) || array_key_exists('timeZone', $fields)) {
            throw new TypeError('PlainDate::with() fields must not contain a calendar or timeZone property.');
        }

        // PrepareCalendarFields step 10 (partial): at least one recognized date field must
        // be present. An empty-property object (e.g. JS undefined / sentinel) has no fields.
        // For non-ISO calendars, era and eraYear are also valid date fields.
        $hasAnyField =
            array_key_exists('year', $fields)
            || array_key_exists('month', $fields)
            || array_key_exists('monthCode', $fields)
            || array_key_exists('day', $fields)
            || array_key_exists('era', $fields)
            || array_key_exists('eraYear', $fields);
        if (!$hasAnyField) {
            throw new TypeError(
                'PlainDate::with() requires at least one of: year, month, monthCode, day, era, eraYear.',
            );
        }

        $calendar = $this->calendarId !== 'iso8601' ? CalendarFactory::get($this->calendarId) : null;

        // --- Non-ISO calendar path --- (withNonIso resolves the overflow option after
        // reading its own fields, per TC39 PrepareCalendarFields-before-GetOptionsObject.)
        if ($calendar !== null) {
            return $this->withNonIso($fields, $options, $calendar);
        }

        // --- ISO calendar path --- The three TC39 steps run in this order, and the
        // boundaries between them are observable:
        //   1. PrepareCalendarFields — read and COERCE every partial field. A bad field
        //      value's RangeError therefore precedes a primitive options TypeError.
        //   2. GetOptionsObject + GetTemporalOverflowOption — read the options bag.
        //   3. CalendarDateFromFields — decide whether the coerced fields describe a
        //      real date in this calendar. A month code the calendar does not have is
        //      rejected HERE, after the options have already been read.
        $year = $this->isoYear;
        if (array_key_exists('year', $fields)) {
            $year = CalendarMath::toFiniteInt($fields['year'], 'PlainDate::with() year');
        }

        $hasMonth = array_key_exists('month', $fields);
        $hasMonthCode = array_key_exists('monthCode', $fields);
        // MonthCode::validate is step 1: it checks TYPE (non-stringifiable => TypeError)
        // then SYNTAX (ill-formed => RangeError). Whether the code names a month this
        // calendar actually has is step 3, below.
        $monthCode = $hasMonthCode ? MonthCode::validate($fields['monthCode']) : null;
        $newMonth = $hasMonth ? CalendarMath::toFiniteInt($fields['month'], 'PlainDate::with() month') : null;

        $day = $this->isoDay;
        if (array_key_exists('day', $fields)) {
            $day = CalendarMath::toFiniteInt($fields['day'], 'PlainDate::with() day');
        }

        // `month` and `day` are read with ToPositiveIntegerWithTruncation, so a
        // non-positive value is rejected as part of step 1 — before the options are
        // read, not with the calendar validation below.
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
        }

        return new self($year, $month, $day, $this->calendarId);
    }

    /**
     * Implements with() for non-ISO calendars following TC39 CalendarDateMergeFields.
     *
     * Handles mutually exclusive fields (year vs era+eraYear, month vs monthCode)
     * and preserves monthCode as default when neither month nor monthCode is provided.
     * Resolves the overflow option AFTER reading its own fields, matching TC39's
     * PrepareCalendarFields-before-GetOptionsObject ordering.
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
            $year = CalendarMath::toFiniteInt($fields['year'], 'PlainDate::with() year');
        } elseif ($hasEra) {
            $resolved = CalendarMath::resolveYearFromEra(
                $calendar,
                $fields['era'],
                $fields['eraYear'],
                'PlainDate::with()',
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
            $month = CalendarMath::toFiniteInt($fields['month'], 'PlainDate::with() month');
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
            $day = CalendarMath::toFiniteInt($fields['day'], 'PlainDate::with() day');
        }

        if ($day < 1) {
            throw new RangeError("Invalid day {$day}: must be at least 1.");
        }

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

        return new self($isoY, $isoM, $isoD, $this->calendarId);
    }

    /**
     * Returns a new PlainDate with the given duration added.
     *
     * @param Duration|string|array<array-key, mixed>|object $duration
     * @param array<array-key, mixed>|object                 $options ['overflow' => 'constrain'|'reject']
     * @psalm-api
     */
    public function add(string|array|object $duration, mixed $options = []): self
    {
        $dur = $duration instanceof Duration ? $duration : Duration::from($duration);
        return DateArithmetic::add($this, 1, $dur, $options);
    }

    /**
     * Returns a new PlainDate with the given duration subtracted.
     *
     * @param Duration|string|array<array-key, mixed>|object $duration
     * @param array<array-key, mixed>|object                 $options ['overflow' => 'constrain'|'reject']
     * @psalm-api
     */
    public function subtract(string|array|object $duration, mixed $options = []): self
    {
        $dur = $duration instanceof Duration ? $duration : Duration::from($duration);
        return DateArithmetic::add($this, -1, $dur, $options);
    }

    /**
     * Returns the Duration from $other to this date (this − other).
     *
     * Supports largestUnit, smallestUnit, roundingMode, and roundingIncrement options.
     *
     * @param self|string|array<array-key, mixed>|object $other   PlainDate or ISO 8601 date string.
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
        return DateDifference::between($this, $o, 'since', $options);
    }

    /**
     * Returns the Duration from this date to $other (other − this).
     *
     * @param self|string|array<array-key, mixed>|object $other   PlainDate or ISO 8601 date string.
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
        return DateDifference::between($this, $o, 'until', $options);
    }

    /**
     * Returns true if this PlainDate is the same date as $other.
     *
     * @param self|string|array<array-key, mixed>|object $other A PlainDate or ISO 8601 date string.
     * @psalm-api
     */
    public function equals(string|array|object $other): bool
    {
        $o = $other instanceof self ? $other : self::from($other);
        return (
            $this->isoYear === $o->isoYear
            && $this->isoMonth === $o->isoMonth
            && $this->isoDay === $o->isoDay
            && $this->calendarId === $o->calendarId
        );
    }

    /**
     * @param array<array-key, mixed>|object|null $options Options bag: ['calendarName' => 'auto'|'always'|'never'|'critical']
     * @throws RangeError for invalid calendarName values.
     * @psalm-api
     */
    #[\Override]
    public function toString(mixed $options = []): string
    {
        // GetOptionsObject: an omitted options argument arrives as the empty-array
        // default; PHP null (the spec layer's representation of JS undefined, since the
        // transpiler maps `undefined` → null in argument position) is the same omitted
        // case. A Symbol sentinel is rejected; a bag is normalized to an array.
        $opts = Options::requireObject($options ?? [], ['calendarName']);

        // TC39: years 0–9999 → 4 digits; years outside → ±YYYYYY (6 digits with sign prefix).
        if ($this->isoYear < 0) {
            $yearStr = sprintf('-%06d', abs($this->isoYear));
        } elseif ($this->isoYear > 9999) {
            $yearStr = sprintf('+%06d', $this->isoYear);
        } else {
            $yearStr = sprintf('%04d', $this->isoYear);
        }
        $base = sprintf('%s-%02d-%02d', $yearStr, $this->isoMonth, $this->isoDay);

        $calendarName = 'auto';
        if (array_key_exists('calendarName', $opts)) {
            $cn = Options::coerceEnumOption($opts['calendarName'], 'calendarName');
            $calendarName = $cn;
        }

        return match ($calendarName) {
            'auto' => $this->calendarId !== 'iso8601' ? sprintf('%s[u-ca=%s]', $base, $this->calendarId) : $base,
            'never' => $base,
            'always' => sprintf('%s[u-ca=%s]', $base, $this->calendarId),
            'critical' => sprintf('%s[!u-ca=%s]', $base, $this->calendarId),
            default => throw new RangeError("Invalid calendarName value: \"{$calendarName}\"."),
        };
    }

    /**
     * Combines this date with a time to produce a PlainDateTime.
     *
     * If no argument is given, midnight (00:00:00) is used.
     * Accepts a PlainTime, a time string, or a property-bag array.
     *
     * @param PlainTime|string|array<array-key, mixed>|object|null $time PlainTime, string, array, or null for midnight.
     * @throws TypeError if $time is an invalid type (number, boolean, etc.).
     * @throws RangeError if the string is invalid or the result is out of range.
     * @psalm-api
     */
    public function toPlainDateTime(string|array|object|null $time = null): PlainDateTime
    {
        if (func_num_args() === 0) {
            return new PlainDateTime($this->isoYear, $this->isoMonth, $this->isoDay, calendar: $this->calendarId);
        }
        if ($time === null) {
            throw new TypeError(
                'PlainDate::toPlainDateTime() argument must be a PlainTime, string, or property bag; null given.',
            );
        }
        $t = $time instanceof PlainTime ? $time : PlainTime::from($time);
        return new PlainDateTime(
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
     * Converts this date to a ZonedDateTime in the given timezone.
     *
     * Accepts a timezone string or an array with 'timeZone' and optional 'plainTime' keys.
     *
     * @param string|array<array-key, mixed>|object $item Timezone string or property bag with 'timeZone' (and optional 'plainTime').
     * @throws RangeError if the timezone is invalid or the result is out of range.
     * @psalm-api
     */
    public function toZonedDateTime(string|array|object $item): ZonedDateTime
    {
        if (is_string($item)) {
            // String argument = timezone ID; use startOfDay semantics (TC39 spec).
            $tzId = TimeZoneHelper::normalizeTimezoneId($item);
            return $this->createZdt($tzId, 0, 0, 0, 0, 0, 0, startOfDay: true);
        }
        // Property bag (array or object). Read each property via the faithful
        // TC39 Get(O, P) helper so that an accessor getter — used by test262's
        // positive-probe fixtures, `{ get timeZone(){ throw } }` — fires on read.
        // get_object_vars() would snapshot only declared props and never trigger it.
        // Spec order: Get(item, "timeZone") first, then Get(item, "plainTime").
        /** @var mixed $tzRaw */
        $tzRaw = Options::bagGet($item, 'timeZone');
        if ($tzRaw === Options::ABSENT) {
            throw new TypeError('PlainDate::toZonedDateTime() property bag must have a timeZone property.');
        }
        if (!is_string($tzRaw)) {
            throw new TypeError(sprintf(
                'PlainDate::toZonedDateTime() timeZone must be a string; got %s.',
                get_debug_type($tzRaw),
            ));
        }
        $tzId = TimeZoneHelper::normalizeTimezoneId($tzRaw);

        // Optional plainTime: probed UNCONDITIONALLY (the spec performs Get(item,
        // "plainTime") whenever timeZone is present). ABSENT → startOfDay; a declared
        // null → TypeError (null !== undefined in JS); otherwise pass through
        // PlainTime::from().
        $h = 0;
        $m = 0;
        $s = 0;
        $ms = 0;
        $us = 0;
        $ns = 0;
        /** @var mixed $ptRaw */
        $ptRaw = Options::bagGet($item, 'plainTime');
        $hasPlainTime = $ptRaw !== Options::ABSENT;
        if ($hasPlainTime) {
            if ($ptRaw === null) {
                throw new TypeError('PlainDate::toZonedDateTime() plainTime must not be null.');
            }
            if (!is_string($ptRaw) && !is_array($ptRaw) && !is_object($ptRaw)) {
                throw new TypeError(sprintf(
                    'PlainDate::toZonedDateTime() plainTime must be a PlainTime, string, or property bag; got %s.',
                    get_debug_type($ptRaw),
                ));
            }
            $t = $ptRaw instanceof PlainTime ? $ptRaw : PlainTime::from($ptRaw);
            $h = $t->hour;
            $m = $t->minute;
            $s = $t->second;
            $ms = $t->millisecond;
            $us = $t->microsecond;
            $ns = $t->nanosecond;
        }
        // Use startOfDay when no plainTime is explicitly provided.
        return $this->createZdt($tzId, $h, $m, $s, $ms, $us, $ns, startOfDay: !$hasPlainTime);
    }

    /**
     * Returns a PlainYearMonth from this date's year and month.
     *
     * @psalm-api
     */
    public function toPlainYearMonth(): PlainYearMonth
    {
        return new PlainYearMonth($this->isoYear, $this->isoMonth, $this->calendarId, $this->isoDay);
    }

    /**
     * Returns a PlainMonthDay from this date's month and day.
     *
     * @psalm-api
     */
    public function toPlainMonthDay(): PlainMonthDay
    {
        // ISO fast-path: construct directly without reference-year resolution.
        if ($this->calendarId === 'iso8601') {
            return new PlainMonthDay($this->isoMonth, $this->isoDay, $this->calendarId);
        }
        // Non-ISO calendars: go through CalendarMonthDayFromFields semantics by providing
        // the calendar year and monthCode so that resolveNonIsoReferenceYear can pick the
        // correct representative ISO year (matching TC39 §Temporal.PlainDate.prototype.toPlainMonthDay).
        return PlainMonthDay::from([
            'calendar' => $this->calendarId,
            'year' => $this->year,
            'monthCode' => $this->monthCode,
            'day' => $this->day,
        ]);
    }

    /**
     * Returns a new PlainDate with the specified calendar.
     *
     * Accepts a bare calendar ID or an ISO date string from which the calendar is extracted.
     *
     * @throws RangeError if the calendar is unsupported.
     * @psalm-api
     */
    public function withCalendar(string $calendar): self
    {
        $calId = CalendarFactory::extractCalendarFromString($calendar);
        return new self($this->isoYear, $this->isoMonth, $this->isoDay, $calId);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Creates a ZonedDateTime from this date combined with the given time fields and timezone.
     *
     * Computes epoch seconds from Julian Day Numbers to handle extreme years correctly.
     *
     * @throws RangeError if the resulting epoch nanoseconds are out of range.
     */
    private function createZdt(
        string $tzId,
        int $h,
        int $m,
        int $s,
        int $ms,
        int $us,
        int $ns,
        bool $startOfDay = false,
    ): ZonedDateTime {
        // Compute wall-clock seconds from epoch days + time-of-day (avoids DateTimeImmutable
        // year-formatting issues with extended years > 9999 or negative years).
        $epochDays = CalendarMath::toJulianDay($this->isoYear, $this->isoMonth, $this->isoDay) - 2_440_588;
        $wallSec = ($epochDays * 86_400) + ($h * 3600) + ($m * 60) + $s;

        // For startOfDay semantics (string shorthand), use the transition epoch
        // for cross-midnight DST gaps instead of regular disambiguation.
        if ($startOfDay && $h === 0 && $m === 0 && $s === 0 && $ms === 0 && $us === 0 && $ns === 0) {
            $epochSec = TimeZoneHelper::wallSecToEpochSec($wallSec, $tzId);
            $zdt = ZonedDateTime::fromEpochParts($epochSec, 0, $tzId, $this->calendarId);
            return $zdt->startOfDay();
        }

        $epochSec = TimeZoneHelper::wallSecToEpochSec($wallSec, $tzId);

        $subNs = ($ms * EpochLimits::NS_PER_MILLISECOND) + ($us * EpochLimits::NS_PER_MICROSECOND) + $ns;

        return ZonedDateTime::fromEpochParts($epochSec, $subNs, $tzId, $this->calendarId);
    }

    #[\Override]
    protected function localeDefaultComponents(): string
    {
        return 'date';
    }

    #[\Override]
    protected function localeIsDateOnly(): bool
    {
        return true;
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
    protected function toLocaleEpochParts(): array
    {
        return [AnchorMath::isoDateToEpochDays($this->isoYear, $this->isoMonth, $this->isoDay) * 86_400, 0];
    }
}
