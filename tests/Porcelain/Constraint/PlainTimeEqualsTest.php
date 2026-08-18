<?php

declare(strict_types=1);

namespace Calendrics\Tests\Porcelain\Constraint;

use Calendrics\PlainTime;
use PHPUnit\Framework\ExpectationFailedException;
use PHPUnit\Framework\TestCase;

final class PlainTimeEqualsTest extends TestCase
{
    public function testMatchesIdenticalPlainTime(): void
    {
        $constraint = new PlainTimeEquals(13, 45, 30, 123, 456, 789);
        $time = new PlainTime(13, 45, 30, 123, 456, 789);

        static::assertTrue($constraint->evaluate($time, '', true));
    }

    public function testMatchesAllZeros(): void
    {
        $constraint = new PlainTimeEquals(0, 0, 0, 0, 0, 0);
        $time = new PlainTime();

        static::assertTrue($constraint->evaluate($time, '', true));
    }

    public function testDoesNotMatchDifferentHour(): void
    {
        $constraint = new PlainTimeEquals(10, 0, 0, 0, 0, 0);
        $time = new PlainTime(11, 0, 0, 0, 0, 0);

        static::assertFalse($constraint->evaluate($time, '', true));
    }

    public function testDoesNotMatchDifferentNanosecond(): void
    {
        $constraint = new PlainTimeEquals(13, 45, 30, 123, 456, 789);
        $time = new PlainTime(13, 45, 30, 123, 456, 0);

        static::assertFalse($constraint->evaluate($time, '', true));
    }

    public function testDoesNotMatchNonPlainTimeValue(): void
    {
        $constraint = new PlainTimeEquals(0, 0, 0, 0, 0, 0);

        static::assertFalse($constraint->evaluate('not a PlainTime', '', true));
    }

    public function testToStringFormatsAsTimeString(): void
    {
        $constraint = new PlainTimeEquals(13, 45, 30, 123, 456, 789);

        static::assertSame('is PlainTime 13:45:30.123456789', $constraint->toString());
    }

    public function testToStringPadsZeros(): void
    {
        $constraint = new PlainTimeEquals(1, 2, 3, 4, 5, 6);

        static::assertSame('is PlainTime 01:02:03.004005006', $constraint->toString());
    }

    public function testFailureMessageShowsMismatchedFields(): void
    {
        $constraint = new PlainTimeEquals(10, 30, 45, 0, 0, 0);
        $time = new PlainTime(10, 31, 0, 0, 0, 0);

        try {
            $constraint->evaluate($time);
            static::fail('Expected ExpectationFailedException');
        } catch (ExpectationFailedException $e) {
            $message = $e->getMessage();
            static::assertStringContainsString('minute: expected 30, actual 31', $message);
            static::assertStringContainsString('second: expected 45, actual 0', $message);
            static::assertStringNotContainsString('hour:', $message);
        }
    }

    public function testFailureMessageIncludesCustomMessage(): void
    {
        $constraint = new PlainTimeEquals(10, 0, 0, 0, 0, 0);
        $time = new PlainTime(11, 0, 0, 0, 0, 0);

        try {
            $constraint->evaluate($time, 'custom context');
            static::fail('Expected ExpectationFailedException');
        } catch (ExpectationFailedException $e) {
            static::assertStringContainsString('custom context', $e->getMessage());
        }
    }
}
