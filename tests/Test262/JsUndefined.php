<?php

declare(strict_types=1);

namespace Calendrics\Tests\Test262;

/**
 * Singleton sentinel for the JS `undefined` value, distinct from PHP `null`
 * (which represents JS `null`).
 *
 * Lives only in transpiled test262 fixtures and the helpers in this namespace.
 * The spec layer (`src/Spec/`) never sees instances of this class — the
 * transpiler strips `JsUndefined` entries from option/property bags via
 * {@see self::strip()} before they cross the boundary, and `Assert::sameValue`
 * and friends treat `JsUndefined::singleton()` as equivalent to PHP `null` for
 * value-equality checks (because our impl returns PHP `null` where JS returns
 * `undefined`). The distinction matters only for `typeof === 'undefined'`-style
 * branches in the test source itself.
 *
 * @psalm-api used by transpiled test262 scripts
 */
final class JsUndefined
{
    private static ?self $instance = null;

    private function __construct() {}

    public static function singleton(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * True for both the JsUndefined sentinel and PHP null. Used by the transpiler
     * to render JS `x === undefined` / `typeof x === 'undefined'` checks: a value
     * counts as "JS undefined" whether it came from a literal `undefined` (= the
     * sentinel) or from an impl-returned PHP null (e.g. `instance.era` for an
     * eraless calendar).
     */
    public static function isUndefined(mixed $value): bool
    {
        return $value === null || $value instanceof self;
    }

    /**
     * Removes entries whose value is a `JsUndefined` instance.
     *
     * Used by the transpiler to model JS object-literal semantics where
     * `{key: undefined}` is observably equivalent to `{}` in the
     * `Get(obj, key)` algorithm — both return `undefined`. PHP `null` values
     * (= JS `null`) are preserved.
     *
     * @param array<array-key, mixed> $bag
     * @return array<array-key, mixed>
     */
    public static function strip(array $bag): array
    {
        return array_filter($bag, static fn(mixed $v): bool => !$v instanceof self);
    }

    public function __toString(): string
    {
        return 'undefined';
    }
}
