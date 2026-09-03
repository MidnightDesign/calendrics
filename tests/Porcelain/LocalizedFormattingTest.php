<?php

declare(strict_types=1);

namespace Calendrics\Tests\Porcelain;

use Calendrics\Calendar;
use Calendrics\Exception\RangeError;
use Calendrics\Exception\TypeError;
use Calendrics\FormatStyle;
use Calendrics\HourCycle;
use Calendrics\Instant;
use Calendrics\MonthWidth;
use Calendrics\NumberWidth;
use Calendrics\PlainDate;
use Calendrics\PlainDateTime;
use Calendrics\PlainMonthDay;
use Calendrics\PlainTime;
use Calendrics\PlainYearMonth;
use Calendrics\TextWidth;
use Calendrics\TimeZoneNameStyle;
use Calendrics\ZonedDateTime;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Covers the porcelain `toLocaleString()` surface.
 *
 * ICU's exact wording drifts between releases, so almost nothing here pins a
 * literal locale string. Instead each option is asserted to *change* the output
 * relative to the same value formatted with no options at all — which is what
 * the option is for, and which holds regardless of the CLDR data behind it.
 *
 * Every provider below yields closures rather than rendered strings. PHPUnit runs a
 * data provider during collection, before coverage recording starts, so a provider
 * that formatted eagerly would still assert correctly while recording no coverage at
 * all for the formatter it exercises.
 */
final class LocalizedFormattingTest extends TestCase
{
    private const string LOCALE = 'en-US';

    /** A locale whose default calendar is not Gregorian, used to observe the `calendar` option. */
    private const string BUDDHIST_LOCALE = 'th-TH';

    /**
     * A locale that spells all three day-period widths differently: at 15:00 French
     * renders 'ap.m.', 'après-midi' and 'de l'après-midi'. English collapses two of
     * the three at every hour of the day.
     */
    private const string DAY_PERIOD_LOCALE = 'fr-FR';

    private static function date(): PlainDate
    {
        return new PlainDate(2020, 6, 15);
    }

    private static function dateTime(): PlainDateTime
    {
        return new PlainDateTime(2020, 6, 15, 21, 30, 45, 123);
    }

    private static function time(): PlainTime
    {
        return new PlainTime(21, 30, 45, 123);
    }

    private static function yearMonth(): PlainYearMonth
    {
        return PlainYearMonth::fromFields(year: 2020, month: 6, calendar: Calendar::Gregory);
    }

    private static function monthDay(): PlainMonthDay
    {
        return PlainMonthDay::fromFields(monthCode: 'M06', day: 15, calendar: Calendar::Gregory);
    }

    private static function instant(): Instant
    {
        return Instant::parse('2020-06-15T13:30:45Z');
    }

    private static function zoned(): ZonedDateTime
    {
        return ZonedDateTime::parse('2020-06-15T21:30:45-04:00[America/New_York]');
    }

    // -------------------------------------------------------------------------
    // Every exposed option changes the rendered output
    // -------------------------------------------------------------------------

