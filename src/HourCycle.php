<?php

declare(strict_types=1);

namespace Calendrics;

/**
 * Hour numbering convention for localized time output.
 *
 * Replaces the ECMA-402 pair of `hour12` (a boolean) and `hourCycle` (a string),
 * which overlap and can contradict each other. Leaving this unset lets the
 * locale pick its own convention.
 */
enum HourCycle: string
{
    /** 12-hour clock numbered 0–11. */
    case H11 = 'h11';

    /** 12-hour clock numbered 1–12. */
    case H12 = 'h12';

    /** 24-hour clock numbered 0–23. */
    case H23 = 'h23';

    /** 24-hour clock numbered 1–24. */
    case H24 = 'h24';
}
