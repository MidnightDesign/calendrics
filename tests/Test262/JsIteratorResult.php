<?php

declare(strict_types=1);

namespace Temporal\Tests\Test262;

/**
 * JS iterator-protocol result record: `{ value, done }`.
 *
 * @psalm-api used by dynamically-required test262 scripts in tests/Test262/scripts/
 */
final class JsIteratorResult
{
    public function __construct(
        public readonly mixed $value,
        public readonly bool $done,
    ) {}
}
