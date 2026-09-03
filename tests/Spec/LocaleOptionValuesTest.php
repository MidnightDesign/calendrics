<?php

declare(strict_types=1);

namespace Calendrics\Tests\Spec;

use Calendrics\Exception\RangeError;
use Calendrics\Spec\Internal\IntlFormatter;
use Calendrics\Spec\PlainDate;
use Calendrics\Spec\ZonedDateTime;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Covers ECMA-402 GetOption's value check on the `toLocaleString()` options that
 * have a fixed value set.
 *
 * The porcelain surface spells these options as enums, so only the spec layer can
 * be handed a value outside the set — which is why these cases live here and not
 * alongside the rest of the formatting tests.
 */
final class LocaleOptionValuesTest extends TestCase
{
    private const string LOCALE = 'en-US';

    /**
     * Every keyword-valued option of ECMA-402's CreateDateTimeFormat: its value set,
     * and a value outside it. Each rejected value is a real keyword borrowed from a
     * neighboring option — the mistake someone actually makes.
     *
     * @return array<string, array{allowed: list<string>, rejected: string}>
     */
    private static function keywordOptions(): array
    {
        $textWidths = ['narrow', 'short', 'long'];
        $numberWidths = ['numeric', '2-digit'];
        $formatStyles = ['full', 'long', 'medium', 'short'];

        return [
            'hourCycle' => ['allowed' => ['h11', 'h12', 'h23', 'h24'], 'rejected' => 'h13'],
            'weekday' => ['allowed' => $textWidths, 'rejected' => 'wide'],
            'era' => ['allowed' => $textWidths, 'rejected' => 'wide'],
            'year' => ['allowed' => $numberWidths, 'rejected' => 'long'],
            'month' => ['allowed' => ['numeric', '2-digit', 'narrow', 'short', 'long'], 'rejected' => 'wide'],
            'day' => ['allowed' => $numberWidths, 'rejected' => 'long'],
            'dayPeriod' => ['allowed' => $textWidths, 'rejected' => 'wide'],
            'hour' => ['allowed' => $numberWidths, 'rejected' => 'long'],
            'minute' => ['allowed' => $numberWidths, 'rejected' => 'long'],
            'second' => ['allowed' => $numberWidths, 'rejected' => 'long'],
            'timeZoneName' => [
                'allowed' => ['short', 'long', 'shortOffset', 'longOffset', 'shortGeneric', 'longGeneric'],
                'rejected' => 'offset',
            ],
            'dateStyle' => ['allowed' => $formatStyles, 'rejected' => 'narrow'],
            'timeStyle' => ['allowed' => $formatStyles, 'rejected' => 'narrow'],
        ];
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function optionValueSets(): iterable
    {
        foreach (self::keywordOptions() as $option => $values) {
            yield $option => [$option, $values['allowed']];
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function rejectedOptionValues(): iterable
    {
        foreach (self::keywordOptions() as $option => $values) {
            yield $option => [$option, $values['rejected']];
        }
    }

    /** @return iterable<string, array{string}> */
    public static function optionNames(): iterable
    {
        foreach (array_keys(self::keywordOptions()) as $option) {
            yield $option => [$option];
        }
    }

    /** @param list<string> $allowed */
    #[DataProvider('optionValueSets')]
    public function testEveryValueInTheSetIsAccepted(string $option, array $allowed): void
    {
        foreach ($allowed as $value) {
            static::assertNotSame('', self::zoned()->toLocaleString(self::LOCALE, [$option => $value]));
        }
    }

    #[DataProvider('rejectedOptionValues')]
    public function testAValueOutsideTheSetIsRejected(string $option, string $rejected): void
    {
        $this->expectException(RangeError::class);
        self::zoned()->toLocaleString(self::LOCALE, [$option => $rejected]);
    }

    /** TC39's `undefined` reaches PHP as `null`, which every option bag reads as absent. */
    #[DataProvider('optionNames')]
    public function testAnAbsentOptionIsNotRejected(string $option): void
    {
        static::assertNotSame('', self::zoned()->toLocaleString(self::LOCALE, [$option => null]));
    }

    /**
     * GetOption stringifies before it compares, and nothing a number, a boolean or a
     * bare object stringifies to is ever a keyword.
     *
     * A JS array is deliberately absent: `ToString(['long'])` is `'long'`, a member of
     * the set, so the answer there turns on how faithfully a PHP array stands in for a
     * JS one — a question this suite does not settle.
     *
     * @return iterable<string, array{mixed}>
     */
    public static function nonStringValues(): iterable
    {
        yield 'int' => [2];
        yield 'float' => [2.0];
        yield 'true' => [true];
        yield 'false' => [false];
        yield 'object' => [new \stdClass()];
    }

    #[DataProvider('nonStringValues')]
    public function testAValueThatIsNotAStringIsRejected(mixed $value): void
    {
        $this->expectException(RangeError::class);
        self::zoned()->toLocaleString(self::LOCALE, ['month' => $value]);
    }

    /**
     * CreateDateTimeFormat reads the component options before it reaches the
     * dateStyle/timeStyle conflict checks, so an invalid component value is reported
     * even when the call would also have been rejected for mixing the two.
     */
    public function testAnInvalidValueIsReportedBeforeTheStyleConflict(): void
    {
        $this->expectException(RangeError::class);
        PlainDate::from('2020-06-15')->toLocaleString(self::LOCALE, ['weekday' => 'wide', 'dateStyle' => 'full']);
    }

    /** The same precedence over the TypeError a date-only type raises for timeStyle. */
    public function testAnInvalidValueIsReportedBeforeTheInapplicableStyle(): void
    {
        $this->expectException(RangeError::class);
        PlainDate::from('2020-06-15')->toLocaleString(self::LOCALE, ['weekday' => 'wide', 'timeStyle' => 'full']);
    }

    /**
     * Every option `toLocaleString()` reads is either keyword-valued — and so listed
     * above — or one of the four whose values have no fixed set. A new name in neither
     * group has reached the formatter without anyone deciding how it is checked.
     */
    public function testEveryRecognizedOptionIsAccountedFor(): void
    {
        $noFixedValueSet = ['calendar', 'timeZone', 'hour12', 'fractionalSecondDigits'];

        static::assertSame(
            [],
            array_values(array_diff(IntlFormatter::OPTION_NAMES, array_keys(self::keywordOptions()), $noFixedValueSet)),
        );
    }

    private static function zoned(): ZonedDateTime
    {
        return ZonedDateTime::from('2020-06-15T21:30:45-04:00[America/New_York]');
    }
}
