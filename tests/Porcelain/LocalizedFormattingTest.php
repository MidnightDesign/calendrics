<?php

declare(strict_types=1);

namespace Temporal\Tests\Porcelain;

use PHPUnit\Framework\Attributes\DataProvider;
use Temporal\Calendar;
use Temporal\DateStyle;
use Temporal\Instant;
use Temporal\PlainDate;
use Temporal\PlainDateTime;
use Temporal\PlainMonthDay;
use Temporal\PlainTime;
use Temporal\PlainYearMonth;
use Temporal\TimeStyle;
use Temporal\ZonedDateTime;

/**
 * Covers `toLocaleString()` across the porcelain value types.
 *
 * ICU inserts a narrow no-break space before the day period in some locales
 * (U+202F since ICU 72, a plain space before it), so every comparison runs
 * through {@see self::assertLocalizedSame()}, which folds the Unicode space
 * separators together. Assertions are otherwise limited to output that CLDR has
 * kept stable for the major locales; calendar-specific rendering is checked by
 * substring so a CLDR reword cannot break the suite.
 */
final class LocalizedFormattingTest extends TemporalTestCase
{
    // -------------------------------------------------------------------------
    // Locale resolution
    // -------------------------------------------------------------------------

    public function testNullLocaleUsesTheIcuDefaultLocale(): void
    {
        $date = new PlainDate(2024, 3, 15);

        static::assertSame($date->toLocaleString(\Locale::getDefault()), $date->toLocaleString());
    }

    public function testEmptyLocaleFallsBackToTheIcuDefaultLocale(): void
    {
        $date = new PlainDate(2024, 3, 15);

        static::assertSame($date->toLocaleString(), $date->toLocaleString(''));
    }

    public function testLocaleIsHonouredPerCall(): void
    {
        $date = new PlainDate(2024, 3, 15);

        static::assertNotSame(
            $date->toLocaleString('en-US', DateStyle::Long),
            $date->toLocaleString('de-DE', DateStyle::Long),
        );
    }

    // -------------------------------------------------------------------------
    // PlainDate
    // -------------------------------------------------------------------------

    public function testPlainDateDefaultsToTheLocalesNumericFormat(): void
    {
        $date = new PlainDate(2024, 3, 15);

        static::assertLocalizedSame('3/15/2024', $date->toLocaleString('en-US'));
        static::assertLocalizedSame('15.3.2024', $date->toLocaleString('de-DE'));
    }

    /** @return list<array{DateStyle, string, string}> */
    public static function plainDateStyleProvider(): array
    {
        return [
            [DateStyle::Full,   'Friday, March 15, 2024', 'Freitag, 15. März 2024'],
            [DateStyle::Long,   'March 15, 2024',         '15. März 2024'],
            [DateStyle::Medium, 'Mar 15, 2024',           '15.03.2024'],
            [DateStyle::Short,  '3/15/24',                '15.03.24'],
        ];
    }

    #[DataProvider('plainDateStyleProvider')]
    public function testPlainDateStyles(DateStyle $style, string $enUs, string $deDe): void
    {
        $date = new PlainDate(2024, 3, 15);

        static::assertLocalizedSame($enUs, $date->toLocaleString('en-US', $style));
        static::assertLocalizedSame($deDe, $date->toLocaleString('de-DE', $style));
    }

    public function testPlainDateInACjkLocale(): void
    {
        $date = new PlainDate(2024, 3, 15);

        static::assertLocalizedSame('2024年3月15日金曜日', $date->toLocaleString('ja-JP', DateStyle::Full));
        static::assertLocalizedSame('2024年3月15日', $date->toLocaleString('ja-JP', DateStyle::Long));
    }

    public function testPlainDateUsesItsOwnCalendarWithoutBeingAsked(): void
    {
        $hebrew = new PlainDate(2024, 3, 15, Calendar::Hebrew);

        $formatted = $hebrew->toLocaleString('en-US', DateStyle::Long);

        static::assertStringContainsString('5784', $formatted, 'the Hebrew year should be shown');
        static::assertStringContainsString('Adar', $formatted, 'the Hebrew month name should be shown');
        static::assertStringNotContainsString('2024', $formatted, 'the ISO year should not leak through');
    }

    public function testPlainDateRendersTheJapaneseEra(): void
    {
        $japanese = new PlainDate(2024, 3, 15, Calendar::Japanese);

        $formatted = $japanese->toLocaleString('en-US', DateStyle::Long);

        static::assertStringContainsString('Reiwa', $formatted);
        static::assertStringContainsString('6', $formatted, 'Reiwa 6 is the era year for 2024');
    }

