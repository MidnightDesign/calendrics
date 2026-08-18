<?php

declare(strict_types=1);

namespace Calendrics;

use Calendrics\Spec\Internal\PhpDateTimeInterop;
use Calendrics\Trait\HasDayOfMonthProperties;
use Calendrics\Trait\HasDayOfMonthSpec;
use Calendrics\Trait\HasEpochProperties;
use Calendrics\Trait\HasEpochSpec;
use Calendrics\Trait\HasLocalizedFormatting;
use Calendrics\Trait\HasTimeOfDayProperties;
use Calendrics\Trait\HasTimeOfDaySpec;
use Calendrics\Trait\HasYearMonthProperties;
use Calendrics\Trait\HasYearMonthSpec;

/**
 * A date-time anchored to a specific time zone and instant.
 *
 * This is the porcelain (user-facing) wrapper around the spec-layer
 * {@see Spec\ZonedDateTime}. It provides typed enums, named parameters, and
 * a simpler API surface for application code while delegating all computation
 * to the spec layer.
 */
final class ZonedDateTime implements
    \Stringable,
    \JsonSerializable,
    HasEpochSpec,
    HasYearMonthSpec,
    HasDayOfMonthSpec,
    HasTimeOfDaySpec
{
    use HasEpochProperties;
    use HasYearMonthProperties;
    use HasDayOfMonthProperties;
    use HasTimeOfDayProperties;
    use HasLocalizedFormatting;

    // -------------------------------------------------------------------------
    // Virtual (get-only) time-zone-specific properties
    // -------------------------------------------------------------------------

    /**
     * The IANA timezone identifier, 'UTC', or a fixed offset string (e.g. '+05:30').
     *
     * @psalm-suppress PropertyNotSetInConstructor — virtual property (get-only hook, no backing store)
     */
    public string $timeZoneId {
        get => $this->spec->timeZoneId;
    }

    /**
     * The UTC offset string for this instant in this time zone (e.g. '+05:30').
     *
     * @psalm-suppress PropertyNotSetInConstructor — virtual property (get-only hook, no backing store)
     */
    public string $offset {
        get => $this->spec->offset;
    }

    /**
     * The UTC offset in nanoseconds for this instant in this time zone.
     *
     * @psalm-suppress PropertyNotSetInConstructor — virtual property (get-only hook, no backing store)
     */
    public int $offsetNanoseconds {
        get => $this->spec->offsetNanoseconds;
    }

    /**
     * Number of hours in the current day (typically 24; may differ during DST transitions).
     *
     * @psalm-suppress PropertyNotSetInConstructor — virtual property (get-only hook, no backing store)
     */
    public int $hoursInDay {
        get => (int) $this->spec->hoursInDay;
    }

    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    /** The underlying spec-layer instance. */
    private readonly Spec\ZonedDateTime $spec;

    /**
     * @param int      $epochNanoseconds Nanoseconds since the Unix epoch.
     * @param string   $timeZoneId       Timezone identifier: 'UTC', '+-HH:MM', or an IANA name.
     * @param Calendar $calendar         Calendar system (default ISO 8601).
     * @throws \Calendrics\Exception\RangeError if the epoch nanoseconds or time zone are invalid.
     */
    public function __construct(int $epochNanoseconds, string $timeZoneId, Calendar $calendar = Calendar::Iso8601)
    {
        $this->spec = new Spec\ZonedDateTime($epochNanoseconds, $timeZoneId, $calendar->value);
    }

    // -------------------------------------------------------------------------
    // Static factory / comparison methods
    // -------------------------------------------------------------------------

    /**
     * Parses a ZonedDateTime ISO 8601 string.
     *
     * The string must include a bracketed time zone annotation, e.g.
     * "2020-01-01T12:00:00+05:30[Asia/Kolkata]".
     *
     * @param string         $text           ISO 8601 ZonedDateTime string.
     * @param Disambiguation $disambiguation How to resolve ambiguous wall-clock times.
     * @param OffsetOption   $offsetOption   How to handle a provided UTC offset.
     * @throws \Calendrics\Exception\RangeError if the string cannot be parsed.
     */
    public static function parse(
        string $text,
        Disambiguation $disambiguation = Disambiguation::Compatible,
        OffsetOption $offsetOption = OffsetOption::Reject,
    ): self {
        return self::fromSpec(Spec\ZonedDateTime::from($text, [
            'disambiguation' => $disambiguation->value,
            'offset' => $offsetOption->value,
        ]));
    }

    /**
     * Creates a ZonedDateTime from calendar fields anchored to a time zone.
     *
     * Supply either `year` or `era` + `eraYear`, and either `month` or `monthCode`.
     * Time fields default to 0. The `offset` parameter is a UTC offset string
     * (e.g. "+05:30") used for disambiguation, not a display preference.
     *
     * @param non-empty-string        $timeZone
     * @param int|null                $year
     * @param int<1, 12>|null         $month
     * @param non-empty-string|null   $monthCode
     * @param int<1, 31>|null         $day
     * @param int<0, 23>|null         $hour
     * @param int<0, 59>|null         $minute
     * @param int<0, 59>|null         $second
     * @param int<0, 999>|null        $millisecond
     * @param int<0, 999>|null        $microsecond
     * @param int<0, 999>|null        $nanosecond
     * @param Calendar                $calendar
     * @param non-empty-string|null   $era
     * @param int|null                $eraYear
     * @param non-empty-string|null   $offset
     * @param Disambiguation          $disambiguation
     * @param OffsetOption            $offsetOption
     * @param Overflow                $overflow
     */
    public static function fromFields(
        string $timeZone,
        ?int $year = null,
        ?int $month = null,
        ?string $monthCode = null,
        ?int $day = null,
        ?int $hour = null,
        ?int $minute = null,
        ?int $second = null,
        ?int $millisecond = null,
        ?int $microsecond = null,
        ?int $nanosecond = null,
        Calendar $calendar = Calendar::Iso8601,
        ?string $era = null,
        ?int $eraYear = null,
        ?string $offset = null,
        Disambiguation $disambiguation = Disambiguation::Compatible,
        OffsetOption $offsetOption = OffsetOption::Reject,
        Overflow $overflow = Overflow::Constrain,
    ): self {
        $fields = ['timeZone' => $timeZone, 'calendar' => $calendar->value];
        if ($year !== null) {
            $fields['year'] = $year;
        }
        if ($month !== null) {
            $fields['month'] = $month;
        }
        if ($monthCode !== null) {
            $fields['monthCode'] = $monthCode;
        }
        if ($day !== null) {
            $fields['day'] = $day;
        }
        if ($hour !== null) {
            $fields['hour'] = $hour;
        }
        if ($minute !== null) {
            $fields['minute'] = $minute;
        }
        if ($second !== null) {
            $fields['second'] = $second;
        }
        if ($millisecond !== null) {
            $fields['millisecond'] = $millisecond;
        }
        if ($microsecond !== null) {
            $fields['microsecond'] = $microsecond;
        }
        if ($nanosecond !== null) {
            $fields['nanosecond'] = $nanosecond;
        }
        if ($era !== null) {
            $fields['era'] = $era;
        }
        if ($eraYear !== null) {
            $fields['eraYear'] = $eraYear;
        }
        if ($offset !== null) {
            $fields['offset'] = $offset;
        }

        return self::fromSpec(Spec\ZonedDateTime::from($fields, [
            'disambiguation' => $disambiguation->value,
            'offset' => $offsetOption->value,
            'overflow' => $overflow->value,
        ]));
    }

    /**
     * Compares two ZonedDateTimes by their epoch nanoseconds.
     *
     * @return int -1, 0, or 1.
     */
    public static function compare(self $one, self $two): int
    {
        return Spec\ZonedDateTime::compare($one->spec, $two->spec);
    }

    /**
     * Creates a ZonedDateTime from a PHP `\DateTimeInterface`.
     *
     * The zone of `$dt` (as returned by `\DateTimeZone::getName()`) is used as
     * the resulting `timeZoneId`; this preserves both IANA names like
     * `Europe/Vienna` and fixed-offset strings like `+05:30`.
     *
     * PHP's `\DateTimeImmutable` carries microsecond precision; sub-microsecond
     * Temporal bits (the lowest three decimal digits of `epochNanoseconds`) are
     * therefore always zero on the resulting ZonedDateTime.
     */
    public static function fromDateTime(\DateTimeInterface $dt): self
    {
        // Mago's stubs type \DateTimeInterface::getTimezone() as \DateTimeZone|false; in practice
        // it never returns false for \DateTimeImmutable. PHPStan and Psalm correctly model the
        // runtime. Suppress Mago here rather than adding a runtime check the other analyzers
        // (correctly) flag as redundant.
        // @mago-ignore analysis:invalid-method-access
        // @mago-ignore analysis:mixed-argument
        $tzId = $dt->getTimezone()->getName();

        return new self(PhpDateTimeInterop::epochNanoseconds($dt), $tzId);
    }

    // -------------------------------------------------------------------------
    // Instance methods
    // -------------------------------------------------------------------------

    /**
     * Returns a new ZonedDateTime with the specified fields overridden.
     *
     * @param int|null         $year           Year override, or null to keep.
     * @param int<1, 12>|null  $month          Month override (1-12), or null to keep.
     * @param int<1, 31>|null  $day            Day override, or null to keep.
     * @param int<0, 23>|null  $hour           Hour override (0-23), or null to keep.
     * @param int<0, 59>|null  $minute         Minute override (0-59), or null to keep.
     * @param int<0, 59>|null  $second         Second override (0-59), or null to keep.
     * @param int<0, 999>|null $millisecond    Millisecond override (0-999), or null to keep.
     * @param int<0, 999>|null $microsecond    Microsecond override (0-999), or null to keep.
     * @param int<0, 999>|null $nanosecond     Nanosecond override (0-999), or null to keep.
     * @param string|null $offset         UTC offset string override (e.g. "+05:30"), or null to keep.
     * @param Overflow       $overflow       How to handle out-of-range values.
     * @param Disambiguation $disambiguation How to resolve ambiguous wall-clock times.
     * @param OffsetOption   $offsetOption   How to use the provided offset.
     * @throws \Calendrics\Exception\RangeError if fields are invalid.
     */
    public function with(
        ?int $year = null,
        ?int $month = null,
        ?int $day = null,
        ?int $hour = null,
        ?int $minute = null,
        ?int $second = null,
        ?int $millisecond = null,
        ?int $microsecond = null,
        ?int $nanosecond = null,
        ?string $offset = null,
        ?string $monthCode = null,
        ?string $era = null,
        ?int $eraYear = null,
        Overflow $overflow = Overflow::Constrain,
        Disambiguation $disambiguation = Disambiguation::Compatible,
        OffsetOption $offsetOption = OffsetOption::Prefer,
    ): self {
        $fields = array_filter(
            [
                'year' => $year,
                'month' => $month,
                'day' => $day,
                'hour' => $hour,
                'minute' => $minute,
                'second' => $second,
                'millisecond' => $millisecond,
                'microsecond' => $microsecond,
                'nanosecond' => $nanosecond,
                'offset' => $offset,
            ],
            static fn($v) => $v !== null,
        );

        if ($monthCode !== null) {
            $fields['monthCode'] = $monthCode;
        }
        if ($era !== null) {
            $fields['era'] = $era;
        }
        if ($eraYear !== null) {
            $fields['eraYear'] = $eraYear;
        }

        $opts = [
            'overflow' => $overflow->value,
            'disambiguation' => $disambiguation->value,
            'offset' => $offsetOption->value,
        ];

        return self::fromSpec($this->spec->with($fields, $opts));
    }

    /**
     * Returns a new ZonedDateTime with the given duration added.
     *
     * @param Duration $duration The duration to add.
     * @param Overflow $overflow How to handle out-of-range values after calendar arithmetic.
     */
    public function add(Duration $duration, Overflow $overflow = Overflow::Constrain): self
    {
        return self::fromSpec($this->spec->add($duration->toSpec(), ['overflow' => $overflow->value]));
    }

    /**
     * Returns a new ZonedDateTime with the given duration subtracted.
     *
     * @param Duration $duration The duration to subtract.
     * @param Overflow $overflow How to handle out-of-range values after calendar arithmetic.
     */
    public function subtract(Duration $duration, Overflow $overflow = Overflow::Constrain): self
    {
        return self::fromSpec($this->spec->subtract($duration->toSpec(), ['overflow' => $overflow->value]));
    }

    /**
     * Returns the duration from $other to this ZonedDateTime (this - other).
     *
     * @param self         $other             The other ZonedDateTime to measure from.
     * @param Unit         $largestUnit       The largest unit in the result (default: Hour).
     * @param Unit         $smallestUnit      The smallest unit in the result (default: Nanosecond).
     * @param RoundingMode $roundingMode      How to round the result (default: Trunc).
     * @param int          $roundingIncrement The rounding increment for the smallest unit.
     */
    public function since(
        self $other,
        Unit $largestUnit = Unit::Hour,
        Unit $smallestUnit = Unit::Nanosecond,
        RoundingMode $roundingMode = RoundingMode::Trunc,
        int $roundingIncrement = 1,
    ): Duration {
        return Duration::fromSpec($this->spec->since($other->spec, [
            'largestUnit' => $largestUnit->value,
            'smallestUnit' => $smallestUnit->value,
            'roundingMode' => $roundingMode->value,
            'roundingIncrement' => $roundingIncrement,
        ]));
    }

    /**
     * Returns the duration from this ZonedDateTime to $other (other - this).
     *
     * @param self         $other             The other ZonedDateTime to measure to.
     * @param Unit         $largestUnit       The largest unit in the result (default: Hour).
     * @param Unit         $smallestUnit      The smallest unit in the result (default: Nanosecond).
     * @param RoundingMode $roundingMode      How to round the result (default: Trunc).
     * @param int          $roundingIncrement The rounding increment for the smallest unit.
     */
    public function until(
        self $other,
        Unit $largestUnit = Unit::Hour,
        Unit $smallestUnit = Unit::Nanosecond,
        RoundingMode $roundingMode = RoundingMode::Trunc,
        int $roundingIncrement = 1,
    ): Duration {
        return Duration::fromSpec($this->spec->until($other->spec, [
            'largestUnit' => $largestUnit->value,
            'smallestUnit' => $smallestUnit->value,
            'roundingMode' => $roundingMode->value,
            'roundingIncrement' => $roundingIncrement,
        ]));
    }

    /**
     * Returns a new ZonedDateTime rounded to the given unit and increment.
     *
     * @param Unit         $smallestUnit       The unit to round to.
     * @param RoundingMode $roundingMode       Rounding mode (default: HalfExpand).
     * @param int          $roundingIncrement  Must evenly divide the next-larger unit.
     * @throws \Calendrics\Exception\RangeError for invalid unit or increment values.
     */
    public function round(
        Unit $smallestUnit,
        RoundingMode $roundingMode = RoundingMode::HalfExpand,
        int $roundingIncrement = 1,
    ): self {
        return self::fromSpec($this->spec->round([
            'smallestUnit' => $smallestUnit->value,
            'roundingMode' => $roundingMode->value,
            'roundingIncrement' => $roundingIncrement,
        ]));
    }

    /**
     * Returns a new ZonedDateTime representing the start of this date's day
     * in the same time zone.
     *
     * For most time zones this is midnight (00:00:00), but DST transitions
     * that skip midnight may produce a different start-of-day time.
     */
    public function startOfDay(): self
    {
        return self::fromSpec($this->spec->startOfDay());
    }

    /**
     * Returns true if this ZonedDateTime represents the same instant, time zone, and calendar.
     */
    public function equals(self $other): bool
    {
        return $this->spec->equals($other->spec);
    }

    // -------------------------------------------------------------------------
    // toString / Stringable / JsonSerializable
    // -------------------------------------------------------------------------

    /**
     * Returns an ISO 8601 string with time zone and optional calendar annotations.
     *
     * @param int|null         $fractionalSecondDigits Number of fractional second digits (0-9), or null for 'auto'.
     * @param Unit|null        $smallestUnit           Smallest unit to display; overrides $fractionalSecondDigits.
     * @param RoundingMode     $roundingMode           Rounding mode for display (default: Trunc).
     * @param OffsetDisplay    $offset                 Whether to include the UTC offset.
     * @param TimeZoneDisplay  $timeZoneName           Whether to include the time zone name.
     * @param CalendarDisplay  $calendarName           Whether to include the calendar annotation.
     */
    public function toString(
        ?int $fractionalSecondDigits = null,
        ?Unit $smallestUnit = null,
        RoundingMode $roundingMode = RoundingMode::Trunc,
        OffsetDisplay $offset = OffsetDisplay::Auto,
        TimeZoneDisplay $timeZoneName = TimeZoneDisplay::Auto,
        CalendarDisplay $calendarName = CalendarDisplay::Auto,
    ): string {
        $opts = [
            'roundingMode' => $roundingMode->value,
            'offset' => $offset->value,
            'timeZoneName' => $timeZoneName->value,
            'calendarName' => $calendarName->value,
        ];
        if ($fractionalSecondDigits !== null) {
            $opts['fractionalSecondDigits'] = $fractionalSecondDigits;
        }
        if ($smallestUnit !== null) {
            $opts['smallestUnit'] = $smallestUnit->value;
        }

        return $this->spec->toString($opts);
    }

    /**
     * Returns a locale-aware string representation of this zoned date-time.
     *
     * The value is always rendered in its own time zone — there is no `timeZone`
     * option; use {@see withTimeZone()} to move it first. When neither a style nor
     * a `timeZoneName` is given, the zone name is included (short form), matching
     * TC39's default for this type.
     *
     * ```php
     * $zdt->toLocaleString('en-US', dateStyle: FormatStyle::Full, timeStyle: FormatStyle::Long);
     * $zdt->toLocaleString('de-AT', timeZoneName: TimeZoneNameStyle::LongGeneric);
     * ```
     *
     * @param string|null            $locale       BCP 47 locale tag; null uses the ICU default locale.
     * @param FormatStyle|null       $dateStyle    Preset date verbosity; excludes the component options below.
     * @param FormatStyle|null       $timeStyle    Preset time verbosity; excludes the component options below.
     * @param TextWidth|null         $weekday
     * @param TextWidth|null         $era
     * @param NumberWidth|null       $year
     * @param MonthWidth|null        $month
     * @param NumberWidth|null       $day
     * @param TextWidth|null         $dayPeriod
     * @param NumberWidth|null       $hour
     * @param NumberWidth|null       $minute
     * @param NumberWidth|null       $second
     * @param int|null               $fractionalSecondDigits Number of sub-second digits to render (1–3).
     * @param TimeZoneNameStyle|null $timeZoneName How to name the time zone; null uses the type default.
     * @param HourCycle|null         $hourCycle    Hour numbering; null lets the locale decide.
     * @param Calendar|null          $calendar     Calendar to render in; null keeps the locale's own calendar.
     * @return string
     * @throws \Calendrics\Exception\TypeError if a style option is combined with a component option.
     * @throws \Calendrics\Exception\RangeError if this value's calendar cannot be rendered by the
     *                                        resolved formatter — see {@see withCalendar()}.
     */
    public function toLocaleString(
        ?string $locale = null,
        ?FormatStyle $dateStyle = null,
        ?FormatStyle $timeStyle = null,
        ?TextWidth $weekday = null,
        ?TextWidth $era = null,
        ?NumberWidth $year = null,
        ?MonthWidth $month = null,
        ?NumberWidth $day = null,
        ?TextWidth $dayPeriod = null,
        ?NumberWidth $hour = null,
        ?NumberWidth $minute = null,
        ?NumberWidth $second = null,
        ?int $fractionalSecondDigits = null,
        ?TimeZoneNameStyle $timeZoneName = null,
        ?HourCycle $hourCycle = null,
        ?Calendar $calendar = null,
    ): string {
        return $this->spec->toLocaleString($locale, self::localeOptions([
            'dateStyle' => $dateStyle,
            'timeStyle' => $timeStyle,
            'weekday' => $weekday,
            'era' => $era,
            'year' => $year,
            'month' => $month,
            'day' => $day,
            'dayPeriod' => $dayPeriod,
            'hour' => $hour,
            'minute' => $minute,
            'second' => $second,
            'fractionalSecondDigits' => $fractionalSecondDigits,
            'timeZoneName' => $timeZoneName,
            'hourCycle' => $hourCycle,
            'calendar' => $calendar,
        ]));
    }

    /**
     * Returns the ISO 8601 string representation (default formatting).
     */
    #[\Override]
    public function __toString(): string
    {
        return $this->spec->toString();
    }

    /**
     * Returns the ISO 8601 string for JSON serialization.
     */
    #[\Override]
    public function jsonSerialize(): string
    {
        return $this->spec->toString();
    }

    // -------------------------------------------------------------------------
    // Conversion methods
    // -------------------------------------------------------------------------

    /**
     * Returns an Instant representing the same point in time.
     */
    public function toInstant(): Instant
    {
        return Instant::fromSpec($this->spec->toInstant());
    }

    /**
     * Returns a `\DateTimeImmutable` for the same instant in this ZonedDateTime's
     * time zone.
     *
     * The result has microsecond precision; sub-microsecond bits of the Temporal
     * `epochNanoseconds` are dropped (they cannot be represented by PHP's
     * native date-time types). Fixed-offset zones like `+05:30` are passed
     * through to `\DateTimeZone` as-is.
     */
    public function toDateTime(): \DateTimeImmutable
    {
        $tzId = $this->spec->timeZoneId;
        \assert($tzId !== '', description: 'spec layer guarantees a non-empty time zone id');

        return PhpDateTimeInterop::toDateTime($this->spec->epochNanoseconds, new \DateTimeZone($tzId));
    }

    /**
     * Returns a PlainDate containing the local date in this time zone.
     */
    public function toPlainDate(): PlainDate
    {
        return PlainDate::fromSpec($this->spec->toPlainDate());
    }

    /**
     * Returns a PlainTime containing the local time in this time zone.
     */
    public function toPlainTime(): PlainTime
    {
        return PlainTime::fromSpec($this->spec->toPlainTime());
    }

    /**
     * Returns a PlainDateTime containing the local date and time in this time zone.
     */
    public function toPlainDateTime(): PlainDateTime
    {
        return PlainDateTime::fromSpec($this->spec->toPlainDateTime());
    }

    /**
     * Returns a new ZonedDateTime with a different time zone.
     *
     * The epoch nanoseconds remain the same; only the local time display changes.
     *
     * @param string $timeZone IANA timezone identifier, UTC offset string, or 'UTC'.
     * @throws \Calendrics\Exception\RangeError if the time zone is invalid.
     */
    public function withTimeZone(string $timeZone): self
    {
        return self::fromSpec($this->spec->withTimeZone($timeZone));
    }

    /**
     * Returns a new ZonedDateTime with a different calendar system.
     *
     * The epoch nanoseconds and time zone remain the same; only the calendar projection changes.
     */
    public function withCalendar(Calendar $calendar): self
    {
        return self::fromSpec($this->spec->withCalendar($calendar->value));
    }

    /**
     * Returns a new ZonedDateTime with the time portion replaced.
     *
     * If $time is null (or omitted), the time is set to midnight (00:00:00).
     *
     * @param PlainTime|null $time The time to set, or null for midnight.
     */
    public function withPlainTime(?PlainTime $time = null): self
    {
        if ($time === null) {
            return self::fromSpec($this->spec->withPlainTime());
        }

        return self::fromSpec($this->spec->withPlainTime($time->toSpec()));
    }

    /**
     * Finds the next or previous time zone transition (e.g. DST change).
     *
     * Returns null for fixed-offset time zones (UTC, +-HH:MM).
     *
     * @param TransitionDirection $direction Whether to search forward ('next') or backward ('previous').
     */
    public function getTimeZoneTransition(TransitionDirection $direction): ?self
    {
        $result = $this->spec->getTimeZoneTransition($direction->value);

        if ($result === null) {
            return null;
        }

        return self::fromSpec($result);
    }

    // -------------------------------------------------------------------------
    // Spec-layer interop
    // -------------------------------------------------------------------------

    /**
     * Returns the underlying spec-layer ZonedDateTime.
     */
    public function toSpec(): Spec\ZonedDateTime
    {
        return $this->spec;
    }

    /**
     * Creates a porcelain ZonedDateTime from a spec-layer ZonedDateTime.
     */
    public static function fromSpec(Spec\ZonedDateTime $spec): self
    {
        return new self($spec->epochNanoseconds, $spec->timeZoneId, Calendar::from($spec->calendarId));
    }

    // -------------------------------------------------------------------------
    // Debug
    // -------------------------------------------------------------------------

    /**
     * Returns debug information for var_dump().
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'epochNanoseconds' => $this->epochNanoseconds,
            'timeZoneId' => $this->timeZoneId,
            'calendar' => $this->calendar->value,
            'string' => $this->spec->toString(),
            'year' => $this->year,
            'month' => $this->month,
            'day' => $this->day,
            'hour' => $this->hour,
            'minute' => $this->minute,
            'second' => $this->second,
            'millisecond' => $this->millisecond,
            'microsecond' => $this->microsecond,
            'nanosecond' => $this->nanosecond,
            'offset' => $this->offset,
        ];
    }
}
