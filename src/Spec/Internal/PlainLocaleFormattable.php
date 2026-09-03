<?php

declare(strict_types=1);

namespace Calendrics\Spec\Internal;

/**
 * Marks the zoneless `Plain*` types: those that render their wall-clock fields in
 * UTC through {@see HasPlainLocaleString}, which every implementor uses.
 *
 * {@see \Calendrics\Spec\ZonedDateTime} and {@see \Calendrics\Spec\Instant} format an
 * exact instant in a real time zone instead, so they are deliberately excluded.
 *
 * @internal
 */
interface PlainLocaleFormattable
{
    /**
     * @param string|array<array-key, mixed>|null $locales
     * @param array<array-key, mixed>|object|null $options
     */
    public function toLocaleString(string|array|null $locales = null, array|object|null $options = null): string;
}
