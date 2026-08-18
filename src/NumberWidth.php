<?php

declare(strict_types=1);

namespace Calendrics;

/**
 * Width of a component rendered as digits.
 *
 * Applies to the `year`, `day`, `hour`, `minute`, and `second` options of the
 * localized formatting methods.
 */
enum NumberWidth: string
{
    /** As many digits as the value needs (e.g. "6"). */
    case Numeric = 'numeric';

    /** Zero-padded to two digits (e.g. "06"). */
    case TwoDigit = '2-digit';
}
