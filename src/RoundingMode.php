<?php

declare(strict_types=1);

namespace Calendrics;

/**
 * Rounding modes for Temporal arithmetic and formatting operations.
 *
 * This enum defines all 9 rounding modes from the TC39 Temporal specification.
 */
enum RoundingMode: string
{
    /** Round toward positive infinity. */
    case Ceil = 'ceil';

    /** Round toward negative infinity. */
    case Floor = 'floor';

    /** Round away from zero. */
    case Expand = 'expand';

    /** Round toward zero (truncate). */
    case Trunc = 'trunc';

    /** Round half toward positive infinity. */
    case HalfCeil = 'halfCeil';

    /** Round half toward negative infinity. */
    case HalfFloor = 'halfFloor';

    /** Round half away from zero (standard mathematical rounding). */
    case HalfExpand = 'halfExpand';

    /** Round half toward zero. */
    case HalfTrunc = 'halfTrunc';

    /** Round half to the nearest even integer (banker's rounding). */
    case HalfEven = 'halfEven';
}
