<?php

declare(strict_types=1);

namespace Temporal;

/**
 * Length of the time portion of a localized string.
 *
 * Mirrors ECMA-402's `timeStyle` option. `Full` and `Long` include the time
 * zone, which only carries information for a zone-aware value: on
 * {@see PlainTime} and {@see PlainDateTime} — neither of which has a zone —
 * they render the UTC label the formatter falls back to, so prefer
 * {@see self::Medium} there.
 *
 * @see PlainTime::toLocaleString()
 */
enum TimeStyle: string
{
    /** Seconds plus the fully spelled-out zone, e.g. "9:30:45 AM Central European Standard Time". */
    case Full = 'full';

    /** Seconds plus a short zone label, e.g. "9:30:45 AM GMT+1". */
    case Long = 'long';

    /** Hour, minute and second, no zone, e.g. "9:30:45 AM". */
    case Medium = 'medium';

    /** Hour and minute only, e.g. "9:30 AM". */
    case Short = 'short';
}
