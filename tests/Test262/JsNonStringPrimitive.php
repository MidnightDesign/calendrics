<?php

declare(strict_types=1);

namespace Temporal\Tests\Test262;

use Override;
use Stringable;

/**
 * A JS object whose `toString` returns something that is not a String.
 *
 * Fixtures put `{ toString: () => 5 }` in wrong-type tables because TC39's
 * ToPrimitiveAndRequireString runs ToPrimitive and *then* rejects a result that is
 * not a String — so the value throws TypeError despite being stringifiable. PHP has
 * no such value: the `__toString()` return type is `string`, enforced by the engine,
 * so every \Stringable satisfies RequireString and the rejection cannot be
 * reproduced. Loops that assert a throw over such a table skip these entries; the
 * transpiler emits the guard (see the `for-of` handling in
 * tools/transpile-test262.mjs).
 *
 * The class is still \Stringable so the fixture's own assertion-description
 * interpolation — `` `month code ${value} should be rejected` `` — keeps working.
 *
 * @psalm-api used by dynamically-required test262 scripts in tests/Test262/scripts/
 */
final readonly class JsNonStringPrimitive implements Stringable
{
    public function __construct(
        private mixed $value,
    ) {}

    #[Override]
    public function __toString(): string
    {
        return match (true) {
            is_bool($this->value) => $this->value ? 'true' : 'false',
            $this->value === null => 'null',
            is_scalar($this->value) => (string) $this->value,
            default => get_debug_type($this->value),
        };
    }
}
