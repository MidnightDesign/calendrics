<?php

declare(strict_types=1);

namespace Calendrics;

/**
 * Temporal calendar and clock units, from largest (year) to smallest (nanosecond).
 */
enum Unit: string
{
    case Year = 'year';
    case Month = 'month';
    case Week = 'week';
    case Day = 'day';
    case Hour = 'hour';
    case Minute = 'minute';
    case Second = 'second';
    case Millisecond = 'millisecond';
    case Microsecond = 'microsecond';
    case Nanosecond = 'nanosecond';
}
