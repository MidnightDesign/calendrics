<?php

declare(strict_types=1);

namespace Calendrics\Tests\Test262;

/**
 * The ordered log of property reads and coercions an order-of-operations fixture
 * records while calling the operation under test.
 *
 * Stands in for the JS fixtures' plain `const actual = []`. An object rather than an
 * array because the observers must append to the SAME log the fixture later asserts
 * on: PHP arrays are values, so sharing one would mean threading it by reference
 * through every observer, which reads as a magic side channel and is invisible to
 * static analysis. A small mutable object says exactly what is going on.
 *
 * The transpiler emits one of these wherever a fixture declares a tracker array, and
 * rewrites `actual.splice(0)` to {@see clear()}; see the observer handling in
 * tools/transpile-test262.mjs.
 *
 * @psalm-api used by dynamically-required test262 scripts in tests/Test262/scripts/
 */
final class ObserverTrace
{
    /** @var list<string> */
    private array $events = [];

    public function record(string ...$events): void
    {
        foreach ($events as $event) {
            $this->events[] = $event;
        }
    }

    /** @return list<string> */
    public function events(): array
    {
        return $this->events;
    }

    /** Drops everything recorded so far, so the next phase asserts on its own reads. */
    public function clear(): void
    {
        $this->events = [];
    }
}
