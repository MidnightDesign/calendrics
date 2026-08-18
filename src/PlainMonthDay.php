<?php

declare(strict_types=1);

namespace Calendrics;

use Calendrics\Spec\PlainMonthDay as SpecPlainMonthDay;
use Calendrics\Trait\HasLocalizedFormatting;

/**
 * A calendar month-day without a year, time, or time zone.
 *
 * This is the porcelain (user-facing) wrapper around the spec-layer
 * {@see SpecPlainMonthDay}. It provides typed enums, named parameters, and
 * value-object semantics while delegating all calendar math to the spec layer.
 */
final class PlainMonthDay implements \Stringable, \JsonSerializable
{
    use HasLocalizedFormatting;

    // -------------------------------------------------------------------------
    // Virtual (get-only) properties — delegated to the spec instance
    // -------------------------------------------------------------------------

    /**
     * Month of the year (projected through the active calendar).
     *
     * @psalm-suppress PropertyNotSetInConstructor
     */
    public int $month {
        get => $this->spec->month;
    }

    /**
     * Day of the month (projected through the active calendar).
     *
     * @psalm-suppress PropertyNotSetInConstructor
     */
    public int $day {
        get => $this->spec->day;
    }

    /**
     * Calendar system used by this month-day.
     *
     * @psalm-suppress PropertyNotSetInConstructor
     */
    public Calendar $calendar {
        get => Calendar::from($this->spec->calendarId);
    }

    /**
     * Month code in "M01"–"M12" format (or "M01L"–"M12L" for leap months).
     *
     * @psalm-suppress PropertyNotSetInConstructor
     */
    public string $monthCode {
        get => $this->spec->monthCode;
    }

    // -------------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------------

    private readonly SpecPlainMonthDay $spec;

    /**
     * Creates a new PlainMonthDay from ISO month and day.
     *
     * @param int<1, 12> $isoMonth        ISO month of the year (1–12).
     * @param int<1, 31> $isoDay          ISO day of the month (1–31, depending on month).
     * @param Calendar   $calendar        Calendar system (default ISO 8601).
     * @param int        $referenceISOYear Reference ISO year for round-trip fidelity (default 1972).
     * @throws \Calendrics\Exception\RangeError if the month-day is invalid or out of range.
     */
    public function __construct(
        int $isoMonth,
        int $isoDay,
        Calendar $calendar = Calendar::Iso8601,
        int $referenceISOYear = 1972,
    ) {
        $this->spec = new SpecPlainMonthDay($isoMonth, $isoDay, $calendar->value, $referenceISOYear);
    }

    // -------------------------------------------------------------------------
    // Static factory methods
    // -------------------------------------------------------------------------

    /**
     * Creates a PlainMonthDay from calendar fields.
     *
     * Supply either `month` or `monthCode`. The optional `year` field lets
     * non-ISO calendars disambiguate leap-month days.
     *
     * @param int<1, 12>|null         $month
     * @param non-empty-string|null   $monthCode
     * @param int<1, 31>|null         $day
     * @param Calendar                $calendar
     * @param int|null                $year
     * @param non-empty-string|null   $era
     * @param int|null                $eraYear
     * @param Overflow                $overflow
     */
    public static function fromFields(
        ?int $month = null,
        ?string $monthCode = null,
        ?int $day = null,
        Calendar $calendar = Calendar::Iso8601,
        ?int $year = null,
        ?string $era = null,
        ?int $eraYear = null,
        Overflow $overflow = Overflow::Constrain,
    ): self {
        $fields = ['calendar' => $calendar->value];
        if ($month !== null) {
            $fields['month'] = $month;
        }
        if ($monthCode !== null) {
            $fields['monthCode'] = $monthCode;
        }
        if ($day !== null) {
            $fields['day'] = $day;
        }
        if ($year !== null) {
            $fields['year'] = $year;
        }
        if ($era !== null) {
            $fields['era'] = $era;
        }
        if ($eraYear !== null) {
            $fields['eraYear'] = $eraYear;
        }

        return self::fromSpec(SpecPlainMonthDay::from($fields, ['overflow' => $overflow->value]));
    }

    /**
     * Parses an ISO 8601 month-day string into a PlainMonthDay.
     *
     * @param string $text ISO 8601 month-day string (e.g. "--12-25" or "12-25").
     * @return self
     * @throws \Calendrics\Exception\RangeError if the string cannot be parsed.
     */
    public static function parse(string $text): self
    {
        $spec = SpecPlainMonthDay::from($text);

        return self::fromSpec($spec);
    }

    // -------------------------------------------------------------------------
    // Instance methods
    // -------------------------------------------------------------------------

