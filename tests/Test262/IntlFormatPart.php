<?php

declare(strict_types=1);

namespace Calendrics\Tests\Test262;

/**
 * One element of {@see IntlDateTimeFormat::formatToParts()}'s result — the PHP
 * stand-in for the `{ type, value }` records ECMA-402 FormatDateTimeToParts returns,
 * plus the `source` that FormatDateTimeRangeToParts adds to say which endpoint of a
 * range a part came from. A part outside a range has no source, which reaches the
 * fixtures as null where JS gives them undefined.
 *
 * Transpiled fixtures read the record both ways: property access (`$part->type`,
 * from JS `part.type`) and array access (`$part['type']`, from destructured
 * arrow-function parameters), so the class exposes its fields through both.
 *
 * @implements \ArrayAccess<string, ?string>
 * @psalm-api used by dynamically-required test262 scripts in tests/Test262/scripts/
 */
final class IntlFormatPart implements \ArrayAccess
{
    public function __construct(
        public readonly string $type,
        public readonly string $value,
        public readonly ?string $source = null,
    ) {}

    #[\Override]
    public function offsetExists(mixed $offset): bool
    {
        return $offset === 'type' || $offset === 'value' || $offset === 'source';
    }

    #[\Override]
    public function offsetGet(mixed $offset): ?string
    {
        return match ($offset) {
            'type' => $this->type,
            'value' => $this->value,
            'source' => $this->source,
            default => throw new \LogicException(sprintf('IntlFormatPart has no field %s.', print_r(
                $offset,
                return: true,
            ))),
        };
    }

    #[\Override]
    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \LogicException('IntlFormatPart is read-only.');
    }

    #[\Override]
    public function offsetUnset(mixed $offset): void
    {
        throw new \LogicException('IntlFormatPart is read-only.');
    }
}
