<?php

declare(strict_types=1);

namespace Temporal;

use Temporal\Exception\InvalidArgument;

/**
 * A reusable, locale-aware formatter for porcelain Temporal values.
 *
 * This is the porcelain counterpart to the spec layer's `toLocaleString()`.
 * Where the spec layer takes an ECMA-402 option bag of magic strings, this
 * class takes backed enums, so call sites are checked by PHPStan and Psalm
 * rather than at runtime:
 *
 * ```php
 * $format = DateTimeFormat::styled('de-AT', date: DateStyle::Full, time: TimeStyle::Short);
 *
 * $format->format(PlainDate::parse('2024-03-15'));  // 'Freitag, 15. März 2024'
 * $format->format(Now::zonedDateTime());            // reuses the same formatter
 * ```
 *
 * ECMA-402 forbids mixing the `dateStyle`/`timeStyle` presets with individual
 * component options. Rather than accepting both and throwing, this class splits
 * them across two constructors — {@see styled()} for the presets,
 * {@see components()} for field-by-field control — so the invalid combination
 * cannot be written in the first place.
 *
 * Instances are immutable and carry no ICU state, so a formatter can be built
 * once — at config time, say — and reused for any number of values.
 *
 * Which options apply depends on the value being formatted, mirroring TC39:
 *
 * - {@see PlainDate}, {@see PlainYearMonth} and {@see PlainMonthDay} reject a
 *   time preset; {@see PlainTime} rejects a date preset. Both throw
 *   {@see \Temporal\Exception\TypeError}.
 * - {@see ZonedDateTime} carries its own zone, so passing `timeZone` throws
 *   {@see \Temporal\Exception\TypeError}. Use `timeZone` to project an
 *   {@see Instant} into a civil zone; on the `Plain*` types it is ignored,
 *   because they denote no instant to project.
 * - A value in a non-ISO calendar can only be rendered by a formatter that
 *   resolves to that same calendar, otherwise its fields would be silently
 *   reinterpreted; the mismatch throws {@see \Temporal\Exception\RangeError}.
 *   Pass `calendar` to make the formatter's calendar explicit.
 *
 * {@see Duration} is deliberately absent from {@see format()}: locale-aware
 * duration rendering is `Intl.DurationFormat` territory, which ext-intl does
 * not expose, so the spec layer's `Duration::toLocaleString()` falls back to
 * the ISO 8601 form that `(string) $duration` already gives you.
 *
 * Requires `ext-intl`.
 *
 * @psalm-api
 */
