<?php

declare(strict_types=1);

namespace Calendrics;

/**
 * Controls how out-of-range values are handled when constructing or modifying Temporal objects.
 */
enum Overflow: string
{
    /** Clamp out-of-range values to the nearest valid value. */
    case Constrain = 'constrain';

    /** Throw an exception for out-of-range values. */
    case Reject = 'reject';
}
