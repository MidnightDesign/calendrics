<?php

declare(strict_types=1);

namespace Temporal;

/**
 * Width of a spelled-out field — weekday, era, or day period — in a
 * {@see DateTimeFormat} built from individual components.
 */
enum TextWidth: string
{
    /** Single letter where the locale has one — "T" for Thursday. */
    case Narrow = 'narrow';

    /** Abbreviation — "Thu". */
    case Short = 'short';

    /** Full name — "Thursday". */
    case Long = 'long';
}