    /** @return iterable<string, array{\Closure(): string, \Closure(): string}> */
    public static function optionCases(): iterable
    {
        $d = static fn(): PlainDate => self::date();
        $baseDate = static fn(): string => self::date()->toLocaleString(self::LOCALE);

        yield 'PlainDate dateStyle' => [
            $baseDate,
            static fn(): string => $d()->toLocaleString(self::LOCALE, dateStyle: FormatStyle::Full),
        ];
        yield 'PlainDate weekday' => [
            $baseDate,
            static fn(): string => $d()->toLocaleString(self::LOCALE, weekday: TextWidth::Long),
        ];
        yield 'PlainDate era' => [
            $baseDate,
            static fn(): string => $d()->toLocaleString(self::LOCALE, era: TextWidth::Long),
        ];
        yield 'PlainDate year' => [
            $baseDate,
            static fn(): string => $d()->toLocaleString(self::LOCALE, year: NumberWidth::TwoDigit),
        ];
        yield 'PlainDate month' => [
            $baseDate,
            static fn(): string => $d()->toLocaleString(self::LOCALE, month: MonthWidth::Long),
        ];
        yield 'PlainDate day' => [
            $baseDate,
            static fn(): string => $d()->toLocaleString(self::LOCALE, day: NumberWidth::TwoDigit),
        ];
        yield 'PlainDate calendar' => [
            $baseDate,
            static fn(): string => $d()->toLocaleString(self::LOCALE, calendar: Calendar::Hebrew),
        ];

        $dt = static fn(): PlainDateTime => self::dateTime();
        $baseDateTime = static fn(): string => self::dateTime()->toLocaleString(self::LOCALE);

        yield 'PlainDateTime dateStyle' => [
            $baseDateTime,
            static fn(): string => $dt()->toLocaleString(self::LOCALE, dateStyle: FormatStyle::Full),
        ];
        yield 'PlainDateTime timeStyle' => [
            $baseDateTime,
            static fn(): string => $dt()->toLocaleString(self::LOCALE, timeStyle: FormatStyle::Full),
        ];
        yield 'PlainDateTime weekday' => [
            $baseDateTime,
            static fn(): string => $dt()->toLocaleString(self::LOCALE, weekday: TextWidth::Long),
        ];
        yield 'PlainDateTime era' => [
            $baseDateTime,
            static fn(): string => $dt()->toLocaleString(self::LOCALE, era: TextWidth::Long),
        ];
        yield 'PlainDateTime year' => [
            $baseDateTime,
            static fn(): string => $dt()->toLocaleString(self::LOCALE, year: NumberWidth::TwoDigit),
        ];
        yield 'PlainDateTime month' => [
            $baseDateTime,
            static fn(): string => $dt()->toLocaleString(self::LOCALE, month: MonthWidth::Long),
        ];
        yield 'PlainDateTime day' => [
            $baseDateTime,
            static fn(): string => $dt()->toLocaleString(self::LOCALE, day: NumberWidth::TwoDigit),
        ];
        yield 'PlainDateTime dayPeriod' => [
            $baseDateTime,
            static fn(): string => $dt()->toLocaleString(self::LOCALE, dayPeriod: TextWidth::Long),
        ];
        yield 'PlainDateTime hour' => [
            $baseDateTime,
            static fn(): string => $dt()->toLocaleString(self::LOCALE, hour: NumberWidth::TwoDigit),
        ];
        yield 'PlainDateTime minute' => [
            $baseDateTime,
            static fn(): string => $dt()->toLocaleString(self::LOCALE, minute: NumberWidth::TwoDigit),
        ];
        yield 'PlainDateTime second' => [
            $baseDateTime,
            static fn(): string => $dt()->toLocaleString(self::LOCALE, second: NumberWidth::TwoDigit),
        ];
        yield 'PlainDateTime fractionalSecondDigits' => [
            $baseDateTime,
            static fn(): string => $dt()->toLocaleString(self::LOCALE, fractionalSecondDigits: 3),
        ];
        yield 'PlainDateTime hourCycle' => [
            $baseDateTime,
            static fn(): string => $dt()->toLocaleString(self::LOCALE, hourCycle: HourCycle::H23),
        ];
        yield 'PlainDateTime calendar' => [
            $baseDateTime,
            static fn(): string => $dt()->toLocaleString(self::LOCALE, calendar: Calendar::Hebrew),
        ];

        $t = static fn(): PlainTime => self::time();
        $baseTime = static fn(): string => self::time()->toLocaleString(self::LOCALE);

        yield 'PlainTime timeStyle' => [
            $baseTime,
            static fn(): string => $t()->toLocaleString(self::LOCALE, timeStyle: FormatStyle::Full),
        ];
        yield 'PlainTime dayPeriod' => [
            $baseTime,
            static fn(): string => $t()->toLocaleString(self::LOCALE, dayPeriod: TextWidth::Long),
        ];
        yield 'PlainTime hour' => [
            $baseTime,
            static fn(): string => $t()->toLocaleString(self::LOCALE, hour: NumberWidth::TwoDigit),
        ];
        yield 'PlainTime minute' => [
            $baseTime,
            static fn(): string => $t()->toLocaleString(self::LOCALE, minute: NumberWidth::TwoDigit),
        ];
        yield 'PlainTime second' => [
            $baseTime,
            static fn(): string => $t()->toLocaleString(self::LOCALE, second: NumberWidth::TwoDigit),
        ];
        yield 'PlainTime fractionalSecondDigits' => [
            $baseTime,
            static fn(): string => $t()->toLocaleString(self::LOCALE, fractionalSecondDigits: 3),
        ];
        yield 'PlainTime hourCycle' => [
            $baseTime,
            static fn(): string => $t()->toLocaleString(self::LOCALE, hourCycle: HourCycle::H23),
        ];

        $ym = static fn(): PlainYearMonth => self::yearMonth();
        $baseYearMonth = static fn(): string => self::yearMonth()->toLocaleString(self::LOCALE);

        yield 'PlainYearMonth dateStyle' => [
            $baseYearMonth,
            static fn(): string => $ym()->toLocaleString(self::LOCALE, dateStyle: FormatStyle::Full),
        ];
        yield 'PlainYearMonth era' => [
            $baseYearMonth,
            static fn(): string => $ym()->toLocaleString(self::LOCALE, era: TextWidth::Long),
        ];
        yield 'PlainYearMonth year' => [
            $baseYearMonth,
            static fn(): string => $ym()->toLocaleString(self::LOCALE, year: NumberWidth::TwoDigit),
        ];
        yield 'PlainYearMonth month' => [
            $baseYearMonth,
            static fn(): string => $ym()->toLocaleString(self::LOCALE, month: MonthWidth::Long),
        ];

        $md = static fn(): PlainMonthDay => self::monthDay();
        $baseMonthDay = static fn(): string => self::monthDay()->toLocaleString(self::LOCALE);

        yield 'PlainMonthDay dateStyle' => [
            $baseMonthDay,
            static fn(): string => $md()->toLocaleString(self::LOCALE, dateStyle: FormatStyle::Full),
        ];
        yield 'PlainMonthDay month' => [
            $baseMonthDay,
            static fn(): string => $md()->toLocaleString(self::LOCALE, month: MonthWidth::Long),
        ];
        yield 'PlainMonthDay day' => [
            $baseMonthDay,
            static fn(): string => $md()->toLocaleString(self::LOCALE, day: NumberWidth::TwoDigit),
        ];

        $i = static fn(): Instant => self::instant();
        $baseInstant = static fn(): string => self::instant()->toLocaleString(self::LOCALE);

        yield 'Instant timeZone' => [
            $baseInstant,
            static fn(): string => $i()->toLocaleString(self::LOCALE, timeZone: 'America/New_York'),
        ];
        yield 'Instant dateStyle' => [
            $baseInstant,
            static fn(): string => $i()->toLocaleString(self::LOCALE, dateStyle: FormatStyle::Full),
        ];
        yield 'Instant timeStyle' => [
            $baseInstant,
            static fn(): string => $i()->toLocaleString(self::LOCALE, timeStyle: FormatStyle::Full),
        ];
        yield 'Instant weekday' => [
            $baseInstant,
            static fn(): string => $i()->toLocaleString(self::LOCALE, weekday: TextWidth::Long),
        ];
        yield 'Instant era' => [
            $baseInstant,
            static fn(): string => $i()->toLocaleString(self::LOCALE, era: TextWidth::Long),
        ];
        yield 'Instant year' => [
            $baseInstant,
            static fn(): string => $i()->toLocaleString(self::LOCALE, year: NumberWidth::TwoDigit),
        ];
        yield 'Instant month' => [
            $baseInstant,
            static fn(): string => $i()->toLocaleString(self::LOCALE, month: MonthWidth::Long),
        ];
        yield 'Instant day' => [
            $baseInstant,
            static fn(): string => $i()->toLocaleString(self::LOCALE, day: NumberWidth::TwoDigit),
        ];
        yield 'Instant dayPeriod' => [
            $baseInstant,
            static fn(): string => $i()->toLocaleString(self::LOCALE, dayPeriod: TextWidth::Long),
        ];
        yield 'Instant hour' => [
            $baseInstant,
            static fn(): string => $i()->toLocaleString(self::LOCALE, hour: NumberWidth::TwoDigit),
        ];
        yield 'Instant minute' => [
            $baseInstant,
            static fn(): string => $i()->toLocaleString(self::LOCALE, minute: NumberWidth::TwoDigit),
        ];
        yield 'Instant second' => [
            $baseInstant,
            static fn(): string => $i()->toLocaleString(self::LOCALE, second: NumberWidth::TwoDigit),
        ];
        yield 'Instant fractionalSecondDigits' => [
            $baseInstant,
            static fn(): string => $i()->toLocaleString(self::LOCALE, fractionalSecondDigits: 3),
        ];
        yield 'Instant timeZoneName' => [
            $baseInstant,
            static fn(): string => $i()->toLocaleString(self::LOCALE, timeZoneName: TimeZoneNameStyle::Long),
        ];
        yield 'Instant hourCycle' => [
            $baseInstant,
            static fn(): string => $i()->toLocaleString(self::LOCALE, hourCycle: HourCycle::H23),
        ];
        yield 'Instant calendar' => [
            $baseInstant,
            static fn(): string => $i()->toLocaleString(self::LOCALE, calendar: Calendar::Hebrew),
        ];

        $z = static fn(): ZonedDateTime => self::zoned();
        $baseZoned = static fn(): string => self::zoned()->toLocaleString(self::LOCALE);

        yield 'ZonedDateTime dateStyle' => [
            $baseZoned,
            static fn(): string => $z()->toLocaleString(self::LOCALE, dateStyle: FormatStyle::Full),
        ];
        yield 'ZonedDateTime timeStyle' => [
            $baseZoned,
            static fn(): string => $z()->toLocaleString(self::LOCALE, timeStyle: FormatStyle::Full),
        ];
        yield 'ZonedDateTime weekday' => [
            $baseZoned,
            static fn(): string => $z()->toLocaleString(self::LOCALE, weekday: TextWidth::Long),
        ];
        yield 'ZonedDateTime era' => [
            $baseZoned,
            static fn(): string => $z()->toLocaleString(self::LOCALE, era: TextWidth::Long),
        ];
        yield 'ZonedDateTime year' => [
            $baseZoned,
            static fn(): string => $z()->toLocaleString(self::LOCALE, year: NumberWidth::TwoDigit),
        ];
        yield 'ZonedDateTime month' => [
            $baseZoned,
            static fn(): string => $z()->toLocaleString(self::LOCALE, month: MonthWidth::Long),
        ];
        yield 'ZonedDateTime day' => [
            $baseZoned,
            static fn(): string => $z()->toLocaleString(self::LOCALE, day: NumberWidth::TwoDigit),
        ];
        yield 'ZonedDateTime dayPeriod' => [
            $baseZoned,
            static fn(): string => $z()->toLocaleString(self::LOCALE, dayPeriod: TextWidth::Long),
        ];
        yield 'ZonedDateTime hour' => [
            $baseZoned,
            static fn(): string => $z()->toLocaleString(self::LOCALE, hour: NumberWidth::TwoDigit),
        ];
        yield 'ZonedDateTime minute' => [
            $baseZoned,
            static fn(): string => $z()->toLocaleString(self::LOCALE, minute: NumberWidth::TwoDigit),
        ];
        yield 'ZonedDateTime second' => [
            $baseZoned,
            static fn(): string => $z()->toLocaleString(self::LOCALE, second: NumberWidth::TwoDigit),
        ];
        yield 'ZonedDateTime fractionalSecondDigits' => [
            $baseZoned,
            static fn(): string => $z()->toLocaleString(self::LOCALE, fractionalSecondDigits: 3),
        ];
        yield 'ZonedDateTime timeZoneName' => [
            $baseZoned,
            static fn(): string => $z()->toLocaleString(self::LOCALE, timeZoneName: TimeZoneNameStyle::Long),
        ];
        yield 'ZonedDateTime hourCycle' => [
            $baseZoned,
            static fn(): string => $z()->toLocaleString(self::LOCALE, hourCycle: HourCycle::H23),
        ];
        yield 'ZonedDateTime calendar' => [
            $baseZoned,
            static fn(): string => $z()->toLocaleString(self::LOCALE, calendar: Calendar::Hebrew),
        ];
    }

