<?php

declare(strict_types=1);

namespace Temporal;

/**
 * Length of the date portion of a localized string.
 *
 * Mirrors ECMA-402's `dateStyle` option. Every case resolves against the
 * requested locale's CLDR data, so the same case renders differently per
 * locale — `Long` is "March 15, 2024" in `en-US` and "15. März 2024" in
 * `de-DE`.
 *
 * @see PlainDate::toLocaleString()
 */
enum DateStyle: string
{
    /** Weekday plus the fully spelled-out date, e.g. "Friday, March 15, 2024". */
    case Full = 'full';

    /** Spelled-out month, no weekday, e.g. "March 15, 2024". */
    case Long = 'long';

    /** Abbreviated month, e.g. "Mar 15, 2024". */
    case Medium = 'medium';

    /** All-numeric and as compact as the locale allows, e.g. "3/15/24". */
    case Short = 'short';
}
