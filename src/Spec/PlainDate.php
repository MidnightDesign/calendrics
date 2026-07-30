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
 * A calendar date without a time or time zone.
 *
 * Only the ISO 8601 calendar is supported. Years must fit in the range
 * representable by PHP integers.
 *
 * @see https://tc39.es/proposal-temporal/#sec-temporal-plaindate-objects
 */
final class PlainDate implements Stringable
{
    use TemporalSerde;

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
            $result = self::fromString($item);
            Options::overflowFromValue($options);
            return $result;
        }

        // Object / instance / property-bag branch: GetOptionsObject and
        // GetTemporalOverflowOption are read before the algorithmic field validation
        // (CalendarDateFromFields, steps 17-20).
        $overflow = Options::overflowFromValue($options);

        if ($item instanceof self) {
            return new self($item->isoYear, $item->isoMonth, $item->isoDay, $item->calendarId);
        }
        if (is_object($item)) {
            $item = get_object_vars($item);
        }
        return self::fromPropertyBag($item, $overflow);
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

        $fields = is_object($fields) ? get_object_vars($fields) : $fields;

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

        // --- ISO calendar path --- TC39 PrepareCalendarFields reads and coerces the
        // partial fields BEFORE GetOptionsObject validates the options argument's type,
        // so a bad field value's RangeError precedes a primitive options TypeError. The
        // overflow keyword (which only drives regulation) is resolved afterward.
        $year = $this->isoYear;
        if (array_key_exists('year', $fields)) {
            $year = CalendarMath::toFiniteInt($fields['year'], 'PlainDate::with() year');
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
            $newMonth = CalendarMath::toFiniteInt($fields['month'], 'PlainDate::with() month');
            if ($hasMonthCode && $newMonth !== $month) {
                throw new RangeError('Conflicting month and monthCode fields.');
            }
            $month = $newMonth;
        }

        $day = $this->isoDay;
        if (array_key_exists('day', $fields)) {
            $day = CalendarMath::toFiniteInt($fields['day'], 'PlainDate::with() day');
        }

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
     * Years and months are added calendrically (month overflow is carried into
     * year; day is clamped or rejected per `overflow` option). Weeks and days
     * are added using Julian Day Numbers so they work correctly across month and
     * year boundaries. Time units are balanced into days before applying.
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
     * Returns a new PlainDate with the given duration subtracted.
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
     * Returns the Duration from $other to this date (this − other).
     *
     * Supports largestUnit, smallestUnit, roundingMode, and roundingIncrement options.
     *
     * @param self|string|array<array-key, mixed>|object $other   PlainDate or ISO 8601 date string.
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
        return self::diffDate($this, $o, 'since', $options);
    }

    /**
     * Returns the Duration from this date to $other (other − this).
     *
     * @param self|string|array<array-key, mixed>|object $other   PlainDate or ISO 8601 date string.
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
        return self::diffDate($this, $o, 'until', $options);
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
    public function toString(array|object|null $options = []): string
    {
        // GetOptionsObject: an omitted options argument arrives as the empty-array
        // default; PHP null (the spec layer's representation of JS undefined, since the
        // transpiler maps `undefined` → null in argument position) is the same omitted
        // case. A Symbol sentinel is rejected; a bag is normalized to an array.
        $opts = Options::requireObject($options ?? []);

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
            $zdt = ZonedDateTime::createFromEpochParts($epochSec, 0, $tzId, $this->calendarId);
            return $zdt->startOfDay();
        }

        $epochSec = TimeZoneHelper::wallSecToEpochSec($wallSec, $tzId);

        $subNs = ($ms * EpochLimits::NS_PER_MILLISECOND) + ($us * EpochLimits::NS_PER_MICROSECOND) + $ns;

        return ZonedDateTime::createFromEpochParts($epochSec, $subNs, $tzId, $this->calendarId);
    }

    /**
     * Parses an ISO 8601 date string into a PlainDate.
     *
     * Accepted formats:
     *   YYYY-MM-DD, ±YYYYYY-MM-DD, YYYYMMDD, ±YYYYYYMMDD
     * Optional trailing time, offset, and bracket annotations are accepted;
     * only the date portion is used. Z (UTC designator) is not valid for PlainDate.
     *
     * @throws RangeError for invalid or out-of-range dates.
     */
    private static function fromString(string $s): self
    {
        if ($s === '') {
            throw new RangeError('PlainDate::from() received an empty string.');
        }
        // Reject non-ASCII minus sign (U+2212 = \xe2\x88\x92).
        if (str_contains($s, "\u{2212}")) {
            throw new RangeError("PlainDate::from() cannot parse \"{$s}\": non-ASCII minus sign is not allowed.");
        }
        // Reject more than 9 fractional-second digits anywhere (time part or offset fraction).
        if (preg_match('/[.,]\d{10,}/', $s) === 1) {
            throw new RangeError(
                "PlainDate::from() cannot parse \"{$s}\": fractional seconds may have at most 9 digits.",
            );
        }

        // Full anchored regex for a PlainDate string.
        // Date part: YYYY-MM-DD | ±YYYYYY-MM-DD | YYYYMMDD | ±YYYYYYMMDD
        // Optional time: T + HH[:MM[:SS[frac]]]  (fraction only after SS)
        // Optional non-Z offset (only when time is present): ±HH[:MM[:SS[frac]]]
        // Optional bracket annotations
        // Z (UTC designator) is NEVER valid for PlainDate.
        // date: year + rest, optional T+HH:MM:SS.frac, optional offset, bracket annotations
        $pattern = '/^([+-]\d{6}|\d{4})(-\d{2}-\d{2}|\d{4})(?:[Tt ](\d{2})(?::?(\d{2})(?::?(\d{2})([.,]\d+)?)?)?(?:[+-]\d{2}(?::\d{2}(?::\d{2}(?:[.,]\d+)?)?|\d{2}(?:\d{2}(?:[.,]\d+)?)?)?)?)?((?:\[[^\]]*\])*)$/';

        /** @var list<string> $m */
        $m = [];
        if (preg_match($pattern, $s, $m) !== 1) {
            throw new RangeError("PlainDate::from() cannot parse \"{$s}\": invalid ISO 8601 date string.");
        }

        [, $yearRaw, $dateRest] = $m;

        // Reject minus-zero extended year (-000000).
        if (preg_match('/^-0{6}$/', $yearRaw) === 1) {
            throw new RangeError('Cannot use negative zero as extended year.');
        }

        // Compact date rest (MMDD) → extract components.
        if (!str_starts_with($dateRest, '-')) {
            $month = (int) substr(string: $dateRest, offset: 0, length: 2);
            $day = (int) substr(string: $dateRest, offset: 2, length: 2);
        } else {
            $month = (int) substr(string: $dateRest, offset: 1, length: 2);
            $day = (int) substr(string: $dateRest, offset: 4, length: 2);
        }
        $year = (int) $yearRaw;

        // Validate the time portion if present (groups 3-6 from the regex).
        // Hour must be 0-23, minute 0-59, second 0-60 (60 = leap second → mapped).
        // Groups are always present in the match array (as empty strings when not matched).
        if ($m[3] !== '') {
            $hour = (int) $m[3];
            if ($hour > 23) {
                throw new RangeError("PlainDate::from() cannot parse \"{$s}\": hour {$hour} out of range.");
            }
            if ($m[4] !== '') {
                $minute = (int) $m[4];
                if ($minute > 59) {
                    throw new RangeError("PlainDate::from() cannot parse \"{$s}\": minute {$minute} out of range.");
                }
                if ($m[5] !== '') {
                    $second = (int) $m[5];
                    if ($second > 60) {
                        throw new RangeError("PlainDate::from() cannot parse \"{$s}\": second {$second} out of range.");
                    }
                }
            }
        }

        // Validate bracket annotations and extract calendar ID.
        $annotationSection = $m[7];
        $calendarId = CalendarMath::validateAnnotations($annotationSection, $s);

        return new self($year, $month, $day, $calendarId ?? 'iso8601');
    }

    /**
     * Creates a PlainDate from a property-bag array.
     *
     * @param array<array-key,mixed> $bag
     * @param string $overflow 'constrain' (clamp) or 'reject' (throw on out-of-range).
     * @throws TypeError if required fields are missing or have wrong type.
     * @throws RangeError if the date is invalid.
     */
    private static function fromPropertyBag(array $bag, string $overflow = 'constrain'): self
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

        $calendar = $calendarId !== null && $calendarId !== 'iso8601' ? CalendarFactory::get($calendarId) : null;

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
        if ($calendar !== null && array_key_exists('era', $bag) && array_key_exists('eraYear', $bag)) {
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
            $month = $calendar !== null
                ? $calendar->monthCodeToMonth($monthCode, $year)
                : CalendarMath::monthCodeToMonth($monthCode);
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

        // Non-ISO calendar: resolve calendar fields to ISO via the calendar protocol.
        if ($calendar !== null) {
            if ($monthCode !== null) {
                [$isoY, $isoM, $isoD] = $calendar->calendarToIsoFromMonthCode($year, $monthCode, $day, $overflow);
            } else {
                [$isoY, $isoM, $isoD] = $calendar->calendarToIso($year, $month, $day, $overflow);
            }
            return new self($isoY, $isoM, $isoD, $calendarId);
        }

        if ($overflow === 'constrain') {
            /**
             * @psalm-suppress UnnecessaryVarAnnotation — Mago can't narrow min()
             */
            $month = min(12, $month);
            $maxDay = CalendarMath::calcDaysInMonth($year, $month);
            $day = min($maxDay, $day);
        }

        return new self($year, $month, $day, $calendarId ?? 'iso8601');
    }

    /**
     * Core implementation for since() and until().
     *
     * $later and $earlier define the raw difference; $receiver is $this (used as
     * the anchor for calendar-aware rounding, per the TC39 NudgeToCalendarUnit spec).
     *
     * @param array<array-key, mixed>|object|null $options ['largestUnit' => ..., 'smallestUnit' => ..., 'roundingMode' => ..., 'roundingIncrement' => ...]
     */
    private static function diffDate(
        self $temporalDate,
        self $other,
        string $operation,
        array|object|null $options,
    ): Duration {
        /** @var list<string> $validUnits */
        static $validUnits = ['auto', 'day', 'days', 'week', 'weeks', 'month', 'months', 'year', 'years'];
        /** @var array<string, int> $unitRank */
        static $unitRank = [
            'year' => 4,
            'years' => 4,
            'month' => 3,
            'months' => 3,
            'week' => 2,
            'weeks' => 2,
            'day' => 1,
            'days' => 1,
            'auto' => 1,
        ];

        $largestUnit = 'day';
        $largestUnitExplicit = false; // whether largestUnit was explicitly specified
        $smallestUnit = null; // null = not specified
        $roundingMode = 'trunc';
        $roundingIncrement = 1;

        if ($options !== null) {
            $opts = Options::normalizeOptions($options);

            // largestUnit
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

            // roundingIncrement (parsed early so validation order matches spec)
            if (array_key_exists('roundingIncrement', $opts)) {
                /** @var mixed $ri */
                $ri = $opts['roundingIncrement'];
                if ($ri !== null) {
                    $roundingIncrement = CalendarMath::validateRoundingIncrement($ri);
                }
            }

            // roundingMode
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

            // smallestUnit
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

        // Default smallestUnit is 'day' (per TC39 spec for PlainDate).
        if ($smallestUnit === null) {
            $smallestUnit = 'day';
        }

        $suRank = $unitRank[$smallestUnit];
        $luRank = $unitRank[$largestUnit];

        if ($suRank > $luRank) {
            if ($largestUnitExplicit) {
                // Both explicitly set and smallestUnit > largestUnit: throw per spec.
                throw new RangeError(
                    "smallestUnit \"{$smallestUnit}\" cannot be larger than largestUnit \"{$largestUnit}\".",
                );
            }
            // Only smallestUnit was explicitly set; bump largestUnit up to match.
            $largestUnit = $smallestUnit;
        }

        // Normalize to canonical singular forms.
        $normLargest = match ($largestUnit) {
            'days', 'auto' => 'day',
            'weeks' => 'week',
            'months' => 'month',
            'years' => 'year',
            default => $largestUnit,
        };
        $normSmallest = match ($smallestUnit) {
            'days', 'auto' => 'day',
            'weeks' => 'week',
            'months' => 'month',
            'years' => 'year',
            default => $smallestUnit,
        };

        // TC39 step 6: CalendarDateUntil(temporalDate, other) — always in
        // (this, other) order, NOT (smaller, larger). The sign and leap-month
        // asymmetry are handled inside dateUntil.
        //
        // For day/week units the calendar doesn't matter, so we just use JDN.
        $tdJdn = CalendarMath::toJulianDay($temporalDate->isoYear, $temporalDate->isoMonth, $temporalDate->isoDay);
        $otherJdn = CalendarMath::toJulianDay($other->isoYear, $other->isoMonth, $other->isoDay);
        $totalDays = $otherJdn - $tdJdn; // positive when other > temporalDate

        // TC39 spec: GetDifferenceSettings negates the rounding mode for "since",
        // because the spec internally computes "until" and negates the resulting
        // duration. Negating the mode here makes ceil/floor behave correctly in
        // the user-facing direction. (Symmetric modes like halfExpand are unaffected.)
        if ($operation === 'since') {
            $roundingMode = self::negateRoundingMode($roundingMode);
        }

        // Weeks and days: purely mathematical (no calendar-awareness for months/years).
        if ($normLargest === 'day') {
            $d = self::roundDays($totalDays, $roundingIncrement, $roundingMode);
            return $operation === 'since' ? new Duration(days: -$d) : new Duration(days: $d);
        }

        if ($normLargest === 'week') {
            if ($normSmallest === 'week') {
                $weekIncrement = $roundingIncrement * 7;
                $roundedDays = self::roundDays($totalDays, $weekIncrement, $roundingMode);
                $w = intdiv(num1: $roundedDays, num2: 7);
                return $operation === 'since' ? new Duration(weeks: -$w) : new Duration(weeks: $w);
            }
            $rawWeeks = intdiv(num1: $totalDays, num2: 7);
            $rawDays = $totalDays - ($rawWeeks * 7);
            $roundedDays = self::roundDays($rawDays, $roundingIncrement, $roundingMode);
            return $operation === 'since'
                ? new Duration(weeks: -$rawWeeks, days: -$roundedDays)
                : new Duration(weeks: $rawWeeks, days: $roundedDays);
        }

        // Calendar units (months/years): compute via calendar protocol.
        // TC39 calls CalendarDateUntil(temporalDate, other) — preserving order.
        $calendarId = $temporalDate->calendarId;
        if ($calendarId !== 'iso8601') {
            $cal = CalendarFactory::get($calendarId);
            [$years, $months, , $days] = $cal->dateUntil(
                $temporalDate->isoYear,
                $temporalDate->isoMonth,
                $temporalDate->isoDay,
                $other->isoYear,
                $other->isoMonth,
                $other->isoDay,
                $normLargest,
            );
        } else {
            // ISO calendar: symmetric, so order doesn't matter for magnitudes.
            // Keep the (earlier, later) convention for the existing calendarDiff.
            $jdn1 = $tdJdn;
            $jdn2 = $otherJdn;
            $iY1 = $temporalDate->isoYear;
            $iM1 = $temporalDate->isoMonth;
            $iD1 = $temporalDate->isoDay;
            $iY2 = $other->isoYear;
            $iM2 = $other->isoMonth;
            $iD2 = $other->isoDay;
            $receiverIsLater = $jdn1 > $jdn2;
            if ($receiverIsLater) {
                [$iY1, $iM1, $iD1, $iY2, $iM2, $iD2] = [$iY2, $iM2, $iD2, $iY1, $iM1, $iD1];
            }
            [$years, $months, $days] = self::calendarDiff($iY1, $iM1, $iD1, $iY2, $iM2, $iD2, $receiverIsLater);
            if ($jdn1 > $jdn2) {
                // Going backward: negate to match the (temporalDate→other) direction
                $years = -$years;
                $months = -$months;
                $days = -$days;
            }
        }

        // TC39 spec: round BEFORE since negation (steps 8-10). The rounding mode
        // for "since" was already negated above, before the day/week branches.
        $sinceSign = $operation === 'since' ? -1 : 1;

        $sign = $totalDays <=> 0;
        $receiverIsLater = $sign < 0;

        if ($normLargest === 'month') {
            $totalMonths = ($years * 12) + $months;
            if ($normSmallest === 'month') {
                $rounded = self::roundCalendarMonths(
                    $totalMonths,
                    $days,
                    $temporalDate,
                    $roundingIncrement,
                    $roundingMode,
                    $receiverIsLater,
                );
                return new Duration(months: $sinceSign * $rounded);
            }
            $roundedDays = self::roundDays($days, $roundingIncrement, $roundingMode);
            return new Duration(months: $sinceSign * $totalMonths, days: $sinceSign * $roundedDays);
        }

        // normLargest === 'year'
        if ($normSmallest === 'year') {
            $totalMonths = ($years * 12) + $months;
            $rounded = self::roundCalendarYears(
                $years,
                $totalMonths,
                $days,
                $temporalDate,
                $roundingIncrement,
                $roundingMode,
                $receiverIsLater,
            );
            return new Duration(years: $sinceSign * $rounded);
        }
        if ($normSmallest === 'month') {
            $totalMonths = ($years * 12) + $months;
            $roundedMonths = self::roundCalendarMonths(
                $totalMonths,
                $days,
                $temporalDate,
                $roundingIncrement,
                $roundingMode,
                $receiverIsLater,
            );
            $roundedYears = intdiv(num1: $roundedMonths, num2: 12);
            $roundedMonths -= $roundedYears * 12;
            return new Duration(years: $sinceSign * $roundedYears, months: $sinceSign * $roundedMonths);
        }
        // smallestUnit=day: return years + months + rounded days.
        $roundedDays = self::roundDays($days, $roundingIncrement, $roundingMode);
        return new Duration(years: $sinceSign * $years, months: $sinceSign * $months, days: $sinceSign * $roundedDays);
    }

    /**
     * Rounds totalDays (possibly negative) to the nearest multiple of $increment
     * using the given rounding mode.
     *
     * For directed modes (floor/ceil, halfFloor/halfCeil), the mode is negated
     * for negative values to maintain correct directional semantics.
     */
    private static function negateRoundingMode(string $mode): string
    {
        return match ($mode) {
            'floor' => 'ceil',
            'ceil' => 'floor',
            'halfFloor' => 'halfCeil',
            'halfCeil' => 'halfFloor',
            default => $mode,
        };
    }

    private static function roundDays(int $totalDays, int $increment, string $mode): int
    {
        if ($increment === 1 && $mode === 'trunc') {
            return $totalDays;
        }
        $sign = $totalDays >= 0 ? 1 : -1;
        $absDays = abs($totalDays);
        $effectiveMode = $mode;
        if ($sign < 0) {
            $effectiveMode = match ($mode) {
                'floor' => 'ceil',
                'ceil' => 'floor',
                'halfFloor' => 'halfCeil',
                'halfCeil' => 'halfFloor',
                default => $mode,
            };
        }
        return $sign * self::roundPositive($absDays, $increment, $effectiveMode);
    }

    /**
     * Rounds $absValue (non-negative) to nearest multiple of $increment with the given mode.
     *
     * Mode must be 'asIfPositive' (i.e. floor/trunc = round down, ceil/expand = round up).
     *
     * @return int rounded absolute value (multiple of $increment)
     */
    private static function roundPositive(int $absValue, int $increment, string $mode): int
    {
        $q = intdiv(num1: $absValue, num2: $increment);
        $rem = $absValue - ($q * $increment);
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
            default => $r1,
        };
    }

    /**
     * Calendar-aware rounding for months (NudgeToCalendarUnit, unit=months).
     *
     * Rounds $totalMonths (signed) + $remainingDays to the nearest $increment months,
     * anchored from the receiver date (per TC39 spec).
     *
     * $receiverIsLater: true when the receiver is the LATER of the two dates (since()
     * semantics), false when it is the EARLIER (until() semantics).  This controls the
     * direction in which the anchor is computed from the receiver.
     *
     * @throws RangeError if the rounded date is out of the valid ISO range.
     */
    private static function roundCalendarMonths(
        int $totalMonths,
        int $remainingDays,
        self $receiver,
        int $increment,
        string $mode,
        bool $receiverIsLater,
    ): int {
        $sign = $totalMonths >= 0 ? 1 : -1;
        if ($totalMonths === 0 && $remainingDays !== 0) {
            $sign = $remainingDays >= 0 ? 1 : -1;
        }
        $absTotalMonths = abs($totalMonths);
        $absRemDays = abs($remainingDays);

        // floor-count (rounded down to nearest multiple of increment).
        $floorCount = intdiv(num1: $absTotalMonths, num2: $increment) * $increment;

        // Anchor: receiver going toward "other" by floorCount months.
        // When receiver is the later date (since): go backward → receiver − sign*floorCount months.
        // When receiver is the earlier date (until): go forward → receiver + sign*floorCount months.
        // Equivalently, the direction multiplier is -sign for since and +sign for until,
        // which simplifies to: direction = receiverIsLater ? -1 : 1.
        $dir = $receiverIsLater ? -$sign : $sign;
        $anchorJdn = self::addSignedMonths($receiver, $dir * $floorCount);

        // Next boundary: one increment further in the same direction.
        $nextJdn = self::addSignedMonths($receiver, $dir * ($floorCount + $increment));

        // Interval size in days (absolute value of the interval).
        $intervalDays = abs($nextJdn - $anchorJdn);

        // Remaining distance from anchor toward target = |remainingDays| from calendarDiff.
        $progress = $intervalDays > 0 ? $absRemDays / $intervalDays : 0.0;

        // Apply rounding (for negative diffs, flip floor/ceil per spec §11.5.12).
        $roundUp = CalendarMath::applyRoundingProgress($progress, $mode, $sign, intdiv($floorCount, $increment));

        $roundedAbsMonths = $roundUp ? $floorCount + $increment : $floorCount;

        // Validate: the rounded result must not exceed the valid PlainDate range.
        self::addSignedMonths($receiver, $dir * $roundedAbsMonths); // throws if out of range

        return $sign * $roundedAbsMonths;
    }

    /**
     * Calendar-aware rounding for years (NudgeToCalendarUnit, unit=years).
     *
     * $receiverIsLater: true when the receiver is the LATER of the two dates (since()
     * semantics), false when it is the EARLIER (until() semantics).
     *
     * @throws RangeError if the rounded date is out of the valid ISO range.
     */
    private static function roundCalendarYears(
        int $years,
        int $totalMonths,
        int $remainingDays,
        self $receiver,
        int $increment,
        string $mode,
        bool $receiverIsLater,
    ): int {
        if ($years !== 0) {
            $sign = $years >= 0 ? 1 : -1;
        } elseif ($totalMonths !== 0) {
            $sign = $totalMonths >= 0 ? 1 : -1;
        } else {
            $sign = $remainingDays >= 0 ? 1 : -1;
        }
        $absYears = abs($years);

        $floorCount = intdiv(num1: $absYears, num2: $increment) * $increment;

        // Anchor: receiver going toward "other" by floorCount years.
        // When receiver is later (since): go backward → -sign direction.
        // When receiver is earlier (until): go forward → +sign direction.
        $dir = $receiverIsLater ? -$sign : $sign;
        $anchorJdn = self::addSignedYears($receiver, $dir * $floorCount);
        $nextJdn = self::addSignedYears($receiver, $dir * ($floorCount + $increment));

        $intervalDays = abs($nextJdn - $anchorJdn);

        // Compute the target JDN: from anchor, go further in the same direction
        // (toward next boundary) by the remaining months+days.
        $absRemMonths = abs($totalMonths) - ($floorCount * 12);
        $subAnchorJdn = self::addSignedMonths(
            self::fromJulianDayStatic($anchorJdn, $receiver->calendarId),
            $dir * $absRemMonths,
        );
        $targetJdn = $subAnchorJdn + ($dir * abs($remainingDays));
        $absRemDistance = abs($targetJdn - $anchorJdn);

        $progress = $intervalDays > 0 ? $absRemDistance / $intervalDays : 0.0;
        $roundUp = CalendarMath::applyRoundingProgress($progress, $mode, $sign, intdiv($floorCount, $increment));

        $roundedAbsYears = $roundUp ? $floorCount + $increment : $floorCount;

        // Validate: the rounded result must not exceed the valid PlainDate range.
        self::addSignedYears($receiver, $dir * $roundedAbsYears); // throws if out of range

        return $sign * $roundedAbsYears;
    }

    /**
     * Adds $signedMonths months to $date with constrain overflow.
     *
     * Returns the Julian Day Number of the resulting date.
     *
     * @throws RangeError if the resulting date is outside the valid ISO range.
     */
    private static function addSignedMonths(self $date, int $signedMonths): int
    {
        $cal = CalendarFactory::get($date->calendarId);
        [$y, $m, $d] = $cal->dateAdd(
            $date->isoYear,
            $date->isoMonth,
            $date->isoDay,
            0,
            $signedMonths,
            0,
            0,
            'constrain',
        );

        $minJdn = CalendarMath::toJulianDay(-271_821, 4, 19);
        $maxJdn = CalendarMath::toJulianDay(275_760, 9, 13);
        $jdn = CalendarMath::toJulianDay($y, $m, $d);
        if ($jdn < $minJdn || $jdn > $maxJdn) {
            throw new RangeError('PlainDate rounding result is outside the representable range.');
        }
        return $jdn;
    }

    /**
     * Adds $signedYears years to $date with constrain overflow.
     *
     * Returns the Julian Day Number of the resulting date.
     *
     * @throws RangeError if the resulting date is outside the valid ISO range.
     */
    private static function addSignedYears(self $date, int $signedYears): int
    {
        $cal = CalendarFactory::get($date->calendarId);
        [$y, $m, $d] = $cal->dateAdd(
            $date->isoYear,
            $date->isoMonth,
            $date->isoDay,
            $signedYears,
            0,
            0,
            0,
            'constrain',
        );

        $minJdn = CalendarMath::toJulianDay(-271_821, 4, 19);
        $maxJdn = CalendarMath::toJulianDay(275_760, 9, 13);
        $jdn = CalendarMath::toJulianDay($y, $m, $d);
        if ($jdn < $minJdn || $jdn > $maxJdn) {
            throw new RangeError('PlainDate rounding result is outside the representable range.');
        }
        return $jdn;
    }

    /**
     * Constructs a PlainDate from a Julian Day Number (used internally for rounding).
     */
    private static function fromJulianDayStatic(int $jdn, string $calendarId = 'iso8601'): self
    {
        [$y, $m, $d] = CalendarMath::fromJulianDay($jdn);
        return new self($y, $m, $d, $calendarId);
    }

    /**
     * Returns [years, months, remainingDays] between two dates.
     *
     * Caller must pass dates in (earlier, later) order: (y1,m1,d1) ≤ (y2,m2,d2).
     *
     * $receiverIsY2: true when the caller's receiver corresponds to y2 (the later
     * argument), false when it corresponds to y1 (the earlier argument).  The anchor
     * for the day-remainder calculation is always derived from the RECEIVER's date so
     * that since() and until() produce receiver-relative results as required by the spec.
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
        $years = $y2 - $y1;
        $months = $m2 - $m1;

        if ($months < 0) {
            $years--;
            $months += 12;
        }

        // Borrow one month if d2 hasn't reached the start day (d1).
        // Compare d2 against the ORIGINAL d1 (not clamped to maxDay) to correctly
        // handle leap-day cases (e.g. Feb 29 2020 → Feb 28 2021: d2=28 < d1=29, borrow).
        if ($d2 < $d1) {
            if ($months > 0) {
                $months--;
            } else {
                $years--;
                $months = 11;
            }
        }

        if ($receiverIsY2) {
            // Anchor from y2 (receiver) going backward.
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
            // Anchor from y1 (receiver) going forward.
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
     * Shared implementation for add() and subtract().
     *
     * Sub-day time units (hours, minutes, seconds, milliseconds, microseconds,
     * nanoseconds) are balanced into whole days before applying day arithmetic.
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

        // Balance sub-day time units (hours → days, etc.) using cascade arithmetic.
        // Each step: extract full days, carry remainder to the next smaller unit.
        $hours = $sign * (int) $dur->hours;
        $minutes = $sign * (int) $dur->minutes;
        $seconds = $sign * (int) $dur->seconds;
        $ms = $sign * (int) $dur->milliseconds;
        $us = $sign * (int) $dur->microseconds;
        $ns = $sign * (int) $dur->nanoseconds;

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

        // carry + nanoseconds → full days
        $totalNs = ($usRem * 1_000) + $ns;
        $nsDays = intdiv(num1: $totalNs, num2: 86_400_000_000_000);

        $days += $hDays + $mDays + $sDays + $msDays + $usDays + $nsDays;

        // Delegate to the calendar protocol for date arithmetic.
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

        // Arithmetic that crosses the valid PlainDate range always throws, regardless of overflow.
        $minJdn = CalendarMath::toJulianDay(-271_821, 4, 19);
        $maxJdn = CalendarMath::toJulianDay(275_760, 9, 13);
        $jdn = CalendarMath::toJulianDay($newYear, $newMonth, $newDay);
        if ($jdn < $minJdn || $jdn > $maxJdn) {
            throw new RangeError('PlainDate arithmetic result is outside the representable range.');
        }

        return new self($newYear, $newMonth, $newDay, $this->calendarId);
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
    protected function localeCalendarId(): ?string
    {
        return $this->calendarId;
    }

    #[\Override]
    protected function toLocaleTimestamp(): int
    {
        $dt = new \DateTime(
            sprintf('%04d-%02d-%02d 00:00:00', $this->isoYear, $this->isoMonth, $this->isoDay),
            new \DateTimeZone('UTC'),
        );
        return $dt->getTimestamp();
    }
}