    /**
     * @param \Closure(): string $baseline
     * @param \Closure(): string $withOption
     */
    #[DataProvider('optionCases')]
    public function testOptionChangesOutput(\Closure $baseline, \Closure $withOption): void
    {
        static::assertNotSame($baseline(), $withOption());
    }

    // -------------------------------------------------------------------------
    // The `calendar` option on year-month / month-day
    // -------------------------------------------------------------------------

    /**
     * A Gregorian year-month cannot be rendered by a Buddhist-defaulting locale,
     * but naming the calendar explicitly overrides what the locale resolves to.
     */
    public function testYearMonthCalendarOptionOverridesTheLocaleCalendar(): void
    {
        static::assertNotSame('', self::yearMonth()
            ->toLocaleString(self::BUDDHIST_LOCALE, calendar: Calendar::Gregory));

        $this->expectException(RangeError::class);
        self::yearMonth()->toLocaleString(self::BUDDHIST_LOCALE);
    }

    public function testMonthDayCalendarOptionOverridesTheLocaleCalendar(): void
    {
        static::assertNotSame('', self::monthDay()->toLocaleString(self::BUDDHIST_LOCALE, calendar: Calendar::Gregory));

        $this->expectException(RangeError::class);
        self::monthDay()->toLocaleString(self::BUDDHIST_LOCALE);
    }

