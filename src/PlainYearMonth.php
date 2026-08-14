<?php

declare(strict_types=1);

namespace Temporal;

use Temporal\Spec\PlainYearMonth as SpecPlainYearMonth;
use Temporal\Trait\HasLocalizedFormatting;
use Temporal\Trait\HasYearMonthProperties;
use Temporal\Trait\HasYearMonthSpec;

/**
 * A calendar year-month without a specific day, time, or time zone.
 *
 * This is the porcelain (user-facing) wrapper around the spec-layer
 * {@see SpecPlainYearMonth}. It provides typed enums, named parameters, and
 * value-object semantics while delegating all calendar math to the spec layer.
 */
final class PlainYearMonth implements \Stringable, \JsonSerializable, HasYearMonthSpec
{
    use HasYearMonthProperties;
    use HasLocalizedFormatting;

    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    private readonly SpecPlainYearMonth $spec;

    /**
     * Creates a new PlainYearMonth from ISO year and month.
     *
     * @param int        $isoYear        ISO year.
     * @param int<1, 12> $isoMonth       ISO month of the year (1–12).
     * @param Calendar   $calendar       Calendar system (default ISO 8601).
     * @param int        $referenceISODay Reference ISO day for round-trip fidelity (default 1).
     * @throws \Temporal\Exception\RangeError if the year-month is invalid or out of range.
     */
    public function __construct(
        int $isoYear,
        int $isoMonth,
        Calendar $calendar = Calendar::Iso8601,
        int $referenceISODay = 1,
    ) {
        $this->spec = new SpecPlainYearMonth($isoYear, $isoMonth, $calendar->value, $referenceISODay);
    }

    // -------------------------------------------------------------------------
    // Static factory / comparison methods
    // -------------------------------------------------------------------------

    /**
     * Creates a PlainYearMonth from calendar fields.
     *
     * Supply either `year` or `era` + `eraYear`, and either `month` or `monthCode`.
     *
     * No `overflow` parameter: `$month` is already bounded by `int<1, 12>`, and
     * `PlainYearMonth` has no `day` field, so there is no out-of-range value the
     * porcelain API accepts that would behave differently under constrain vs.
     * reject.
     *
     * @param int|null                $year
     * @param int<1, 12>|null         $month
     * @param non-empty-string|null   $monthCode
     * @param Calendar                $calendar
     * @param non-empty-string|null   $era
     * @param int|null                $eraYear
     */
    public static function fromFields(
        ?int $year = null,
        ?int $month = null,
        ?string $monthCode = null,
        Calendar $calendar = Calendar::Iso8601,
        ?string $era = null,
        ?int $eraYear = null,
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
        if ($era !== null) {
            $fields['era'] = $era;
        }
        if ($eraYear !== null) {
            $fields['eraYear'] = $eraYear;
        }

        return self::fromSpec(SpecPlainYearMonth::from($fields));
    }

    /**
     * Parses an ISO 8601 year-month string into a PlainYearMonth.
     *
     * @param string $text ISO 8601 year-month string (e.g. "2020-01").
     * @return self
     * @throws \Temporal\Exception\RangeError if the string cannot be parsed.
     */
    public static function parse(string $text): self
    {
        $spec = SpecPlainYearMonth::from($text);

        return self::fromSpec($spec);
    }

    /**
     * Compares two PlainYearMonths chronologically.
     *
     * @return int Negative if $one is earlier, positive if later, zero if equal.
     */
    public static function compare(self $one, self $two): int
    {
        return SpecPlainYearMonth::compare($one->spec, $two->spec);
    }

    // -------------------------------------------------------------------------
    // Instance methods
    // -------------------------------------------------------------------------

