<?php

declare(strict_types=1);

namespace Calendrics\Spec\Internal;

use Calendrics\Spec\Internal\Calendar\IntlCalendarFactory;

/** @internal */
final class IntlCalendarPatterns
{
    // ICU's root DateTimePatterns and availableFormats aliases restart lookup at
    // the requested locale, not at root, for the target calendar.
    private const array PARENT_CALENDARS = [
        'buddhist' => 'generic',
        'coptic' => 'generic',
        'dangi' => 'chinese',
        'ethiopic' => 'generic',
        'ethiopic-amete-alem' => 'ethiopic',
        'hebrew' => 'generic',
        'indian' => 'generic',
        'islamic' => 'generic',
        'islamic-civil' => 'islamic',
        'islamic-tbla' => 'islamic',
        'islamic-umalqura' => 'islamic',
        'japanese' => 'generic',
        'persian' => 'generic',
        'roc' => 'generic',
    ];

    public static function standardTimePattern(string $locale): ?string
    {
        foreach (self::calendarData($locale) as $calendarData) {
            $patterns = $calendarData->get('DateTimePatterns');
            $pattern = $patterns instanceof \ResourceBundle ? $patterns->get(\IntlDateFormatter::SHORT) : null;
            if ($pattern instanceof \ResourceBundle) {
                $pattern = $pattern->get(0);
            }
            if (is_string($pattern)) {
                return $pattern;
            }
        }

        return null;
    }

    public static function availableFormat(string $locale, string $skeleton): ?string
    {
        foreach (self::calendarData($locale) as $calendarData) {
            $formats = $calendarData->get('availableFormats');
            $pattern = $formats instanceof \ResourceBundle ? $formats->get($skeleton) : null;
            if (is_string($pattern)) {
                return $pattern;
            }
        }

        return null;
    }

    /** @return \Generator<int, \ResourceBundle> */
    private static function calendarData(string $locale): \Generator
    {
        $calendar = IntlCalendarFactory::forLocale(timeZone: null, locale: $locale);
        $calendarType = $calendar->getType();
        $formatter = new \IntlDateFormatter($locale, \IntlDateFormatter::NONE, \IntlDateFormatter::SHORT);
        $locale = $formatter->getLocale(\Locale::VALID_LOCALE);
        do {
            $parentCalendar = self::PARENT_CALENDARS[$calendarType] ?? null;
            foreach (self::localeBundles($locale) as $candidate => $bundle) {
                if ($candidate === 'root' && $parentCalendar !== null) {
                    break;
                }
                $calendars = $bundle->get('calendar');
                $data = $calendars instanceof \ResourceBundle ? $calendars->get($calendarType) : null;
                if ($data instanceof \ResourceBundle) {
                    yield $data;
                }
            }
            $calendarType = $parentCalendar;
        } while ($calendarType !== null);
    }

    /** @return \Generator<string, \ResourceBundle> */
    private static function localeBundles(string|false $locale): \Generator
    {
        if ($locale === false) {
            throw new \RuntimeException('ICU could not resolve the formatting locale.');
        }
        $locale = explode('@', \Locale::canonicalize($locale) ?? $locale, limit: 2)[0];
        $visited = [];
        while (!array_key_exists($locale, $visited)) {
            $visited[$locale] = true;
            $bundle = \ResourceBundle::create($locale, bundle: null, fallback: false);
            if ($bundle instanceof \ResourceBundle) {
                yield $locale => $bundle;
            }
            if ($locale === 'root') {
                return;
            }

            // Nested ResourceBundle lookups do not inherit missing calendar entries.
            // ICU's pattern generator walks the resource path through locale parents.
            $parent = $bundle?->get('%%Parent');
            if (is_string($parent)) {
                $locale = $parent;
                continue;
            }
            $separator = strrpos($locale, needle: '_');
            $locale = $separator === false ? 'root' : substr($locale, offset: 0, length: $separator);
        }
    }
}
