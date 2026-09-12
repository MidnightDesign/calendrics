<?php

declare(strict_types=1);

namespace Calendrics\Tests\Test262;

/**
 * PHP counterpart of the test262 harness's `Test262Error`.
 *
 * Lives only in transpiled test262 fixtures and the helpers in this namespace.
 * The transpiler maps the JS identifier `Test262Error` to this class so that
 * `assert.throws(Test262Error, …)` lowers to `Assert::throws(Test262Error::class, …)`,
 * and "positive-probe" property-bag getters whose body is `throw new Test262Error()`
 * lower to an anonymous class with a throwing `__get` (see the transpiler's
 * positiveProbeGetterBag). When the spec operation genuinely reads the probed
 * property, this exception propagates and the surrounding assertion catches it.
 *
 * @psalm-api used by transpiled test262 scripts
 */
final class Test262Error extends \Exception {}