    /**
     * Returns a new PlainYearMonth with the specified fields overridden.
     *
     * @param int|null        $year  Year override, or null to keep current.
     * @param int<1, 12>|null $month Month override (1–12), or null to keep current.
     * @return self A new PlainYearMonth with the overridden fields.
     * @throws \Temporal\Exception\RangeError if the resulting year-month is invalid or fields conflict.
     */
    public function with(
        ?int $year = null,
        ?int $month = null,
        ?string $monthCode = null,
        ?string $era = null,
        ?int $eraYear = null,
    ): self {
        $fields = [];
        if ($year !== null) {
            $fields['year'] = $year;
        }
        if ($month !== null) {
            $fields['month'] = $month;
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

        $spec = $this->spec->with($fields);

        return self::fromSpec($spec);
    }

    /**
     * Returns a new PlainYearMonth with the given duration added.
     *
     * Only years and months are relevant; weeks and days are rejected.
     *
     * @param Duration $duration The duration to add.
     * @return self
     */
    public function add(Duration $duration): self
    {
        $spec = $this->spec->add($duration->toSpec());

        return self::fromSpec($spec);
    }

    /**
     * Returns a new PlainYearMonth with the given duration subtracted.
     *
     * @param Duration $duration The duration to subtract.
     * @return self
     */
    public function subtract(Duration $duration): self
    {
        $spec = $this->spec->subtract($duration->toSpec());

        return self::fromSpec($spec);
    }

    /**
     * Returns the duration from $other to this year-month (this - other).
     *
     * @param self         $other             The other year-month to measure from.
     * @param Unit         $largestUnit       The largest unit to use in the result.
     * @param Unit         $smallestUnit      The smallest unit to use in the result.
     * @param RoundingMode $roundingMode      How to round the result.
     * @param int          $roundingIncrement The rounding increment for the smallest unit.
     * @return Duration
     */
    public function since(
        self $other,
        Unit $largestUnit = Unit::Year,
        Unit $smallestUnit = Unit::Month,
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
     * Returns the duration from this year-month to $other (other - this).
     *
     * @param self         $other             The other year-month to measure to.
     * @param Unit         $largestUnit       The largest unit to use in the result.
     * @param Unit         $smallestUnit      The smallest unit to use in the result.
     * @param RoundingMode $roundingMode      How to round the result.
     * @param int          $roundingIncrement The rounding increment for the smallest unit.
     * @return Duration
     */
    public function until(
        self $other,
        Unit $largestUnit = Unit::Year,
        Unit $smallestUnit = Unit::Month,
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
     * Returns true if this PlainYearMonth represents the same year-month as $other.
     *
     * @param self $other The year-month to compare with.
     * @return bool
     */
    public function equals(self $other): bool
    {
        return $this->spec->equals($other->spec);
    }

    /**
     * Returns the ISO 8601 string representation of this year-month.
     *
     * @param CalendarDisplay $calendarName Whether to include the calendar annotation.
     * @return string
     */
    public function toString(CalendarDisplay $calendarName = CalendarDisplay::Auto): string
    {
        return $this->spec->toString(['calendarName' => $calendarName->value]);
    }

    /**
     * Returns a locale-aware string representation of this year-month.
     *
     * A `PlainYearMonth` has no day, so the day and weekday options are absent
     * from this signature. Supply `dateStyle` (a locale-provided preset, with the
     * day component stripped out) or any combination of the individual component
     * options — mixing the two throws.
     *
     * Unlike `PlainDate`, an ISO 8601 year-month is *not* projectable into another
     * calendar: a bare year-month has no meaning outside the calendar it was
     * expressed in, so the value's calendar must match the formatter's. Since no
     * locale resolves to `iso8601`, formatting a default-constructed (ISO)
     * year-month always throws — build it in the target calendar instead:
     *
     * ```php
     * $ym = PlainYearMonth::fromFields(year: 2020, month: 6, calendar: Calendar::Gregory);
     * $ym->toLocaleString('de-AT', dateStyle: FormatStyle::Long);               // 'Juni 2020'
     * $ym->toLocaleString('en-US', month: MonthWidth::Long, year: NumberWidth::Numeric); // 'June 2020'
     * ```
     *
     * @param string|null      $locale    BCP 47 locale tag; null uses the ICU default locale.
     * @param FormatStyle|null $dateStyle Preset date verbosity; excludes the component options below.
     * @param TextWidth|null   $era
     * @param NumberWidth|null $year
     * @param MonthWidth|null  $month
     * @param Calendar|null    $calendar  Calendar to render in; null keeps the locale's own calendar.
     * @return string
     * @throws \Temporal\Exception\TypeError if `dateStyle` is combined with a component option.
     * @throws \Temporal\Exception\RangeError if this value's calendar differs from the resolved
     *                                        formatter's.
     */
    public function toLocaleString(
        ?string $locale = null,
        ?FormatStyle $dateStyle = null,
        ?TextWidth $era = null,
        ?NumberWidth $year = null,
        ?MonthWidth $month = null,
        ?Calendar $calendar = null,
    ): string {
        return $this->spec->toLocaleString($locale, self::localeOptions([
            'dateStyle' => $dateStyle,
            'era' => $era,
            'year' => $year,
            'month' => $month,
            'calendar' => $calendar,
        ]));
    }

    /**
     * Converts this year-month to a PlainDate by supplying the day.
     *
     * @param int<1, 31> $day Day of the month.
     * @return PlainDate
     * @throws \Temporal\Exception\RangeError if the resulting date is invalid.
     */
    public function toPlainDate(int $day): PlainDate
    {
        return PlainDate::fromSpec($this->spec->toPlainDate(['day' => $day]));
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
     * Returns the underlying spec-layer PlainYearMonth.
     *
     * @return SpecPlainYearMonth
     */
    public function toSpec(): SpecPlainYearMonth
    {
        return $this->spec;
    }

    /**
     * Creates a porcelain PlainYearMonth from a spec-layer PlainYearMonth.
     *
     * @param SpecPlainYearMonth $spec The spec-layer instance to wrap.
     * @return self
     */
    public static function fromSpec(SpecPlainYearMonth $spec): self
    {
        return new self($spec->isoYear, $spec->isoMonth, Calendar::from($spec->calendarId), $spec->referenceISODay);
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
            'calendar' => $this->calendar,
            'iso' => $this->toString(),
        ];
    }
}
