<?php

declare(strict_types=1);

namespace Calendrics\Tests\Test262;

use Calendrics\Spec\Internal\Options;

/**
 * Test-harness stand-in for the ECMA-402 `Intl.Locale` object, scoped to what the
 * intl402 Temporal fixtures exercise: a non-Temporal object that carries a
 * calendar, which Temporal must refuse wherever it accepts a calendar ID.
 *
 * The calendar is exposed as a public property so that a spec layer which reads
 * calendar-like properties off arbitrary objects fails those fixtures instead of
 * slipping past them.
 *
 * @psalm-api used by dynamically-required test262 scripts in tests/Test262/scripts/
 */
final readonly class IntlLocale
{
    public ?string $calendar;

    /**
     * @param array<array-key, mixed>|object $options a JS options bag, transpiled as an array or an object
     */
    public function __construct(
        public string $tag,
        array|object $options = [],
    ) {
        $this->calendar = self::stringOrNull(Options::bagGet($options, 'calendar'));
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