    /**
     * Returns a new PlainMonthDay with the specified fields overridden.
     *
     * @param int<1, 12>|null $month     Month override (1–12), or null to keep current.
     * @param int<1, 31>|null $day       Day override, or null to keep current.
     * @param Overflow        $overflow  How to handle out-of-range values.
     * @return self A new PlainMonthDay with the overridden fields.
     * @throws \Calendrics\Exception\RangeError if the resulting month-day is invalid (overflow: reject) or fields conflict.
     */
    public function with(
        ?int $month = null,
        ?int $day = null,
        ?string $monthCode = null,
        Overflow $overflow = Overflow::Constrain,
    ): self {
        $fields = [];
        if ($month !== null) {
            $fields['month'] = $month;
        }
        if ($day !== null) {
            $fields['day'] = $day;
        }
        if ($monthCode !== null) {
            $fields['monthCode'] = $monthCode;
        }

        $spec = $this->spec->with($fields, ['overflow' => $overflow->value]);

        return self::fromSpec($spec);
    }

    /**
     * Returns true if this PlainMonthDay represents the same month-day as $other.
     *
     * @param self $other The month-day to compare with.
     * @return bool
     */
    public function equals(self $other): bool
    {
        return $this->spec->equals($other->spec);
    }

    /**
     * Returns the ISO 8601 string representation of this month-day.
     *
     * @param CalendarDisplay $calendarName Whether to include the calendar annotation.
     * @return string
     */
    public function toString(CalendarDisplay $calendarName = CalendarDisplay::Auto): string
    {
        return $this->spec->toString(['calendarName' => $calendarName->value]);
    }

    /**
     * Returns a locale-aware string representation of this month-day.
     *
     * A `PlainMonthDay` has no year, so the year, era, and weekday options are
     * absent from this signature. Supply `dateStyle` (a locale-provided preset,
     * with the year component stripped out) or any combination of the individual
     * component options — mixing the two throws.
     *
     * Unlike `PlainDate`, an ISO 8601 month-day is *not* projectable into another
     * calendar: a bare month-day has no meaning outside the calendar it was
     * expressed in, so the value's calendar must match the formatter's. Since no
     * locale resolves to `iso8601`, formatting a default-constructed (ISO)
     * month-day always throws — build it in the target calendar instead:
     *
     * ```php
     * $md = PlainMonthDay::fromFields(monthCode: 'M06', day: 15, calendar: Calendar::Gregory);
     * $md->toLocaleString('de-AT', dateStyle: FormatStyle::Long);              // '15 Juni'
     * $md->toLocaleString('en-US', month: MonthWidth::Short, day: NumberWidth::Numeric); // 'Jun 15'
     * ```
     *
     * @param string|null      $locale    BCP 47 locale tag; null uses the ICU default locale.
     * @param FormatStyle|null $dateStyle Preset date verbosity; excludes the component options below.
     * @param MonthWidth|null  $month
     * @param NumberWidth|null $day
     * @param Calendar|null    $calendar  Calendar to render in; null keeps the locale's own calendar.
     * @return string
     * @throws \Calendrics\Exception\TypeError if `dateStyle` is combined with a component option.
     * @throws \Calendrics\Exception\RangeError if this value's calendar differs from the resolved
     *                                        formatter's.
     */
    public function toLocaleString(
        ?string $locale = null,
        ?FormatStyle $dateStyle = null,
        ?MonthWidth $month = null,
        ?NumberWidth $day = null,
        ?Calendar $calendar = null,
    ): string {
        return $this->spec->toLocaleString($locale, self::localeOptions([
            'dateStyle' => $dateStyle,
            'month' => $month,
            'day' => $day,
            'calendar' => $calendar,
        ]));
    }

    /**
     * Converts this month-day to a PlainDate by supplying the year.
     *
     * The day is constrained to the valid range for that year's month.
     *
     * @param int $year The year to combine with this month-day.
     * @return PlainDate
     * @throws \Calendrics\Exception\RangeError if the resulting date is invalid.
     */
    public function toPlainDate(int $year): PlainDate
    {
        return PlainDate::fromSpec($this->spec->toPlainDate(['year' => $year]));
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
     * Returns the underlying spec-layer PlainMonthDay.
     *
     * @return SpecPlainMonthDay
     */
    public function toSpec(): SpecPlainMonthDay
    {
        return $this->spec;
    }

    /**
     * Creates a porcelain PlainMonthDay from a spec-layer PlainMonthDay.
     *
     * @param SpecPlainMonthDay $spec The spec-layer instance to wrap.
     * @return self
     */
    public static function fromSpec(SpecPlainMonthDay $spec): self
    {
        return new self($spec->isoMonth, $spec->isoDay, Calendar::from($spec->calendarId), $spec->referenceISOYear);
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
            'month' => $this->month,
            'day' => $this->day,
            'calendar' => $this->calendar,
            'iso' => $this->toString(),
        ];
    }
}