    /** An ISO year-month has no calendar a locale can ever resolve to. */
    public function testIsoYearMonthCannotBeLocalized(): void
    {
        $this->expectException(RangeError::class);
        new PlainYearMonth(2020, 6)->toLocaleString(self::LOCALE);
    }

    public function testIsoMonthDayCannotBeLocalized(): void
    {
        $this->expectException(RangeError::class);
        new PlainMonthDay(6, 15)->toLocaleString(self::LOCALE);
    }

    // -------------------------------------------------------------------------
    // Every value of every option renders as itself
    // -------------------------------------------------------------------------

    /**
     * The one-value-per-option cases above prove an option is read at all. These
     * prove every one of its values is read as itself — the distinction that
     * separates a real value-to-skeleton mapping from a single skeleton every
     * value happens to collapse onto.
     *
     * Each case renders the same temporal value once per enum case and expects as
     * many distinct strings as the enum has cases, so nothing pins CLDR wording.
     *
     * @return iterable<string, array{\Closure(): list<string>}>
     */
    public static function everyOptionValueCases(): iterable
    {
        // `hour`, `minute` and `second` have no case here: ICU's pattern generator
        // normalizes the hour field to the locale's own width, so 'numeric' and
        // '2-digit' render the same string. ECMA-402 pads the '2-digit' hour ('09 AM'
        // against '9 AM'), a deviation this suite cannot assert until the generated
        // pattern is widened afterwards.
        yield 'PlainDate dateStyle' => [
            static fn(): array => array_map(static fn(FormatStyle $style): string => self::date()
                ->toLocaleString(self::LOCALE, dateStyle: $style), FormatStyle::cases()),
        ];
        yield 'PlainDate weekday' => [
            static fn(): array => array_map(static fn(TextWidth $width): string => self::date()
                ->toLocaleString(self::LOCALE, weekday: $width), TextWidth::cases()),
        ];
        yield 'PlainDate era' => [
            static fn(): array => array_map(static fn(TextWidth $width): string => self::date()
                ->toLocaleString(self::LOCALE, era: $width), TextWidth::cases()),
        ];
        yield 'PlainDate year' => [
            static fn(): array => array_map(static fn(NumberWidth $width): string => self::date()
                ->toLocaleString(self::LOCALE, year: $width), NumberWidth::cases()),
        ];
        yield 'PlainDate month' => [
            static fn(): array => array_map(static fn(MonthWidth $width): string => self::date()
                ->toLocaleString(self::LOCALE, month: $width), MonthWidth::cases()),
        ];
        // The 5th, not the 15th: a two-digit day of the month renders the same either
        // way, so the 15th would tell us nothing about the option.
        yield 'PlainDate day' => [
            static fn(): array => array_map(static fn(NumberWidth $width): string => new PlainDate(
                2020,
                6,
                5,
            )->toLocaleString(self::LOCALE, day: $width), NumberWidth::cases()),
        ];
        yield 'PlainTime dayPeriod' => [
            static fn(): array => array_map(static fn(TextWidth $width): string => new PlainTime(15, 0)->toLocaleString(
                self::DAY_PERIOD_LOCALE,
                dayPeriod: $width,
            ), TextWidth::cases()),
        ];
        yield 'ZonedDateTime timeZoneName' => [
            static fn(): array => array_map(static fn(TimeZoneNameStyle $style): string => self::zoned()
                ->toLocaleString(self::LOCALE, timeZoneName: $style), TimeZoneNameStyle::cases()),
        ];
    }

