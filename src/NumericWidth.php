<?php

declare(strict_types=1);

namespace Temporal;

/**
 * Width of a numeric field — year, day, hour, minute, or second — in a
 * {@see DateTimeFormat} built from individual components.
 */
enum NumericWidth: string
{
    /** As many digits as the value needs — "5". */
    case Numeric = 'numeric';

    /** Zero-padded to two digits — "05". */
    case TwoDigit = '2-digit';
}
