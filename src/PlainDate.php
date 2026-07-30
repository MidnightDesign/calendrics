<?php

declare(strict_types=1);

namespace Temporal;

use Temporal\Spec\PlainDate as SpecPlainDate;
use Temporal\Trait\HasDayOfMonthProperties;
use Temporal\Trait\HasDayOfMonthSpec;
use Temporal\Trait\HasLocalizedString;
use Temporal\Trait\HasYearMonthProperties;
use Temporal\Trait\HasYearMonthSpec;

/**
 * A calendar date without a time or time zone.
 *
 * This is the porcelain (user-facing) wrapper around the spec-layer
 * {@see SpecPlainDate}. It provides typed enums, named parameters, and
 * value-object semantics while delegating all calendar math to the spec layer.
 */
final class PlainDate implements \Stringable, \JsonSerializable, HasYearMonthSpec, HasDayOfMonthSpec
{
    use HasYearMonthProperties;
    use HasDayOfMonthProperties;
    use HasLocalizedString;

    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    private readonly SpecPlainDate $spec;

    /**
     * Creates a new PlainDate from ISO year, month, and day.
     *
     * @param int          $isoYear  ISO year.
     * @param int<1, 12>   $isoMonth ISO month of the year (1–12).
     * @param int<1, 31>   $isoDay   ISO day of the month (1–31, depending on month/year).
     * @param Calendar     $calendar Calendar system to project through.
     * @throws \Temporal\Exception\RangeError if the date is invalid or out of range.
     */
    public function __construct(int $isoYear, int $isoMonth, int $isoDay, Calendar $calendar = Calendar::Iso8601)
    {
        $this->spec = new SpecPlainDate($isoYear, $isoMonth, $isoDay, $calendar->value);
    }

    // -------------------------------------------------------------------------
    // Static factory / comparison methods
    // -------------------------------------------------------------------------

    /**
     * Creates a PlainDate from calendar fields.
     *
     * Supply either `year` or `era` + `eraYear`, and either `month` or `monthCode`.
     * All other fields default sensibly; unsupplied fields are omitted.
     *
     * @param int|null                $year
     * @param int<1, 12>|null         $month
     * @param non-empty-string|null   $monthCode
     * @param int<1, 31>|null         $day
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
        if ($era !== null) {
            $fields['era'] = $era;
        }
        if ($eraYear !== null) {
            $fields['eraYear'] = $eraYear;
        }

        return self::fromSpec(SpecPlainDate::from($fields, ['overflow' => $overflow->value]));
    }

    /**
     * Parses an ISO 8601 date string into a PlainDate.
     *
     * @param string $text ISO 8601 date string (e.g. "2020-01-01").
     * @return self
     * @throws \Temporal\Exception\RangeError if the string cannot be parsed.
     */
    public static function parse(string $text): self
    {
        $spec = SpecPlainDate::from($text);

        return self::fromSpec($spec);
    }

    /**
     * Creates a PlainDate from a PHP `\DateTimeInterface`.
     *
     * The year/month/day are read from `$dt`'s own time zone (via the `Y`, `n`,
     * `j` format specifiers); the time-of-day and time-zone identifier are
     * dropped because PlainDate carries neither.
     *
     * @param \DateTimeInterface $dt       Source date-time; its own zone determines the civil date.
     * @param Calendar           $calendar Calendar projection (default ISO 8601).
     */
    public static function fromDateTime(\DateTimeInterface $dt, Calendar $calendar = Calendar::Iso8601): self
    {
        return self::fromSpec(
            new SpecPlainDate((int) $dt->format('Y'), (int) $dt->format('n'), (int) $dt->format('j'), $calendar->value),
        );
    }

    /**
     * Compares two PlainDates chronologically.
     *
     * @return int Negative if $one is earlier, positive if later, zero if equal.
     */
    public static function compare(self $one, self $two): int
    {
        return SpecPlainDate::compare($one->spec, $two->spec);
    }

    // -------------------------------------------------------------------------
    // Instance methods
    // -------------------------------------------------------------------------

    /**
     * Returns a new PlainDate with the specified fields overridden.
     *
     * @param int|null         $year      Year override, or null to keep current.
     * @param int<1, 12>|null  $month     Month override (1–12), or null to keep current.
     * @param int<1, 31>|null  $day       Day override, or null to keep current.
     * @param string|null      $monthCode Month code override (e.g. "M01"), or null to keep current.
     * @param string|null      $era       Era override (e.g. "ce"), or null to keep current.
     * @param int|null         $eraYear   Era year override, or null to keep current.
     * @param Overflow         $overflow  How to handle out-of-range values.
     * @return self A new PlainDate with the overridden fields.
     * @throws \Temporal\Exception\RangeError if the resulting date is invalid (overflow: reject) or fields conflict.
     */
    public function with(
        ?int $year = null,
        ?int $month = null,
        ?int $day = null,
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
        if ($monthCode !== null) {
            $fields['monthCode'] = $monthCode;
        }
        if ($era !== null) {
            $fields['era'] = $era;
        }
        if ($eraYear !== null) {
            $fields['eraYear'] = $eraYear;
        }

        if ($fields === []) {
            return $this;
        }

        $spec = $this->spec->with($fields, ['overflow' => $overflow->value]);

        return self::fromSpec($spec);
    }

    /**
     * Returns a new PlainDate with a different calendar system.
     *
     * The underlying ISO date remains the same; only the calendar projection changes.
     */
    public function withCalendar(Calendar $calendar): self
    {
        return self::fromSpec($this->spec->withCalendar($calendar->value));
    }