    /** @param \Closure(): list<string> $renderEveryValue One rendering per value of a single option. */
    #[DataProvider('everyOptionValueCases')]
    public function testEveryOptionValueRendersDistinctly(\Closure $renderEveryValue): void
    {
        self::assertAllDistinct($renderEveryValue());
    }

    // -------------------------------------------------------------------------
    // hourCycle across the locale dialects ICU accepts
    // -------------------------------------------------------------------------

    /** @return iterable<string, array{string, Calendar|null}> */
    public static function hourCycleLocaleCases(): iterable
    {
        yield 'bare locale' => [self::LOCALE, null];
        yield 'locale already carrying a -u- extension' => ['en-US-u-nu-latn', null];
        yield 'calendar option, which ICU spells as an @keyword' => [self::LOCALE, Calendar::Hebrew];
    }

    /**
     * Midnight is the hour the four cycles disagree about most: h11 renders it 0,
     * h12 renders it 12, h23 renders it 00 and h24 renders it 24. Asking for all
     * four and requiring four different answers is therefore enough to prove the
     * cycle reached ICU — however the locale had to be spelled to carry it.
     */
    #[DataProvider('hourCycleLocaleCases')]
    public function testHourCycleReachesIcuWhateverTheLocaleDialect(string $locale, ?Calendar $calendar): void
    {
        $midnight = new PlainDateTime(2020, 6, 15, 0, 30);

        $rendered = array_map(static fn(HourCycle $hourCycle): string => $midnight->toLocaleString(
            $locale,
            hourCycle: $hourCycle,
            calendar: $calendar,
        ), HourCycle::cases());

        self::assertAllDistinct($rendered);
    }