    public function testPlainDateRendersTheBuddhistEra(): void
    {
        $buddhist = new PlainDate(2024, 3, 15, Calendar::Buddhist);

        static::assertStringContainsString('2567', $buddhist->toLocaleString('en-US', DateStyle::Long));
    }

    public function testIsoCalendarIsFormattedAsGregorian(): void
    {
        $iso = new PlainDate(2024, 3, 15);
        $gregory = new PlainDate(2024, 3, 15, Calendar::Gregory);

        static::assertSame(
            $gregory->toLocaleString('en-US', DateStyle::Full),
            $iso->toLocaleString('en-US', DateStyle::Full),
        );
    }

    // -------------------------------------------------------------------------
    // PlainTime
    // -------------------------------------------------------------------------

    public function testPlainTimeDefaultsToHourMinuteSecond(): void
    {
        $time = new PlainTime(9, 30, 45);

        static::assertLocalizedSame('9:30:45 AM', $time->toLocaleString('en-US'));
        static::assertLocalizedSame('09:30:45', $time->toLocaleString('de-DE'));
    }

    /** @return list<array{TimeStyle, string, string}> */
    public static function plainTimeStyleProvider(): array
    {
        return [
            [TimeStyle::Full,   '9:30:45 AM Coordinated Universal Time', '09:30:45 Koordinierte Weltzeit'],
            [TimeStyle::Long,   '9:30:45 AM UTC',                        '09:30:45 UTC'],
            [TimeStyle::Medium, '9:30:45 AM',                            '09:30:45'],
            [TimeStyle::Short,  '9:30 AM',                               '09:30'],
        ];
    }

    #[DataProvider('plainTimeStyleProvider')]
    public function testPlainTimeStyles(TimeStyle $style, string $enUs, string $deDe): void
    {
        $time = new PlainTime(9, 30, 45);

        static::assertLocalizedSame($enUs, $time->toLocaleString('en-US', $style));
        static::assertLocalizedSame($deDe, $time->toLocaleString('de-DE', $style));
    }

    public function testPlainTimeUsesTheLocalesHourCycle(): void
    {
        $afternoon = new PlainTime(15, 5);

        static::assertLocalizedSame('3:05 PM', $afternoon->toLocaleString('en-US', TimeStyle::Short));
        static::assertLocalizedSame('15:05', $afternoon->toLocaleString('de-DE', TimeStyle::Short));
    }

    // -------------------------------------------------------------------------
    // PlainDateTime
    // -------------------------------------------------------------------------

    public function testPlainDateTimeDefaultsToBothParts(): void
    {
        $dt = new PlainDateTime(2024, 3, 15, 9, 30, 45);

        static::assertLocalizedSame('3/15/2024, 9:30:45 AM', $dt->toLocaleString('en-US'));
    }

    public function testPlainDateTimeWithBothStyles(): void
    {
        $dt = new PlainDateTime(2024, 3, 15, 9, 30, 45);

        $formatted = $dt->toLocaleString('en-US', DateStyle::Long, TimeStyle::Medium);

        static::assertLocalizedSame('March 15, 2024 at 9:30:45 AM', $formatted);
    }

    public function testPlainDateTimeWithOnlyADateStyleDropsTheTime(): void
    {
        $dt = new PlainDateTime(2024, 3, 15, 9, 30, 45);

        static::assertLocalizedSame('March 15, 2024', $dt->toLocaleString('en-US', DateStyle::Long));
    }

    public function testPlainDateTimeWithOnlyATimeStyleDropsTheDate(): void
    {
        $dt = new PlainDateTime(2024, 3, 15, 9, 30, 45);

        static::assertLocalizedSame('9:30 AM', $dt->toLocaleString('en-US', timeStyle: TimeStyle::Short));
    }

    public function testPlainDateTimeUsesItsOwnCalendar(): void
    {
        $dt = new PlainDateTime(2024, 3, 15, 9, 30, 45)->withCalendar(Calendar::Hebrew);

        $formatted = $dt->toLocaleString('en-US', DateStyle::Long, TimeStyle::Short);

        static::assertStringContainsString('5784', $formatted);
        static::assertLocalizedStringContainsString('9:30 AM', $formatted);
    }

    // -------------------------------------------------------------------------
    // ZonedDateTime
    // -------------------------------------------------------------------------

