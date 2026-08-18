<?php

declare(strict_types=1);

namespace Calendrics\Trait;

/**
 * Virtual (get-only) Unix epoch properties that delegate to a spec instance
 * accessible via `$this->spec`.
 *
 * Used by outer-layer wrapper classes (Instant, ZonedDateTime) to share
 * property-hook declarations that would otherwise be copy-pasted.
 *
 * @internal
 * @phpstan-require-implements HasEpochSpec
 * @psalm-require-implements HasEpochSpec
 */
trait HasEpochProperties
{
    /**
     * Nanoseconds since the Unix epoch (1970-01-01T00:00:00Z).
     *
     * @psalm-suppress PropertyNotSetInConstructor — virtual property (get-only hook, no backing store)
     */
    public int $epochNanoseconds {
        get => $this->spec->epochNanoseconds;
    }

    /**
     * Milliseconds since the Unix epoch (floor-divided from nanoseconds).
     *
     * @psalm-suppress PropertyNotSetInConstructor — virtual property (get-only hook, no backing store)
     */
    public int $epochMilliseconds {
        get => $this->spec->epochMilliseconds;
    }
}
