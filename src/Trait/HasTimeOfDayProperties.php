<?php

declare(strict_types=1);

namespace Calendrics\Trait;

/**
 * Virtual (get-only) time-of-day properties that delegate to a spec
 * instance accessible via `$this->spec`.
 *
 * Used by outer-layer wrapper classes (PlainTime, PlainDateTime,
 * ZonedDateTime) to share property-hook declarations that would otherwise
 * be copy-pasted.
 *
 * @internal
 * @phpstan-require-implements HasTimeOfDaySpec
 * @psalm-require-implements HasTimeOfDaySpec
 */
trait HasTimeOfDayProperties
{
    /**
     * Hour of the day (0–23).
     *
     * @psalm-suppress PropertyNotSetInConstructor — virtual property (get-only hook, no backing store)
     */
    public int $hour {
        get => $this->spec->hour;
    }

    /**
     * Minute of the hour (0–59).
     *
     * @psalm-suppress PropertyNotSetInConstructor — virtual property (get-only hook, no backing store)
     */
    public int $minute {
        get => $this->spec->minute;
    }

    /**
     * Second of the minute (0–59).
     *
     * @psalm-suppress PropertyNotSetInConstructor — virtual property (get-only hook, no backing store)
     */
    public int $second {
        get => $this->spec->second;
    }

    /**
     * Millisecond within the second (0–999).
     *
     * @psalm-suppress PropertyNotSetInConstructor — virtual property (get-only hook, no backing store)
     */
    public int $millisecond {
        get => $this->spec->millisecond;
    }

    /**
     * Microsecond within the millisecond (0–999).
     *
     * @psalm-suppress PropertyNotSetInConstructor — virtual property (get-only hook, no backing store)
     */
    public int $microsecond {
        get => $this->spec->microsecond;
    }

    /**
     * Nanosecond within the microsecond (0–999).
     *
     * @psalm-suppress PropertyNotSetInConstructor — virtual property (get-only hook, no backing store)
     */
    public int $nanosecond {
        get => $this->spec->nanosecond;
    }
}