    public function testZonedDateTimeLabelsTheZoneByDefault(): void
    {
        $zdt = ZonedDateTime::parse('2024-03-15T09:30:45+01:00[Europe/Berlin]');

        static::assertLocalizedSame('3/15/2024, 9:30:45 AM GMT+1', $zdt->toLocaleString('en-US'));
    }

    public function testZonedDateTimeWithBothStyles(): void
    {
        $zdt = ZonedDateTime::parse('2024-03-15T09:30:45+01:00[Europe/Berlin]');

        $formatted = $zdt->toLocaleString('en-US', DateStyle::Full, TimeStyle::Full);

        static::assertLocalizedStringContainsString('Friday, March 15, 2024', $formatted);
        static::assertLocalizedStringContainsString('9:30:45 AM', $formatted);
        static::assertStringContainsString('Central European', $formatted, 'a full time style spells the zone out');
    }

    public function testZonedDateTimeRendersInItsOwnZone(): void
    {
        $berlin = ZonedDateTime::parse('2024-03-15T09:30:45+01:00[Europe/Berlin]');
        $tokyo = $berlin->withTimeZone('Asia/Tokyo');

        $inBerlin = $berlin->toLocaleString('en-US', DateStyle::Long, TimeStyle::Short);
        $inTokyo = $tokyo->toLocaleString('en-US', DateStyle::Long, TimeStyle::Short);

        static::assertLocalizedSame('March 15, 2024 at 9:30 AM', $inBerlin);
        static::assertLocalizedSame('March 15, 2024 at 5:30 PM', $inTokyo);
    }

    public function testZonedDateTimeUsesItsOwnCalendar(): void
    {
        $zdt = ZonedDateTime::parse('2024-03-15T09:30:45+01:00[Europe/Berlin]')->withCalendar(Calendar::Buddhist);

        static::assertStringContainsString('2567', $zdt->toLocaleString('en-US', DateStyle::Long, TimeStyle::Short));
    }

    // -------------------------------------------------------------------------
    // Instant
    // -------------------------------------------------------------------------

    public function testInstantDefaultsToUtc(): void
    {
        $instant = Instant::parse('2024-03-15T09:30:45Z');

        $implicitZone = $instant->toLocaleString('en-US', DateStyle::Long, TimeStyle::Short);
        $explicitUtc = $instant->toLocaleString('en-US', DateStyle::Long, TimeStyle::Short, 'UTC');

        static::assertLocalizedSame('March 15, 2024 at 9:30 AM', $implicitZone);
        static::assertSame($explicitUtc, $implicitZone);
    }

    public function testInstantRendersInTheRequestedZone(): void
    {
        $instant = Instant::parse('2024-03-15T09:30:45Z');

        $formatted = $instant->toLocaleString('en-US', DateStyle::Long, TimeStyle::Short, 'Asia/Tokyo');

        static::assertLocalizedSame('March 15, 2024 at 6:30 PM', $formatted);
    }

    public function testInstantAcceptsAFixedOffsetZone(): void
    {
        $instant = Instant::parse('2024-03-15T09:30:45Z');

        $formatted = $instant->toLocaleString('en-US', DateStyle::Long, TimeStyle::Short, '-05:00');

        static::assertLocalizedSame('March 15, 2024 at 4:30 AM', $formatted);
    }

    public function testInstantDefaultsToBothParts(): void
    {
        $instant = Instant::parse('2024-03-15T09:30:45Z');

        static::assertLocalizedSame('3/15/2024, 9:30:45 AM', $instant->toLocaleString('en-US'));
    }

    // -------------------------------------------------------------------------
    // PlainYearMonth
    // -------------------------------------------------------------------------

    public function testPlainYearMonthDefaultsToTheLocalesNumericFormat(): void
    {
        $ym = new PlainYearMonth(2024, 3);

        static::assertLocalizedSame('3/2024', $ym->toLocaleString('en-US'));
    }

    /** @return list<array{DateStyle, string, string}> */
    public static function plainYearMonthStyleProvider(): array
    {
        return [
            [DateStyle::Full,   'March 2024', 'März 2024'],
            [DateStyle::Long,   'March 2024', 'März 2024'],
            [DateStyle::Medium, 'Mar 2024',   'März 2024'],
            [DateStyle::Short,  '3/24',       '3/24'],
        ];
    }

