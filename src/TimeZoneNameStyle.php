<?php

declare(strict_types=1);

namespace Calendrics;

/**
 * How the time zone is named in a localized string.
 *
 * Corresponds to ECMA-402's `timeZoneName` option. Not to be confused with
 * {@see TimeZoneDisplay}, which controls the `[…]` annotation in ISO 8601
 * output rather than localized text.
 */
enum TimeZoneNameStyle: string
{
    /** Abbreviated location-specific name (e.g. "PST"). */
    case Short = 'short';

    /** Full location-specific name (e.g. "Pacific Standard Time"). */
    case Long = 'long';

    /** Short localized GMT offset (e.g. "GMT-8"). */
    case ShortOffset = 'shortOffset';

    /** Long localized GMT offset (e.g. "GMT-08:00"). */
    case LongOffset = 'longOffset';

    /** Abbreviated non-location name (e.g. "PT"). */
    case ShortGeneric = 'shortGeneric';

    /** Full non-location name (e.g. "Pacific Time"). */
    case LongGeneric = 'longGeneric';
}
