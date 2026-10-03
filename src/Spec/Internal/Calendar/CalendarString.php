<?php

declare(strict_types=1);

namespace Calendrics\Spec\Internal\Calendar;

use Calendrics\Spec\Internal\IsoString;

/** @internal */
final class CalendarString
{
    public static function parse(string $input): string
    {
        $parsed = IsoString::parse($input);
        return CalendarFactory::canonicalize($parsed['calendar'] ?? 'iso8601');
    }
}
