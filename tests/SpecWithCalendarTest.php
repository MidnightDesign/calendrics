<?php

declare(strict_types=1);

namespace Calendrics\Tests;

use Calendrics\Exception\TypeError;
use Calendrics\Spec\PlainDate;
use Calendrics\Spec\PlainDateTime;
use Calendrics\Spec\ZonedDateTime;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SpecWithCalendarTest extends TestCase
{
    #[DataProvider('withCalendarReceivers')]
    public function testWithCalendarRejectsUnbrandedObjectWithCalendarId(PlainDate|PlainDateTime|ZonedDateTime $receiver): void
    {
        $this->expectException(TypeError::class);

        $receiver->withCalendar((object) ['calendarId' => 'hebrew']);
    }

    /**
     * @return iterable<string, array{PlainDate|PlainDateTime|ZonedDateTime}>
     */
    public static function withCalendarReceivers(): iterable
    {
        yield 'PlainDate' => [new PlainDate(2024, 1, 1)];
        yield 'PlainDateTime' => [new PlainDateTime(2024, 1, 1)];
        yield 'ZonedDateTime' => [new ZonedDateTime(0, 'UTC')];
    }
}
