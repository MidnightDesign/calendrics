<?php

declare(strict_types=1);

namespace Calendrics\Spec\Internal\Calendar;

use Calendrics\Exception\RangeError;

/**
 * Owns the boundary between Temporal calendar identifiers and ICU calendars.
 *
 * @internal
 */
final class IntlCalendarFactory
{
    /** @var array<string, string> */
    private const CALENDAR_TO_ICU = [
        'iso8601' => 'iso8601',
        'buddhist' => 'buddhist',
        'chinese' => 'chinese',
        'coptic' => 'coptic',
        'dangi' => 'dangi',
        'ethioaa' => 'ethiopic-amete-alem',
        'ethiopic' => 'ethiopic',
        'gregory' => 'gregorian',
        'hebrew' => 'hebrew',
        'indian' => 'indian',
        'islamic-civil' => 'islamic-civil',
        'islamic-tbla' => 'islamic-tbla',
        'islamic-umalqura' => 'islamic-umalqura',
        'japanese' => 'japanese',
        'persian' => 'persian',
        'roc' => 'roc',
    ];

    /** @var array<string, string> */
    private const ICU_TO_CALENDAR = [
        'gregorian' => 'gregory',
        'ethiopic-amete-alem' => 'ethioaa',
    ];

    public static function icuType(string $calendarId): string
    {
        return self::CALENDAR_TO_ICU[$calendarId] ?? throw new RangeError(
            "No ICU mapping for calendar \"{$calendarId}\".",
        );
    }

    public static function calendarId(string $icuType): string
    {
        return self::ICU_TO_CALENDAR[$icuType] ?? $icuType;
    }

    public static function forCalendarId(?string $timeZone, string $calendarId): \IntlCalendar
    {
        return self::makeGregorianProleptic(self::create($timeZone, sprintf(
            '@calendar=%s',
            self::icuType($calendarId),
        )));
    }

    public static function forLocale(?string $timeZone, string $locale): \IntlCalendar
    {
        return self::create($timeZone, $locale);
    }

    /**
     * Returns the calendar a locale formats through, reckoned as proleptic Gregorian.
     *
     * ICU models ISO 8601 as a Gregorian calendar with ISO week rules, but exposes it
     * through the base IntlCalendar class. Date and time patterns do not read week
     * numbering, so a plain Gregorian calendar supplies the same formatting fields
     * while allowing the Gregorian cutover to be disabled.
     */
    public static function forFormatting(?string $timeZone, string $locale): \IntlCalendar
    {
        $calendar = self::forLocale($timeZone, $locale);
        if ($calendar->getType() === 'iso8601') {
            $calendar = self::forCalendarId($timeZone, 'gregory');
        }

        return self::makeGregorianProleptic($calendar);
    }

    private static function create(?string $timeZone, string $locale): \IntlCalendar
    {
        return self::requireCalendar(\IntlCalendar::createInstance($timeZone, $locale), $locale);
    }

    /** Normalizes the conflicting analyzer stubs for ext-intl's nullable runtime return. */
    private static function requireCalendar(mixed $calendar, string $locale): \IntlCalendar
    {
        if (!$calendar instanceof \IntlCalendar) {
            throw new \RuntimeException("ICU could not create a calendar for locale \"{$locale}\".");
        }

        return $calendar;
    }

    private static function makeGregorianProleptic(\IntlCalendar $calendar): \IntlCalendar
    {
        if ($calendar instanceof \IntlGregorianCalendar) {
            $calendar->setGregorianChange(-INF);
        }

        return $calendar;
    }
}
