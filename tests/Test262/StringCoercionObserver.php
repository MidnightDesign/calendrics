<?php

declare(strict_types=1);

namespace Temporal\Tests\Test262;

use Override;
use Stringable;

/**
 * A string option/field value that logs its own ToString coercion.
 *
 * TC39's order-of-operations fixtures assert that an operation reads each option
 * off the bag and coerces it *immediately*, before reading the next one. The JS
 * harness proves that by handing each option value an object whose `toString`
 * accessor logs `get <name>.toString` and whose returned function logs
 * `call <name>.toString`. This is the PHP spelling of that object: `__toString()`
 * records both events in the same order, so a fixture's expected trace transfers
 * verbatim.
 *
 * Only string-valued options are observable this way. JS's ToNumber has no PHP
 * hook — an object cannot stand in for an integer — so numeric options are handed
 * to the implementation unwrapped and their `…valueOf` events are filtered out of
 * the expected trace by {@see Assert::compareObserverTrace()}.
 *
 * @psalm-api used by dynamically-required test262 scripts in tests/Test262/scripts/
 */
final readonly class StringCoercionObserver implements Stringable
{
    /**
     * @param ObserverTrace $calls Shared trace this coercion is recorded into.
     * @param string $value The string this stands in for.
     * @param string $name Dotted path used in the logged events (e.g. "options.smallestUnit").
     */
    public function __construct(
        private ObserverTrace $calls,
        private string $value,
        private string $name,
    ) {}

    #[Override]
    public function __toString(): string
    {
        $this->calls->record("get {$this->name}.toString", "call {$this->name}.toString");

        return $this->value;
    }
}
