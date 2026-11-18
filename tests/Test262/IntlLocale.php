<?php

declare(strict_types=1);

namespace Calendrics\Tests\Test262;

/**
 * Test-harness stand-in for the ECMA-402 `Intl.Locale` object. The intl402
 * Temporal fixtures only pass it where Temporal must refuse a non-Temporal object,
 * so it carries its constructor arguments and nothing more.
 *
 * @psalm-api used by dynamically-required test262 scripts in tests/Test262/scripts/
 */
final readonly class IntlLocale
{
    public function __construct(
        public string $tag,
        public mixed $options = null,
    ) {}
}
