<?php

declare(strict_types=1);

namespace Calendrics\Tests\Test262;

use Calendrics\Spec\Instant;
use Calendrics\Spec\ZonedDateTime;

/**
 * Exact boundary between Temporal epoch values and the harness's int64 BigInts.
 *
 * @psalm-api used by dynamically-required test262 scripts
 */
final class JsEpoch
{
    /**
     * Read without allowing a clamped nanosecond field into arithmetic or an alias.
     *
     * @psalm-api used by generated fixtures
     */
    public static function read(mixed $receiver): mixed
    {
        if ($receiver instanceof Instant || $receiver instanceof ZonedDateTime) {
            $scalar = $receiver->epochNanoseconds;
            $seconds = intdiv($scalar, num2: 1_000_000_000);
            $remainder = $scalar % 1_000_000_000;
            if ($remainder < 0) {
                $seconds--;
                $remainder += 1_000_000_000;
            }
            if ($receiver->epochParts() !== [$seconds, $remainder]) {
                Assert::incomplete('epochNanoseconds outside int64 requires an exact comparison of epoch properties');
            }
            return $scalar;
        }
        if ($receiver === null || $receiver instanceof JsUndefined) {
            throw new \TypeError('Cannot read epochNanoseconds of null or undefined');
        }
        if (is_array($receiver)) {
            return $receiver['epochNanoseconds'] ?? null;
        }
        if (is_object($receiver)) {
            // A direct read invokes user-defined getters and preserves their errors.
            if (property_exists($receiver, 'epochNanoseconds')) {
                return $receiver->epochNanoseconds;
            }
            if (method_exists($receiver, '__get')) {
                return $receiver->__get('epochNanoseconds');
            }
            return null;
        }
        Assert::incomplete('epochNanoseconds property access on a primitive is not supported');
    }

    /**
     * Normalize a comparison operand before evaluating the following operand.
     * Runtime dispatch supports factories, aliases and reassigned variables without
     * assuming anything about a JS binding's name or the rest of its fixture.
     *
     * @return array{int, int}
     * @psalm-api used by generated fixtures
     */
    public static function comparisonOperand(mixed $receiver): array
    {
        if ($receiver instanceof Instant || $receiver instanceof ZonedDateTime) {
            return $receiver->epochParts();
        }
        self::read($receiver);
        Assert::incomplete('Exact epoch comparison requires Temporal Instant or ZonedDateTime receivers');
    }
}