    /**
     * Returns a new PlainDate with the given duration added.
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
     * Returns a new PlainDate with the given duration subtracted.
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
     * Returns the duration from $other to this date (this - other).
     *
     * @param self         $other             The other date to measure from.
     * @param Unit         $largestUnit       The largest unit to use in the result.
     * @param Unit         $smallestUnit      The smallest unit to use in the result.
     * @param RoundingMode $roundingMode      How to round the result.
     * @param int          $roundingIncrement The rounding increment for the smallest unit.
     * @return Duration
     */
    public function since(
        self $other,
        Unit $largestUnit = Unit::Day,
        Unit $smallestUnit = Unit::Day,
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
     * Returns the duration from this date to $other (other - this).
     *
     * @param self         $other             The other date to measure to.
     * @param Unit         $largestUnit       The largest unit to use in the result.
     * @param Unit         $smallestUnit      The smallest unit to use in the result.
     * @param RoundingMode $roundingMode      How to round the result.
     * @param int          $roundingIncrement The rounding increment for the smallest unit.
     * @return Duration
     */
    public function until(
        self $other,
        Unit $largestUnit = Unit::Day,
        Unit $smallestUnit = Unit::Day,
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
     * Returns true if this PlainDate represents the same date as $other.
     *
     * @param self $other The date to compare with.
     * @return bool
     */
    public function equals(self $other): bool
    {
        return $this->spec->equals($other->spec);
    }

    /**
     * Returns the ISO 8601 string representation of this date.
     *
     * @param CalendarDisplay $calendarName Whether to include the calendar annotation.
     * @return string
     */
    public function toString(CalendarDisplay $calendarName = CalendarDisplay::Auto): string
    {
        return $this->spec->toString(['calendarName' => $calendarName->value]);
    }

    /**
     * Returns this date rendered for human readers in the given locale.
     *
     * Formatting is delegated to ICU via `ext-intl`, so the result follows the
     * locale's CLDR data and can change with the ICU version. It is display
     * text: use {@see self::toString()} whenever the string will be parsed
     * again.
     *
     * A non-ISO {@see Calendar} carried by this date is honoured without being
     * repeated here — a `Calendar::Hebrew` date renders Hebrew month names and
     * year numbering.
     *
     * @param string|null    $locale    BCP 47 locale tag (e.g. "de-DE"), or null for ICU's default locale.
     * @param DateStyle|null $dateStyle How much of the date to spell out, or null for the locale's
     *                                  all-numeric default (e.g. "3/15/2024" in `en-US`).
     * @return string
     */
    public function toLocaleString(?string $locale = null, ?DateStyle $dateStyle = null): string
    {
        return $this->spec->toLocaleString($locale, self::localeStyleOptions(dateStyle: $dateStyle));
    }

    /**
     * Combines this date with a time to produce a PlainDateTime.
     *
     * If no time is given, midnight (00:00:00) is used.
     *
     * @param PlainTime|null $time The time to combine with, or null for midnight.
     * @return PlainDateTime
     */
    public function toPlainDateTime(?PlainTime $time = null): PlainDateTime
    {
        if ($time === null) {
            $specDateTime = $this->spec->toPlainDateTime();
        } else {
            $specDateTime = $this->spec->toPlainDateTime($time->toSpec());
        }

        return PlainDateTime::fromSpec($specDateTime);
    }

    /**
     * Converts this date to a ZonedDateTime in the given timezone.
     *
     * @param string         $timeZone IANA timezone identifier or UTC offset string.
     * @param PlainTime|null $time     Optional time; if null, midnight is used.
     * @return ZonedDateTime
     * @throws \Temporal\Exception\RangeError if the timezone is invalid or the result is out of range.
     */
    public function toZonedDateTime(string $timeZone, ?PlainTime $time = null): ZonedDateTime
    {
        if ($time === null) {
            $specZdt = $this->spec->toZonedDateTime($timeZone);
        } else {
            $specZdt = $this->spec->toZonedDateTime([
                'timeZone' => $timeZone,
                'plainTime' => $time->toSpec(),
            ]);
        }

        return ZonedDateTime::fromSpec($specZdt);
    }

    /**
     * Returns a PlainYearMonth from this date's year and month.
     *
     * @return PlainYearMonth
     * @psalm-api
     */
    public function toPlainYearMonth(): PlainYearMonth
    {
        return PlainYearMonth::fromSpec($this->spec->toPlainYearMonth());
    }

    /**
     * Returns a PlainMonthDay from this date's month and day.
     *
     * @return PlainMonthDay
     * @psalm-api
     */
    public function toPlainMonthDay(): PlainMonthDay
    {
        return PlainMonthDay::fromSpec($this->spec->toPlainMonthDay());
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
     * Returns the underlying spec-layer PlainDate.
     *
     * @return SpecPlainDate
     */
    public function toSpec(): SpecPlainDate
    {
        return $this->spec;
    }

    /**
     * Creates a porcelain PlainDate from a spec-layer PlainDate.
     *
     * @param SpecPlainDate $spec The spec-layer instance to wrap.
     * @return self
     */
    public static function fromSpec(SpecPlainDate $spec): self
    {
        return new self($spec->isoYear, $spec->isoMonth, $spec->isoDay, Calendar::from($spec->calendarId));
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
            'calendar' => $this->calendar,
            'iso' => $this->toString(),
        ];
    }
}
