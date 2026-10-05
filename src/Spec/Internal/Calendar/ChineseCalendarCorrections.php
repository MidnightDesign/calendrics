<?php

declare(strict_types=1);

namespace Calendrics\Spec\Internal\Calendar;

use Calendrics\Exception\RangeError;
use Calendrics\Spec\Internal\CalendarMath;

/** Shared absolute month boundaries for the Chinese calendar's known ICU discrepancies. */
final class ChineseCalendarCorrections
{
    /**
     * HKO Gregorian–Lunar conversion tables, including the adjacent month's start.
     * https://www.hko.gov.hk/en/gts/time/calendar/pdf/files/2027e.pdf
     * https://www.hko.gov.hk/en/gts/time/calendar/pdf/files/2030e.pdf
     * Absolute boundaries also produce the same result when ICU already agrees.
     *
     * @var array<int, array<int, array{array{int,int,int}, array{int,int,int}}>>
     */
    private const MONTHS = [
        2026 => [12 => [[2027, 1, 8], [2027, 2, 6]]],
        2027 => [1 => [[2027, 2, 6], [2027, 3, 8]]],
        2029 => [12 => [[2030, 1, 4], [2030, 2, 3]]],
        2030 => [1 => [[2030, 2, 3], [2030, 3, 4]]],
    ];

    /** @return array{year:int,month:int,day:int,daysInMonth:int}|null */
    public static function fromIso(int $isoYear, int $isoMonth, int $isoDay): ?array
    {
        if ($isoYear !== 2027 && $isoYear !== 2030 || $isoMonth > 3) {
            return null;
        }
        $jdn = CalendarMath::toJulianDay($isoYear, $isoMonth, $isoDay);
        foreach (self::MONTHS as $year => $months) {
            foreach ($months as $month => [$start, $end]) {
                $startJdn = CalendarMath::toJulianDay(...$start);
                $endJdn = CalendarMath::toJulianDay(...$end);
                if ($jdn >= $startJdn && $jdn < $endJdn) {
                    return [
                        'year' => $year,
                        'month' => $month,
                        'day' => $jdn - $startJdn + 1,
                        'daysInMonth' => $endJdn - $startJdn,
                    ];
                }
            }
        }
        return null;
    }

    /** @return array{int,int<1,12>,int<1,31>}|null */
    public static function toIso(int $year, int $month, int $day, string $overflow): ?array
    {
        $bounds = self::MONTHS[$year][$month] ?? null;
        if ($bounds === null) {
            return null;
        }
        [$start, $end] = $bounds;
        $startJdn = CalendarMath::toJulianDay(...$start);
        $maxDay = CalendarMath::toJulianDay(...$end) - $startJdn;
        if ($day > $maxDay && $overflow === 'reject') {
            throw new RangeError("Day {$day} exceeds maximum {$maxDay} for this calendar month.");
        }
        return CalendarMath::fromJulianDay($startJdn + min($day, $maxDay) - 1);
    }

    public static function yearStart(int $year): ?int
    {
        $bounds = self::MONTHS[$year][1] ?? null;
        return $bounds === null ? null : CalendarMath::toJulianDay(...$bounds[0]);
    }
}
