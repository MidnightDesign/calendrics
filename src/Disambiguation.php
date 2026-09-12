<?php

declare(strict_types=1);

namespace Calendrics;

/**
 * Controls how ambiguous wall-clock times are resolved during time zone conversions.
 *
 * Ambiguity occurs when a local time either does not exist (spring-forward DST gap)
 * or exists twice (fall-back DST overlap).
 */
enum Disambiguation: string
{
    /** Use the earlier occurrence for overlaps; shift forward for gaps. */
    case Compatible = 'compatible';

    /** Always use the earlier of two possible instants. */
    case Earlier = 'earlier';

    /** Always use the later of two possible instants. */
    case Later = 'later';

    /** Throw an exception for any ambiguous time. */
    case Reject = 'reject';
}
