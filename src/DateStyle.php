<?php

declare(strict_types=1);

namespace Temporal;

/**
 * Preset level of detail for the date portion of a {@see DateTimeFormat}.
 *
 * The concrete rendering is locale-defined; the cases only order the presets
 * from most to least verbose.
 */
enum DateStyle: string
{
    /** Weekday, day, full month name, year — "Thursday, March 15, 2024". */
    case Full = 'full';

    /** Day, full month name, year — "March 15, 2024". */
    case Long = 'long';

    /** Day, abbreviated month name, year — "Mar 15, 2024". */
    case Medium = 'medium';

    /** All-numeric, shortest form — "3/15/24". */
    case Short = 'short';
}
