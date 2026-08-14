<?php

declare(strict_types=1);

namespace Temporal;

/**
 * Width of a component rendered as locale-specific text rather than digits.
 *
 * Applies to the `weekday`, `era`, and `dayPeriod` options of the localized
 * formatting methods.
 */
enum TextWidth: string
{
    /** Single-letter form where the locale has one (e.g. "M" for Monday). */
    case Narrow = 'narrow';

    /** Abbreviated form (e.g. "Mon"). */
    case Short = 'short';

    /** Full form (e.g. "Monday"). */
    case Long = 'long';
}
