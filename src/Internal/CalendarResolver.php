<?php

declare(strict_types=1);

namespace Calendrics\Internal;

use Calendrics\Calendar;
use Calendrics\PlainDate;
use Calendrics\PlainDateTime;
use Calendrics\PlainMonthDay;
use Calendrics\PlainYearMonth;
use Calendrics\ZonedDateTime;

/** @internal */
final class CalendarResolver
{
    public static function resolve(Calendar|PlainDate|PlainDateTime|PlainMonthDay|PlainYearMonth|ZonedDateTime $calendarLike): Calendar
    {
        if ($calendarLike instanceof Calendar) {
            return $calendarLike;
        }

        return $calendarLike->calendar;
    }
}
