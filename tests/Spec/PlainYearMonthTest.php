<?php

declare(strict_types=1);

namespace Calendrics\Tests\Spec;

use Calendrics\Exception\RangeError;
use Calendrics\Spec\PlainYearMonth;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PlainYearMonthTest extends TestCase
{
    private PlainYearMonth $earlier;

    private PlainYearMonth $later;

    /**
     * @return iterable<string, array{array<string, string>, string, string}>
     */
    public static function differenceUnitProvider(): iterable
    {
        yield 'auto largestUnit, year smallestUnit' => [
            ['largestUnit' => 'auto', 'smallestUnit' => 'year'],
            'P4Y',
            '-P4Y',
        ];
        yield 'auto largestUnit, plural years smallestUnit' => [
            ['largestUnit' => 'auto', 'smallestUnit' => 'years'],
            'P4Y',
            '-P4Y',
        ];
        yield 'auto largestUnit, month smallestUnit' => [
            ['largestUnit' => 'auto', 'smallestUnit' => 'month'],
            'P4Y5M',
            '-P4Y5M',
        ];
        yield 'auto largestUnit, default smallestUnit' => [
            ['largestUnit' => 'auto'],
            'P4Y5M',
            '-P4Y5M',
        ];
        yield 'year largestUnit, year smallestUnit' => [
            ['largestUnit' => 'year', 'smallestUnit' => 'year'],
            'P4Y',
            '-P4Y',
        ];
        yield 'month largestUnit, month smallestUnit' => [
            ['largestUnit' => 'month', 'smallestUnit' => 'month'],
            'P53M',
            '-P53M',
        ];
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function smallestLargerThanLargestProvider(): iterable
    {
        yield 'month largestUnit, year smallestUnit' => [['largestUnit' => 'month', 'smallestUnit' => 'year']];
        yield 'plural months largestUnit, plural years smallestUnit' => [
            ['largestUnit' => 'months', 'smallestUnit' => 'years'],
        ];
    }

    #[Override]
    protected function setUp(): void
    {
        $this->earlier = PlainYearMonth::from('2020-01');
        $this->later = PlainYearMonth::from('2024-06');
    }

    /**
     * @param array<string, string> $options
     */
    #[DataProvider('differenceUnitProvider')]
    public function testUntilAndSinceResolveUnitOptions(
        array $options,
        string $expectedUntil,
        string $expectedSince,
    ): void {
        static::assertSame($expectedUntil, $this->earlier->until($this->later, $options)->toString(), 'until');
        static::assertSame($expectedSince, $this->earlier->since($this->later, $options)->toString(), 'since');
    }

    /**
     * @param array<string, string> $options
     */
    #[DataProvider('smallestLargerThanLargestProvider')]
    public function testUntilRejectsSmallestUnitLargerThanLargestUnit(array $options): void
    {
        $this->expectException(RangeError::class);

        $this->earlier->until($this->later, $options);
    }

    /**
     * @param array<string, string> $options
     */
    #[DataProvider('smallestLargerThanLargestProvider')]
    public function testSinceRejectsSmallestUnitLargerThanLargestUnit(array $options): void
    {
        $this->expectException(RangeError::class);

        $this->earlier->since($this->later, $options);
    }
}
