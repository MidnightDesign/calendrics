<?php

declare(strict_types=1);

namespace Calendrics;

/**
 * Controls how a provided UTC offset is handled when parsing or modifying a ZonedDateTime.
 */
enum OffsetOption: string
{
    /** Use the provided offset to determine the exact instant, ignoring the time zone. */
    case Use = 'use';

    /** Prefer the provided offset if it is valid for the time zone; otherwise use the time zone. */
    case Prefer = 'prefer';

    /** Ignore the provided offset entirely; use the time zone to determine the instant. */
    case Ignore = 'ignore';

    /** Throw an exception if the provided offset does not match the time zone. */
    case Reject = 'reject';
}
