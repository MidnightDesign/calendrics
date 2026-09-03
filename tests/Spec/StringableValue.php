<?php

declare(strict_types=1);

namespace Calendrics\Tests\Spec;

use Stringable;

/**
 * A plain object that converts to a caller-chosen string.
 *
 * The spec layer accepts a `\Stringable` wherever TC39 applies a to-string
 * conversion; this is the smallest thing that satisfies it.
 */
final class StringableValue implements Stringable
{
    public function __construct(
        private readonly string $value,
    ) {}

    #[\Override]
    public function __toString(): string
    {
        return $this->value;
    }
}
