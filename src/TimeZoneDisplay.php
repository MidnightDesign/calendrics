<?php

declare(strict_types=1);

namespace Calendrics;

/**
 * Controls whether the time zone name annotation is included in ZonedDateTime string output.
 */
enum TimeZoneDisplay: string
{
    /** Include the time zone name (default behavior). */
    case Auto = 'auto';

    /** Omit the time zone name. */
    case Never = 'never';

    /** Include the time zone name with a critical flag (!). */
    case Critical = 'critical';
}
