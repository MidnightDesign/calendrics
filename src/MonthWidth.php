<?php

declare(strict_types=1);

namespace Temporal;

/**
 * Width of the month field in a {@see DateTimeFormat} built from individual
 * components.
 *
 * Months are the one field that can be rendered either numerically or as text,
 * so they get their own enum rather than sharing {@see NumericWidth} or
 * {@see TextWidth}.
 */
enum MonthWidth: string
{
    /** As many digits as the value needs — "3". */
    case Numeric = 'numeric';

    /** Zero-padded to two digits — "03". */
    case TwoDigit = '2-digit';

    /** Single letter where the locale has one — "M". */
    case Narrow = 'narrow';

    /** Abbreviation — "Mar". */
    case Short = 'short';

    /** Full name — "March". */
    case Long = 'long';
}
