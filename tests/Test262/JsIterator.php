<?php

declare(strict_types=1);

namespace Calendrics\Tests\Test262;

/**
 * PHP equivalent of a JavaScript iterator, as returned by `Set.prototype.values()`.
 *
 * Transpiled fixtures consume it exactly the way JS does — `it.next().value` — so
 * `next()` returns an IteratorResult-shaped object rather than the value itself, and
 * exhausted iterators yield `undefined` instead of throwing.
 *
 * @psalm-api used by dynamically-required test scripts in tests/Test262/scripts/
 */
final class JsIterator
{
    private int $position = 0;

    /** @param list<mixed> $values */
    public function __construct(
        private readonly array $values,
    ) {}

    /** Mirrors JS `Iterator.prototype.next()`: yields the next IteratorResult. */
    public function next(): JsIteratorResult
    {
        if ($this->position >= count($this->values)) {
            return new JsIteratorResult(JsUndefined::singleton(), done: true);
        }

        /** @var mixed $value */
        $value = $this->values[$this->position];
        $this->position++;

        return new JsIteratorResult($value, done: false);
    }
}
