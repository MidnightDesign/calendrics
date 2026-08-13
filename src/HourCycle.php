<?php

declare(strict_types=1);

namespace Temporal;

/**
 * Overrides the hour numbering a locale would pick on its own in a
 * {@see DateTimeFormat}.
 *
 * The two axes are the cycle length (12 or 24 hours) and whether the cycle
 * starts at 0 or 1.
 */
enum HourCycle: string
{
    /** 12-hour cycle numbered 0–11; midnight is 0 AM. */
    case H11 = 'h11';

    /** 12-hour cycle numbered 1–12; midnight is 12 AM. */
    case H12 = 'h12';

    /** 24-hour cycle numbered 0–23; midnight is 0. */
    case H23 = 'h23';

    /** 24-hour cycle numbered 1–24; midnight is 24. */
    case H24 = 'h24';
}
