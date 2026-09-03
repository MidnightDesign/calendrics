<?php

declare(strict_types=1);

namespace Calendrics\Spec\Internal;

/**
 * The string forms every temporal type derives from its own `toString()`.
 *
 * Locale-sensitive formatting is not part of this contract: the zoneless `Plain*`
 * types share {@see HasPlainLocaleString}, while ZonedDateTime formats an exact
 * instant in a real zone and implements `toLocaleString()` itself.
 *
 * @internal
 */
trait HasStringRepresentations
{
    abstract public function toString(): string;

    #[\Override]
    public function __toString(): string
    {
        return $this->toString();
    }

    /** @psalm-api */
    public function toJSON(): string
    {
        return $this->toString();
    }
}
