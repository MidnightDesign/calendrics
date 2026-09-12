<?php

declare(strict_types=1);

namespace Calendrics;

use Calendrics\Spec\PlainDateTime as SpecPlainDateTime;
use Calendrics\Trait\HasDayOfMonthProperties;
use Calendrics\Trait\HasDayOfMonthSpec;
use Calendrics\Trait\HasLocalizedFormatting;
use Calendrics\Trait\HasTimeOfDayProperties;
use Calendrics\Trait\HasTimeOfDaySpec;
use Calendrics\Trait\HasYearMonthProperties;
use Calendrics\Trait\HasYearMonthSpec;

/**
 * A calendar date combined with a wall-clock time, without a time zone.
 *
 * This is the porcelain (user-facing) wrapper around the spec-layer
 * {@see SpecPlainDateTime}. It provides typed enums, named parameters, and
 * value-object semantics while delegating all calendar math to the spec layer.
 */
final class PlainDateTime implements
    \Stringable,
    \JsonSerializable,
    HasYearMonthSpec,
    HasDayOfMonthSpec,
    HasTimeOfDaySpec
{
    use HasYearMonthProperties;
    use HasDayOfMonthProperties;
    use HasTimeOfDayProperties;
    use HasLocalizedFormatting;

    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    private readonly SpecPlainDateTime $spec;

    /**
     * Creates a new PlainDateTime from ISO date and time components.
     *
     * @param int          $isoYear     ISO year.
     * @param int<1, 12>   $isoMonth    ISO month of the year (1–12).
     * @param int<1, 31>   $isoDay      ISO day of the month (1–31, depending on month/year).
     * @param int<0, 23>   $hour        Hour of the day (0–23).
     * @param int<0, 59>   $minute      Minute of the hour (0–59).
     * @param int<0, 59>   $second      Second of the minute (0–59).
     * @param int<0, 999>  $millisecond Millisecond (0–999).
     * @param int<0, 999>  $microsecond Microsecond (0–999).
     * @param int<0, 999>  $nanosecond  Nanosecond (0–999).
     * @param Calendar     $calendar    Calendar system to project through.
     * @throws \Calendrics\Exception\RangeError if any value is out of range.
     */
    public function __construct(
        int $isoYear,
        int $isoMonth,
        int $isoDay,
        int $hour = 0,
        int $minute = 0,
        int $second = 0,
        int $millisecond = 0,
        int $microsecond = 0,
        int $nanosecond = 0,
        Calendar $calendar = Calendar::Iso8601,
    ) {
        $this->spec = new SpecPlainDateTime(
            $isoYear,
            $isoMonth,
            $isoDay,
            $hour,
            $minute,
            $second,
            $millisecond,
            $microsecond,
            $nanosecond,
            $calendar->value,
        );
    }

    // -------------------------------------------------------------------------
    // Static factory / comparison methods
    // -------------------------------------------------------------------------

    /**
     * Creates a PlainDateTime from calendar fields.
     *
     * Supply either `year` or `era` + `eraYear`, and either `month` or `monthCode`.
     * Time fields default to 0; unsupplied calendar fields are omitted.
     *
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
     * @param Overflow                $overflow
     */
    public static function fromFields(
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
        Overflow $overflow = Overflow::Constrain,
    ): self {
        $fields = ['calendar' => $calendar->value];
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

        return self::fromSpec(SpecPlainDateTime::from($fields, ['overflow' => $overflow->value]));
    }

    /**
     * Parses an ISO 8601 datetime string into a PlainDateTime.
     *
     * @param string $text ISO 8601 datetime string (e.g. "2020-01-01T12:30:00").
     * @return self
     * @throws \Calendrics\Exception\RangeError if the string cannot be parsed.
     */
    public static function parse(string $text): self
    {
        $spec = SpecPlainDateTime::from($text);

        return self::fromSpec($spec);
    }

    /**
     * Creates a PlainDateTime from a PHP `\DateTimeInterface`.
     *
     * The civil year/month/day/hour/minute/second/microsecond are read from
     * `$dt`'s own time zone (via the `Y`, `n`, `j`, `G`, `i`, `s`, `u` format
     * specifiers); the time-zone identifier itself is dropped because
     * PlainDateTime is zoneless.
     *
     * PHP's `\DateTimeImmutable` carries microsecond precision; the resulting
     * PlainDateTime always has `nanosecond = 0`.
     *
     * @param \DateTimeInterface $dt       Source date-time; its own zone determines the civil fields.
     * @param Calendar           $calendar Calendar projection (default ISO 8601).
     */
    public static function fromDateTime(\DateTimeInterface $dt, Calendar $calendar = Calendar::Iso8601): self
    {
        $u = (int) $dt->format('u');

        return self::fromSpec(
            new SpecPlainDateTime(
                (int) $dt->format('Y'),
                (int) $dt->format('n'),
                (int) $dt->format('j'),
                (int) $dt->format('G'),
                (int) $dt->format('i'),
                (int) $dt->format('s'),
                intdiv(num1: $u, num2: 1000),
                $u % 1000,
                0,
                $calendar->value,
            ),
        );
    }

    /**
     * Compares two PlainDateTimes chronologically.
     *
     * @return int Negative if $one is earlier, positive if later, zero if equal.
     */
    public static function compare(self $one, self $two): int
    {
        return SpecPlainDateTime::compare($one->spec, $two->spec);
    }

    // -------------------------------------------------------------------------
    // Instance methods
    // -------------------------------------------------------------------------

    /**
     * Returns a new PlainDateTime with the specified fields overridden.
     *
     * @param int|null         $year        Year override, or null to keep current.
     * @param int<1, 12>|null  $month       Month override (1–12), or null to keep current.
     * @param int<1, 31>|null  $day         Day override, or null to keep current.
     * @param int<0, 23>|null  $hour        Hour override (0–23), or null to keep current.
     * @param int<0, 59>|null  $minute      Minute override (0–59), or null to keep current.
     * @param int<0, 59>|null  $second      Second override (0–59), or null to keep current.
     * @param int<0, 999>|null $millisecond Millisecond override (0–999), or null to keep current.
     * @param int<0, 999>|null $microsecond Microsecond override (0–999), or null to keep current.
     * @param int<0, 999>|null $nanosecond  Nanosecond override (0–999), or null to keep current.
     * @param string|null      $monthCode   Month code override (e.g. "M01"), or null to keep current.
     * @param string|null      $era         Era override (e.g. "ce"), or null to keep current.
     * @param int|null         $eraYear     Era year override, or null to keep current.
     * @param Overflow         $overflow    How to handle out-of-range values.
     * @return self A new PlainDateTime with the overridden fields.
     * @throws \Calendrics\Exception\RangeError if the resulting datetime is invalid (overflow: reject) or fields conflict.
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
        ?string $monthCode = null,
        ?string $era = null,
        ?int $eraYear = null,
        Overflow $overflow = Overflow::Constrain,
    ): self {
        $fields = [];
        if ($year !== null) {
            $fields['year'] = $year;
        }
        if ($month !== null) {
            $fields['month'] = $month;
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
        if ($monthCode !== null) {
            $fields['monthCode'] = $monthCode;
        }
        if ($era !== null) {
            $fields['era'] = $era;
        }
        if ($eraYear !== null) {
            $fields['eraYear'] = $eraYear;
        }

        $spec = $this->spec->with($fields, ['overflow' => $overflow->value]);

        return self::fromSpec($spec);
    }

    /**
     * Returns a new PlainDateTime with a different calendar system.
     *
     * The underlying ISO datetime remains the same; only the calendar projection changes.
     *
     * Accepts a {@see Calendar} case or any Temporal date-bearing value
     * (PlainDate, PlainDateTime, PlainMonthDay, PlainYearMonth, ZonedDateTime),
     * whose own calendar is used (mirrors TC39 ToTemporalCalendarIdentifier).
     */
    public function withCalendar(Calendar|PlainDate|PlainDateTime|PlainMonthDay|PlainYearMonth|ZonedDateTime $calendar): self
    {
        return self::fromSpec($this->spec->withCalendar(Calendar::resolve($calendar)->value));
    }

    /**
     * Returns a new PlainDateTime with the given duration added.
     *
     * @param Duration $duration The duration to add.
     * @param Overflow $overflow How to handle out-of-range values after calendar arithmetic.
     * @return self
     */
    public function add(Duration $duration, Overflow $overflow = Overflow::Constrain): self
    {
        $spec = $this->spec->add($duration->toSpec(), ['overflow' => $overflow->value]);

        return self::fromSpec($spec);
    }

    /**
     * Returns a new PlainDateTime with the given duration subtracted.
     *
     * @param Duration $duration The duration to subtract.
     * @param Overflow $overflow How to handle out-of-range values after calendar arithmetic.
     * @return self
     */
    public function subtract(Duration $duration, Overflow $overflow = Overflow::Constrain): self
    {
        $spec = $this->spec->subtract($duration->toSpec(), ['overflow' => $overflow->value]);

        return self::fromSpec($spec);
    }

    /**
     * Returns the duration from $other to this datetime (this - other).
     *
     * @param self         $other             The other datetime to measure from.
     * @param Unit         $largestUnit       The largest unit to use in the result.
     * @param Unit         $smallestUnit      The smallest unit to use in the result.
     * @param RoundingMode $roundingMode      How to round the result.
     * @param int          $roundingIncrement The rounding increment for the smallest unit.
     * @return Duration
     */
    public function since(
        self $other,
        Unit $largestUnit = Unit::Day,
        Unit $smallestUnit = Unit::Nanosecond,
        RoundingMode $roundingMode = RoundingMode::Trunc,
        int $roundingIncrement = 1,
    ): Duration {
        $specDuration = $this->spec->since($other->spec, [
            'largestUnit' => $largestUnit->value,
            'smallestUnit' => $smallestUnit->value,
            'roundingMode' => $roundingMode->value,
            'roundingIncrement' => $roundingIncrement,
        ]);

        return Duration::fromSpec($specDuration);
    }

    /**
     * Returns the duration from this datetime to $other (other - this).
     *
     * @param self         $other             The other datetime to measure to.
     * @param Unit         $largestUnit       The largest unit to use in the result.
     * @param Unit         $smallestUnit      The smallest unit to use in the result.
     * @param RoundingMode $roundingMode      How to round the result.
     * @param int          $roundingIncrement The rounding increment for the smallest unit.
     * @return Duration
     */
    public function until(
        self $other,
        Unit $largestUnit = Unit::Day,
        Unit $smallestUnit = Unit::Nanosecond,
        RoundingMode $roundingMode = RoundingMode::Trunc,
        int $roundingIncrement = 1,
    ): Duration {
        $specDuration = $this->spec->until($other->spec, [
            'largestUnit' => $largestUnit->value,
            'smallestUnit' => $smallestUnit->value,
            'roundingMode' => $roundingMode->value,
            'roundingIncrement' => $roundingIncrement,
        ]);

        return Duration::fromSpec($specDuration);
    }

    /**
     * Returns a new PlainDateTime rounded to the given unit and increment.
     *
     * @param Unit         $smallestUnit      The unit to round to.
     * @param RoundingMode $roundingMode      Rounding mode (default: HalfExpand).
     * @param int          $roundingIncrement Must evenly divide the next-larger unit.
     * @return self
     * @throws \Calendrics\Exception\RangeError for invalid unit or increment values.
     */
    public function round(
        Unit $smallestUnit,
        RoundingMode $roundingMode = RoundingMode::HalfExpand,
        int $roundingIncrement = 1,
    ): self {
        $spec = $this->spec->round([
            'smallestUnit' => $smallestUnit->value,
            'roundingMode' => $roundingMode->value,
            'roundingIncrement' => $roundingIncrement,
        ]);

        return self::fromSpec($spec);
    }

    /**
     * Returns true if this PlainDateTime represents the same date and time as $other.
     *
     * @param self $other The datetime to compare with.
     * @return bool
     */
    public function equals(self $other): bool
    {
        return $this->spec->equals($other->spec);
    }

    /**
     * Returns the ISO 8601 string representation of this datetime.
     *
     * @param CalendarDisplay $calendarName          Whether to include the calendar annotation.
     * @param int|null        $fractionalSecondDigits Number of fractional second digits (0–9), or null for 'auto'.
     * @param Unit|null       $smallestUnit           Smallest unit to display; overrides $fractionalSecondDigits.
     * @param RoundingMode    $roundingMode           Rounding mode for display (default: Trunc).
     * @return string
     */
    public function toString(
        CalendarDisplay $calendarName = CalendarDisplay::Auto,
        ?int $fractionalSecondDigits = null,
        ?Unit $smallestUnit = null,
        RoundingMode $roundingMode = RoundingMode::Trunc,
    ): string {
        $opts = ['calendarName' => $calendarName->value];

        if ($fractionalSecondDigits !== null) {
            $opts['fractionalSecondDigits'] = $fractionalSecondDigits;
        }
        if ($smallestUnit !== null) {
            $opts['smallestUnit'] = $smallestUnit->value;
        }
        $opts['roundingMode'] = $roundingMode->value;

        return $this->spec->toString($opts);
    }

    /**
     * Returns a locale-aware string representation of this date-time.
     *
     * Supply `dateStyle` and/or `timeStyle` (locale-provided presets) or any
     * combination of the individual component options — mixing the two throws.
     * With nothing set, the locale's default date and time are rendered.
     *
     * ```php
     * $dt->toLocaleString('de-AT', dateStyle: FormatStyle::Long, timeStyle: FormatStyle::Short);
     * $dt->toLocaleString('en-US', hour: NumberWidth::Numeric, minute: NumberWidth::TwoDigit);
     * ```
     *
     * @param string|null      $locale    BCP 47 locale tag; null uses the ICU default locale.
     * @param FormatStyle|null $dateStyle Preset date verbosity; excludes the component options below.
     * @param FormatStyle|null $timeStyle Preset time verbosity; excludes the component options below.
     * @param TextWidth|null   $weekday
     * @param TextWidth|null   $era
     * @param NumberWidth|null $year
     * @param MonthWidth|null  $month
     * @param NumberWidth|null $day
     * @param TextWidth|null   $dayPeriod
     * @param NumberWidth|null $hour
     * @param NumberWidth|null $minute
     * @param NumberWidth|null $second
     * @param int|null         $fractionalSecondDigits Number of sub-second digits to render (1–3).
     * @param HourCycle|null   $hourCycle Hour numbering; null lets the locale decide.
     * @param Calendar|null    $calendar  Calendar to render in; null keeps the locale's own calendar.
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
            'hourCycle' => $hourCycle,
            'calendar' => $calendar,
        ]));
    }

    /**
     * Returns the date part as a PlainDate.
     *
     * @return PlainDate
     */
    public function toPlainDate(): PlainDate
    {
        return PlainDate::fromSpec($this->spec->toPlainDate());
    }

    /**
     * Returns the time part as a PlainTime.
     *
     * @return PlainTime
     */
    public function toPlainTime(): PlainTime
    {
        return PlainTime::fromSpec($this->spec->toPlainTime());
    }

    /**
     * Returns a new PlainDateTime with the time part replaced.
     *
     * When called with no argument (or null), the time defaults to midnight (00:00:00).
     *
     * @param PlainTime|null $time The time to set, or null for midnight.
     * @return self
     */
    public function withPlainTime(?PlainTime $time = null): self
    {
        if ($time === null) {
            $spec = $this->spec->withPlainTime();
        } else {
            $spec = $this->spec->withPlainTime($time->toSpec());
        }

        return self::fromSpec($spec);
    }

    /**
     * Converts this datetime to a ZonedDateTime in the given time zone.
     *
     * @api
     * @param string         $timeZone      IANA time zone identifier or UTC offset string.
     * @param Disambiguation $disambiguation How to resolve ambiguous wall-clock times.
     * @return ZonedDateTime
     * @throws \Calendrics\Exception\RangeError if the time zone is invalid or the result is out of range.
     */
    public function toZonedDateTime(
        string $timeZone,
        Disambiguation $disambiguation = Disambiguation::Compatible,
    ): ZonedDateTime {
        $specZdt = $this->spec->toZonedDateTime($timeZone, [
            'disambiguation' => $disambiguation->value,
        ]);

        return ZonedDateTime::fromSpec($specZdt);
    }

    // -------------------------------------------------------------------------
    // Stringable / JsonSerializable
    // -------------------------------------------------------------------------

    /**
     * Returns the ISO 8601 string representation.
     *
     * @return string
     */
    #[\Override]
    public function __toString(): string
    {
        return $this->toString();
    }

    /**
     * Returns the ISO 8601 string for JSON serialization.
     *
     * @return string
     */
    #[\Override]
    public function jsonSerialize(): string
    {
        return $this->toString();
    }

    // -------------------------------------------------------------------------
    // Spec-layer interop
    // -------------------------------------------------------------------------

    /**
     * Returns the underlying spec-layer PlainDateTime.
     *
     * @return SpecPlainDateTime
     */
    public function toSpec(): SpecPlainDateTime
    {
        return $this->spec;
    }

    /**
     * Creates a porcelain PlainDateTime from a spec-layer PlainDateTime.
     *
     * @param SpecPlainDateTime $spec The spec-layer instance to wrap.
     * @return self
     */
    public static function fromSpec(SpecPlainDateTime $spec): self
    {
        return new self(
            $spec->isoYear,
            $spec->isoMonth,
            $spec->isoDay,
            $spec->hour,
            $spec->minute,
            $spec->second,
            $spec->millisecond,
            $spec->microsecond,
            $spec->nanosecond,
            Calendar::from($spec->calendarId),
        );
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
            'year' => $this->year,
            'month' => $this->month,
            'day' => $this->day,
            'hour' => $this->hour,
            'minute' => $this->minute,
            'second' => $this->second,
            'millisecond' => $this->millisecond,
            'microsecond' => $this->microsecond,
            'nanosecond' => $this->nanosecond,
            'calendar' => $this->calendar,
            'iso' => $this->toString(),
        ];
    }
}