final readonly class DateTimeFormat
{
    /**
     * @param array<string, string|int> $options ECMA-402 option bag, already lowered from enums.
     * @param string|null               $locale  BCP 47 locale tag, or null for the system default.
     */
    private function __construct(
        private array $options,
        public ?string $locale,
    ) {}

    /**
     * Builds a formatter from the `dateStyle` / `timeStyle` presets.
     *
     * Omitting both leaves the level of detail to the value's own default: a
     * date for {@see PlainDate}, a time for {@see PlainTime}, a date and time
     * for the rest.
     *
     * @param string|null    $locale    BCP 47 locale tag (e.g. "de-AT"); null uses the system default.
     * @param DateStyle|null $date      Preset for the date portion.
     * @param TimeStyle|null $time      Preset for the time portion.
     * @param Calendar|null  $calendar  Calendar to render in; null lets the locale decide.
     * @param HourCycle|null $hourCycle Hour numbering override; null lets the locale decide.
     * @param string|null    $timeZone  IANA zone id used to place an {@see Instant}; ignored for `Plain*`.
     */
    public static function styled(
        ?string $locale = null,
        ?DateStyle $date = null,
        ?TimeStyle $time = null,
        ?Calendar $calendar = null,
        ?HourCycle $hourCycle = null,
        ?string $timeZone = null,
    ): self {
        return new self(self::compact([
            'dateStyle' => $date?->value,
            'timeStyle' => $time?->value,
            'calendar' => $calendar?->value,
            'hourCycle' => $hourCycle?->value,
            'timeZone' => $timeZone,
        ]), $locale);
    }

    /**
     * Builds a formatter from individual date/time components.
     *
     * Every component defaults to null, meaning "leave it out". Supplying only
     * auxiliary fields — `era`, `timeZoneName` — still yields the value's
     * default date and/or time components alongside them, per ECMA-402.
     *
     * @param string|null            $locale                 BCP 47 locale tag; null uses the system default.
     * @param TextWidth|null         $weekday                Weekday name width.
     * @param TextWidth|null         $era                    Era name width (e.g. "AD", "Anno Domini").
     * @param NumericWidth|null      $year                   Year width.
     * @param MonthWidth|null        $month                  Month width, numeric or spelled out.
     * @param NumericWidth|null      $day                    Day-of-month width.
     * @param NumericWidth|null      $hour                   Hour width.
     * @param NumericWidth|null      $minute                 Minute width.
     * @param NumericWidth|null      $second                 Second width.
     * @param int|null               $fractionalSecondDigits Digits of sub-second precision to show (1–3).
     * @param TextWidth|null         $dayPeriod              Day-period width ("in the morning" vs "AM").
     * @param TimeZoneNameStyle|null $timeZoneName           How to name the zone.
     * @param HourCycle|null         $hourCycle              Hour numbering override.
     * @param Calendar|null          $calendar               Calendar to render in.
     * @param string|null            $timeZone               IANA zone id used to place an {@see Instant}.
     * @throws InvalidArgument if $fractionalSecondDigits is outside 1–3.
     */
    public static function components(
        ?string $locale = null,
        ?TextWidth $weekday = null,
        ?TextWidth $era = null,
        ?NumericWidth $year = null,
        ?MonthWidth $month = null,
        ?NumericWidth $day = null,
        ?NumericWidth $hour = null,
        ?NumericWidth $minute = null,
        ?NumericWidth $second = null,
        ?int $fractionalSecondDigits = null,
        ?TextWidth $dayPeriod = null,
        ?TimeZoneNameStyle $timeZoneName = null,
        ?HourCycle $hourCycle = null,
        ?Calendar $calendar = null,
        ?string $timeZone = null,
    ): self {
        if ($fractionalSecondDigits !== null && ($fractionalSecondDigits < 1 || $fractionalSecondDigits > 3)) {
            throw new InvalidArgument("fractionalSecondDigits must be between 1 and 3, got {$fractionalSecondDigits}.");
        }

        return new self(self::compact([
            'weekday' => $weekday?->value,
            'era' => $era?->value,
            'year' => $year?->value,
            'month' => $month?->value,
            'day' => $day?->value,
            'hour' => $hour?->value,
            'minute' => $minute?->value,
            'second' => $second?->value,
            'fractionalSecondDigits' => $fractionalSecondDigits,
            'dayPeriod' => $dayPeriod?->value,
            'timeZoneName' => $timeZoneName?->value,
            'calendar' => $calendar?->value,
            'hourCycle' => $hourCycle?->value,
            'timeZone' => $timeZone,
        ]), $locale);
    }

    /**
     * Renders a Temporal value in this formatter's locale.
     *
     * @param PlainDate|PlainDateTime|PlainTime|PlainYearMonth|PlainMonthDay|Instant|ZonedDateTime $value
     * @throws \Temporal\Exception\TypeError if an option does not apply to $value's type.
     * @throws \Temporal\Exception\RangeError if $value's calendar is not the one the formatter resolves to.
     */
    public function format(PlainDate|PlainDateTime|PlainTime|PlainYearMonth|PlainMonthDay|Instant|ZonedDateTime $value): string
    {
        return $value->toSpec()->toLocaleString($this->locale, $this->options);
    }

    /**
     * Drops unset options so the spec layer sees an absent key rather than a
     * null one — the two are not interchangeable there: a present-but-null
     * `timeZone` still trips {@see ZonedDateTime}'s rejection of the option.
     *
     * @param array<string, string|int|null> $options
     * @return array<string, string|int>
     */
    private static function compact(array $options): array
    {
        $set = [];
        foreach ($options as $key => $value) {
            if ($value === null) {
                continue;
            }
            $set[$key] = $value;
        }

        return $set;
    }
}
