<?php

declare(strict_types=1);

namespace Calendrics;

/**
 * Preset verbosity for the date or time half of a localized string.
 *
 * Corresponds to ECMA-402's `dateStyle` / `timeStyle` options. A style option
 * selects a locale-provided pattern as a whole and therefore cannot be combined
 * with individual component options (`year`, `month`, `hour`, …).
 */
enum FormatStyle: string
{
    /** The most verbose form the locale provides (e.g. "Monday, June 15, 2020"). */
    case Full = 'full';

    /** Verbose, but without the weekday (e.g. "June 15, 2020"). */
    case Long = 'long';

    /** Abbreviated names (e.g. "Jun 15, 2020"). */
    case Medium = 'medium';

    /** The most compact form the locale provides (e.g. "6/15/20"). */
    case Short = 'short';
}
