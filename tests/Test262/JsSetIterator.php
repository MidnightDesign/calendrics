<?php

declare(strict_types=1);

namespace Temporal\Tests\Test262;

/**
 * The iterator {@see JsSet::values()} returns: `next()` yields JS-style
 * `{ value, done }` result records.
 *
 * @psalm-api used by dynamically-required test262 scripts in tests/Test262/scripts/
 */
final class JsSetIterator
{
    private int $index = 0;

    /** @param list<mixed> $items */
    public function __construct(
        private readonly array $items,
    ) {}

    public function next(): JsIteratorResult
    {
        if ($this->index >= count($this->items)) {
            return new JsIteratorResult(null, true);
        }
        /** @var mixed $value */
        $value = $this->items[$this->index];
        $this->index++;
        return new JsIteratorResult($value, false);
    }
}