    /** @param list<string> $rendered */
    private static function assertAllDistinct(array $rendered): void
    {
        static::assertSame($rendered, array_values(array_unique($rendered)));
    }

    // -------------------------------------------------------------------------
    // Locale resolution
    // -------------------------------------------------------------------------

    public function testLocaleSelectsTheRenderedLanguage(): void
    {
        static::assertNotSame(
            self::date()->toLocaleString(self::LOCALE, dateStyle: FormatStyle::Full),
            self::date()->toLocaleString('de-AT', dateStyle: FormatStyle::Full),
        );
    }

    public function testOmittedLocaleFallsBackToTheIcuDefault(): void
    {
        static::assertSame(self::date()->toLocaleString(\Locale::getDefault()), self::date()->toLocaleString());
    }

    public function testEnUsRendersTheStableNumericShortDate(): void
    {
        static::assertSame('6/15/2020', self::date()->toLocaleString(self::LOCALE));
    }

    // -------------------------------------------------------------------------
    // Style / component conflicts
    // -------------------------------------------------------------------------

    /** @return iterable<string, array{\Closure(): string}> */
    public static function conflictingOptionCases(): iterable
    {
        yield 'PlainDate' => [
            static fn(): string => self::date()
                ->toLocaleString(self::LOCALE, dateStyle: FormatStyle::Full, year: NumberWidth::Numeric),
        ];
        yield 'PlainDateTime' => [
            static fn(): string => self::dateTime()
                ->toLocaleString(self::LOCALE, timeStyle: FormatStyle::Full, hour: NumberWidth::Numeric),
        ];
        yield 'PlainTime' => [
            static fn(): string => self::time()
                ->toLocaleString(self::LOCALE, timeStyle: FormatStyle::Full, minute: NumberWidth::Numeric),
        ];
        yield 'PlainYearMonth' => [
            static fn(): string => self::yearMonth()
                ->toLocaleString(self::LOCALE, dateStyle: FormatStyle::Full, month: MonthWidth::Long),
        ];
        yield 'PlainMonthDay' => [
            static fn(): string => self::monthDay()
                ->toLocaleString(self::LOCALE, dateStyle: FormatStyle::Full, day: NumberWidth::Numeric),
        ];
        yield 'Instant' => [
            static fn(): string => self::instant()
                ->toLocaleString(self::LOCALE, dateStyle: FormatStyle::Full, weekday: TextWidth::Long),
        ];
        yield 'ZonedDateTime' => [
            static fn(): string => self::zoned()
                ->toLocaleString(self::LOCALE, dateStyle: FormatStyle::Full, timeZoneName: TimeZoneNameStyle::Long),
        ];
    }

    /** @param \Closure(): string $format */
    #[DataProvider('conflictingOptionCases')]
    public function testStyleAndComponentOptionsCannotBeMixed(\Closure $format): void
    {
        $this->expectException(TypeError::class);
        $format();
    }

    // -------------------------------------------------------------------------
    // Calendar compatibility on the projectable types
    // -------------------------------------------------------------------------

    public function testIsoDateProjectsIntoTheLocaleCalendar(): void
    {
        // th-TH resolves to the Buddhist calendar: 2020 CE is 2563 BE.
        static::assertStringContainsString('2563', self::date()->toLocaleString(self::BUDDHIST_LOCALE));
    }

    public function testNonIsoDateRejectsAMismatchedFormatter(): void
    {
        $hebrew = self::date()->withCalendar(Calendar::Hebrew);

        $this->expectException(RangeError::class);
        $hebrew->toLocaleString(self::LOCALE);
    }
}
