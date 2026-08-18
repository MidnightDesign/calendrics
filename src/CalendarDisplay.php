<?php

declare(strict_types=1);

namespace Calendrics;

/**
 * Controls whether the calendar annotation is included in string output.
 */
enum CalendarDisplay: string
{
    /** Include the annotation only when the calendar is not iso8601. */
    case Auto = 'auto';

    /** Always include the calendar annotation. */
    case Always = 'always';

    /** Never include the calendar annotation. */
    case Never = 'never';

    /** Always include the calendar annotation with a critical flag (!). */
    case Critical = 'critical';
}
