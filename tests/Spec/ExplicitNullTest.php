<?php

declare(strict_types=1);

namespace Calendrics\Tests\Spec;

use Calendrics\Exception\RangeError;
use Calendrics\Exception\TypeError;
use Calendrics\Spec\Duration;
use Calendrics\Spec\Now;
use Calendrics\Spec\PlainDate;
use Calendrics\Spec\PlainDateTime;
use Calendrics\Spec\PlainTime;
use Calendrics\Spec\PlainYearMonth;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An explicit `null` where the transpiled corpus can only omit the value.
 *
 * `{ key: undefined }` means "key present, value absent" in JS; the transpiler drops
 * such keys entirely, because an absent PHP array key is the only faithful spelling of
 * that. Nothing then remains to write `null` into a bag, so the arms below — which the
 * spec layer keeps distinct from an absent key — are unreachable from test262 even
 * though every one of them is reachable from PHP.
 *
 * Options and property-bag fields treat the value differently, and deliberately so:
 * in an options bag `null` stands for JS `null`, which
 * GetTemporalRelativeToOption rejects as neither a String nor an Object, whereas an
 * absent key is JS `undefined` and means "no anchor". A property-bag field rejects
 * `null` outright rather than following JS's ToNumber(null) = 0.
 */
final class ExplicitNullTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Arguments
    // -------------------------------------------------------------------------

    public function testNowRejectsANullTimeZone(): void
    {
        // Omitting the argument falls back to the system zone; passing null does not.
        $this->expectException(TypeError::class);

        Now::plainDateISO(null);
    }

    public function testToPlainDateTimeRejectsANullTime(): void
    {
        $this->expectException(TypeError::class);

        new PlainDate(2024, 1, 1)->toPlainDateTime(null);
    }

    public function testToZonedDateTimeRejectsANullPlainTime(): void
    {
        $this->expectException(TypeError::class);

        new PlainDate(2024, 1, 1)->toZonedDateTime(['timeZone' => 'UTC', 'plainTime' => null]);
    }

    // -------------------------------------------------------------------------
    // Property-bag fields
    // -------------------------------------------------------------------------

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function plainDateBagsWithANullField(): iterable
    {
        yield 'year' => [['year' => null, 'month' => 1, 'day' => 1]];
        yield 'month' => [['year' => 2024, 'month' => null, 'day' => 1]];
        yield 'day' => [['year' => 2024, 'month' => 1, 'day' => null]];
    }

    /**
     * @param array<string, mixed> $bag
     *
     * PlainDate reports this as a RangeError where PlainDateTime and PlainYearMonth
     * report a TypeError for the same bag; the split is observed, not designed.
     */
    #[DataProvider('plainDateBagsWithANullField')]
    public function testPlainDateRejectsANullField(array $bag): void
    {
        $this->expectException(RangeError::class);

        PlainDate::from($bag);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function plainDateTimeBagsWithANullField(): iterable
    {
        yield 'year' => [['year' => null, 'month' => 1, 'day' => 1]];
        yield 'month' => [['year' => 2024, 'month' => null, 'day' => 1]];
        yield 'day' => [['year' => 2024, 'month' => 1, 'day' => null]];
    }

    /** @param array<string, mixed> $bag */
    #[DataProvider('plainDateTimeBagsWithANullField')]
    public function testPlainDateTimeRejectsANullDateField(array $bag): void
    {
        $this->expectException(TypeError::class);

        PlainDateTime::from($bag);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function plainYearMonthBagsWithANullField(): iterable
    {
        yield 'year' => [['year' => null, 'month' => 5]];
        yield 'month' => [['year' => 2024, 'month' => null]];
    }

    /** @param array<string, mixed> $bag */
    #[DataProvider('plainYearMonthBagsWithANullField')]
    public function testPlainYearMonthRejectsANullField(array $bag): void
    {
        $this->expectException(TypeError::class);

        PlainYearMonth::from($bag);
    }

    public function testPlainTimeRejectsANullField(): void
    {
        // An absent minute defaults to 0; an explicit null does not.
        $this->expectException(RangeError::class);

        PlainTime::from(['hour' => 12, 'minute' => null]);
    }

    // -------------------------------------------------------------------------
    // The relativeTo option
    // -------------------------------------------------------------------------

    public function testANullRelativeToIsIgnoredWhenNoAnchorIsNeeded(): void
    {
        $comparison = Duration::compare(Duration::from('P1D'), Duration::from('P1D'), ['relativeTo' => null]);

        static::assertSame(0, $comparison);
    }

    public function testTotalRejectsANullRelativeToWhenAnAnchorIsNeeded(): void
    {
        // The same call with relativeTo absent is a RangeError: null is a wrong-typed
        // anchor, not a missing one.
        $this->expectException(TypeError::class);

        Duration::from('P1Y')->total(['unit' => 'hours', 'relativeTo' => null]);
    }

    public function testTotalRejectsARelativeToThatIsNeitherStringNorBag(): void
    {
        $this->expectException(TypeError::class);

        Duration::from('P1D')->total(['unit' => 'hours', 'relativeTo' => 42]);
    }

    public function testRoundRejectsANullRelativeToWhenAnAnchorIsNeeded(): void
    {
        $this->expectException(TypeError::class);

        Duration::from('P1Y')->round(['smallestUnit' => 'years', 'relativeTo' => null]);
    }
}
