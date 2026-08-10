<?php

declare(strict_types=1);

namespace Temporal\Tests\Test262;

/**
 * PHP equivalent of a JavaScript IteratorResult — the `{ value, done }` record
 * returned by {@see JsIterator::next()}.
 *
 * @psalm-api used by dynamically-required test scripts in tests/Test262/scripts/
 */
final class JsIteratorResult
{
    public function __construct(
        public readonly mixed $value,
        public readonly bool $done,
    ) {}
}
