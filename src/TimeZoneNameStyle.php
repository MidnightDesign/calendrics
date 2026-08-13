<?php

declare(strict_types=1);

namespace Temporal;

/**
 * How the time zone is named in a {@see DateTimeFormat} built from individual
 * components.
 *
 * Only meaningful for values that carry a zone — {@see ZonedDateTime}, and
 * {@see Instant} formatted through a `timeZone`.
 */
enum TimeZoneNameStyle: string
{
    /** Abbreviated, location-specific — "PST". */
    case Short = 'short';

    /** Spelled out, location-specific — "Pacific Standard Time". */
    case Long = 'long';

    /** Abbreviated GMT offset — "GMT-8". */
    case ShortOffset = 'shortOffset';

    /** Full GMT offset — "GMT-08:00". */
    case LongOffset = 'longOffset';

    /** Abbreviated, without a standard/daylight distinction — "PT". */
    case ShortGeneric = 'shortGeneric';

    /** Spelled out, without a standard/daylight distinction — "Pacific Time". */
    case LongGeneric = 'longGeneric';
}