    #[DataProvider('plainYearMonthStyleProvider')]
    public function testPlainYearMonthStyles(DateStyle $style, string $enUs, string $deDe): void
    {
        $ym = new PlainYearMonth(2024, 3);

        static::assertLocalizedSame($enUs, $ym->toLocaleString('en-US', $style));
        static::assertLocalizedSame($deDe, $ym->toLocaleString('de-DE', $style));
    }

    public function testPlainYearMonthNeverShowsADayOrWeekday(): void
    {
        $ym = new PlainYearMonth(2024, 3);

        foreach (DateStyle::cases() as $style) {
            $formatted = $ym->toLocaleString('en-US', $style);

            static::assertStringNotContainsString('Friday', $formatted, "{$style->value} leaked a weekday");
            static::assertDoesNotMatchRegularExpression('/\b1\b/', $formatted, "{$style->value} leaked a day of month");
        }
    }

    public function testPlainYearMonthUsesItsOwnCalendar(): void
    {
        $ym = new PlainDate(2024, 3, 15, Calendar::Hebrew)->toPlainYearMonth();

        static::assertStringContainsString('5784', $ym->toLocaleString('en-US', DateStyle::Long));
    }

    // -------------------------------------------------------------------------
    // PlainMonthDay
    // -------------------------------------------------------------------------

    public function testPlainMonthDayDefaultsToTheLocalesNumericFormat(): void
    {
        $md = new PlainMonthDay(12, 25);

        static::assertLocalizedSame('12/25', $md->toLocaleString('en-US'));
    }

    /** @return list<array{DateStyle, string, string}> */
    public static function plainMonthDayStyleProvider(): array
    {
        return [
            [DateStyle::Full,  'December 25', '25. Dezember'],
            [DateStyle::Long,  'December 25', '25. Dezember'],
            [DateStyle::Short, '12/25',       '25.12.'],
        ];
    }

    #[DataProvider('plainMonthDayStyleProvider')]
    public function testPlainMonthDayStyles(DateStyle $style, string $enUs, string $deDe): void
    {
        $md = new PlainMonthDay(12, 25);

        static::assertLocalizedSame($enUs, $md->toLocaleString('en-US', $style));
        static::assertLocalizedSame($deDe, $md->toLocaleString('de-DE', $style));
    }

    public function testPlainMonthDayMediumAbbreviatesTheMonth(): void
    {
        $md = new PlainMonthDay(12, 25);

        static::assertLocalizedSame('Dec 25', $md->toLocaleString('en-US', DateStyle::Medium));
    }

    public function testPlainMonthDayInACjkLocale(): void
    {
        $md = new PlainMonthDay(12, 25);

        static::assertLocalizedSame('12月25日', $md->toLocaleString('ja-JP', DateStyle::Long));
    }

    public function testPlainMonthDayNeverShowsAYearOrWeekday(): void
    {
        $md = new PlainMonthDay(12, 25);

        foreach (DateStyle::cases() as $style) {
            $formatted = $md->toLocaleString('en-US', $style);

            static::assertStringNotContainsString('Monday', $formatted, "{$style->value} leaked the reference weekday");
            static::assertDoesNotMatchRegularExpression(
                '/\d{4}/',
                $formatted,
                "{$style->value} leaked the reference year",
            );
        }
    }

    public function testPlainMonthDayUsesItsOwnCalendar(): void
    {
        $md = new PlainDate(2024, 3, 15, Calendar::Hebrew)->toPlainMonthDay();

        static::assertStringContainsString('Adar', $md->toLocaleString('en-US', DateStyle::Long));
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Asserts equality after folding ICU's Unicode space separators to U+0020.
     */
    private static function assertLocalizedSame(string $expected, string $actual): void
    {
        static::assertSame(self::foldSpaces($expected), self::foldSpaces($actual));
    }

    /**
     * Asserts containment after folding ICU's Unicode space separators to U+0020.
     */
    private static function assertLocalizedStringContainsString(string $needle, string $haystack): void
    {
        static::assertStringContainsString(self::foldSpaces($needle), self::foldSpaces($haystack));
    }

    /**
     * Replaces the space separators ICU emits inside formatted output with U+0020.
     *
     * Which one appears depends on the ICU version — en-US switched from a plain
     * space to U+202F before the day period in ICU 72 — and none of them carry
     * meaning for these assertions.
     */
    private static function foldSpaces(string $value): string
    {
        return str_replace(search: ["\u{202F}", "\u{00A0}", "\u{2009}", "\u{2007}"], replace: ' ', subject: $value);
    }
}
