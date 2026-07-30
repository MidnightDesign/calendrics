<?php

declare(strict_types=1);

namespace Temporal\Tests\Test262;

/**
 * Minimal stand-in for the JavaScript `Set` built-in, scoped to the operations
 * intl402 fixtures perform: construction from an array, `has`, `add`, `delete`,
 * `size`, and `values().next().value` to pull the first remaining element.
 *
 * Insertion order is preserved, matching JS Set iteration order.
 *
 * @psalm-api used by dynamically-required test262 scripts in tests/Test262/scripts/
 */
final class JsSet
{
    /** @var list<mixed> */
    private array $items = [];

    /** @psalm-suppress PropertyNotSetInConstructor — virtual property */
    public int $size {
        get => count($this->items);
    }

    /** @param iterable<mixed> $items */
    public function __construct(iterable $items = [])
    {
        /** @var mixed $item */
        foreach ($items as $item) {
            $this->add($item);
        }
    }

    public function add(mixed $value): self
    {
        if (!in_array($value, $this->items, strict: true)) {
            $this->items[] = $value;
        }
        return $this;
    }

    public function has(mixed $value): bool
    {
        return in_array($value, $this->items, strict: true);
    }

    public function delete(mixed $value): bool
    {
        $index = array_search($value, $this->items, strict: true);
        if ($index === false) {
            return false;
        }
        array_splice($this->items, offset: $index, length: 1);
        return true;
    }

    /** JS Set.prototype.values() — an iterator over the remaining elements. */
    public function values(): JsSetIterator
    {
        return new JsSetIterator($this->items);
    }
}
