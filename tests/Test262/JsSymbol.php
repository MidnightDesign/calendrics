<?php

declare(strict_types=1);

namespace Calendrics\Tests\Test262;

use Calendrics\Exception\TypeError;
use Stringable;

/**
 * Singleton sentinel for a JS `Symbol()` value.
 *
 * Lives only in transpiled test262 fixtures and the helpers in this namespace.
 * The transpiler emits `JsSymbol::singleton()` for a bare `Symbol()` call so
 * that fixtures exercising symbol-coercion behavior translate faithfully.
 *
 * In JS, `ToString(Symbol)` and `ToNumber(Symbol)` throw a `TypeError`. To
 * reproduce that, this class *is* {@see Stringable}: the spec layer's coercion
 * helpers attempt `(string) $value`, invoke {@see self::__toString()}, and get a
 * `TypeError`. A plain `stdClass` (which is *not* Stringable) instead falls
 * through to a `RangeError`. That asymmetry is what makes, e.g.,
 * `fractionalSecondDigits: Symbol()` raise `TypeError` while
 * `fractionalSecondDigits: {}` raises `RangeError`.
 *
 * @psalm-api used by transpiled test262 scripts
 */
final class JsSymbol implements Stringable
{
    private static ?self $instance = null;

    private function __construct() {}

    public static function singleton(): self
    {
        return self::$instance ??= new self();
    }

    #[\Override]
    public function __toString(): string
    {
        throw new TypeError('Cannot convert a Symbol value to a string');
    }
}
