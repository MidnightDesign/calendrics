<?php

declare(strict_types=1);

namespace Temporal\Tests\Spec;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Temporal\Spec\Instant;

/**
 * halfEven ties on a sub-second increment.
 *
 * The epoch is held as (seconds, sub-second nanoseconds), and a sub-second
 * increment is applied to the remainder alone. The tie rule still belongs to the
 * combined value: halfEven breaks on the parity of the floor multiple of the whole
 * epoch, and the seconds contribute to it whenever 10^9 / increment is odd.
 */
final class InstantRoundTest extends TestCase
{
    /** @return iterable<string, array{int, int}> */
    public static function halfEvenTieCases(): iterable
    {
        // 8 ms fits 125 times into a second — an odd count, so the parity of the
        // whole second decides which way the tie at 4 ms goes.
        yield 'odd second expands' => [3_004_000_000, 3_008_000_000];
        yield 'even second contracts' => [4_004_000_000, 4_000_000_000];
    }

    #[DataProvider('halfEvenTieCases')]
    public function testHalfEvenTieBreaksOnTheWholeEpochNotTheRemainder(int $epochNs, int $expected): void
    {
        $rounded = new Instant($epochNs)->round([
            'smallestUnit' => 'milliseconds',
            'roundingIncrement' => 8,
            'roundingMode' => 'halfEven',
        ]);

        static::assertSame($expected, $rounded->epochNanoseconds);
    }
}
