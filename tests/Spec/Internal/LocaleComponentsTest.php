<?php

declare(strict_types=1);

namespace Calendrics\Tests\Spec\Internal;

use Calendrics\Spec\Internal\LocaleComponents;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Pins the date-only / time-only table {@see LocaleComponents} derives from the mode. */
final class LocaleComponentsTest extends TestCase
{
    /** @return iterable<string, array{LocaleComponents, bool, bool}> */
    public static function modes(): iterable
    {
        yield 'date' => [LocaleComponents::Date, true, false];
        yield 'yearMonth' => [LocaleComponents::YearMonth, true, false];
        yield 'monthDay' => [LocaleComponents::MonthDay, true, false];
        yield 'time' => [LocaleComponents::Time, false, true];
        yield 'dateTime' => [LocaleComponents::DateTime, false, false];
    }

    #[DataProvider('modes')]
    public function testDerivesDateOnlyAndTimeOnly(LocaleComponents $mode, bool $isDateOnly, bool $isTimeOnly): void
    {
        static::assertSame($isDateOnly, $mode->isDateOnly());
        static::assertSame($isTimeOnly, $mode->isTimeOnly());
    }
}
