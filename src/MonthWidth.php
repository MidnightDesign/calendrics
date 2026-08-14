<?php

declare(strict_types=1);

namespace Temporal;

/**
 * Width of the month component in a localized string.
 *
 * The month is the one component that can be rendered either as digits or as
 * locale-specific text, so it has its own width vocabulary rather than reusing
 * {@see NumberWidth} or {@see TextWidth}.
 */
enum MonthWidth: string
{
    /** As many digits as the value needs (e.g. "6"). */
    case Numeric = 'numeric';

    /** Zero-padded to two digits (e.g. "06"). */
    case TwoDigit = '2-digit';

    /** Single-letter form where the locale has one (e.g. "J"). */
    case Narrow = 'narrow';

    /** Abbreviated name (e.g. "Jun"). */
    case Short = 'short';

    /** Full name (e.g. "June"). */
    case Long = 'long';
}
