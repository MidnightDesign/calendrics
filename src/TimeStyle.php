<?php

declare(strict_types=1);

namespace Temporal;

/**
 * Preset level of detail for the time portion of a {@see DateTimeFormat}.
 *
 * The concrete rendering is locale-defined; the cases only order the presets
 * from most to least verbose.
 */
enum TimeStyle: string
{
    /** Hour, minute, second and the long time-zone name — "3:23:30 PM Central European Standard Time". */
    case Full = 'full';

    /** Hour, minute, second and the short time-zone name — "3:23:30 PM CET". */
    case Long = 'long';

    /** Hour, minute, second — "3:23:30 PM". */
    case Medium = 'medium';

    /** Hour and minute — "3:23 PM". */
    case Short = 'short';
}
