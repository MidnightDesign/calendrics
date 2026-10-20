<?php

declare(strict_types=1);

namespace Calendrics\Spec\Internal\Calendar;

/** A resolved Chinese/Dangi date, independent of mutable ICU state. @internal */
final readonly class ChineseCalendarFields
{
    public function __construct(
        public int $year,
        public int $month,
        public int $day,
        public string $monthCode,
        public int $dayOfYear,
        public int $daysInMonth,
        public int $daysInYear,
        public int $monthsInYear,
    ) {}
}
