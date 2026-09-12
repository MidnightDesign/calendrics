<?php

declare(strict_types=1);

namespace Calendrics\Tests\Porcelain;

use Calendrics\Duration;
use Calendrics\PlainTime;
use Calendrics\Tests\Porcelain\Constraint\DurationEquals;
use Calendrics\Tests\Porcelain\Constraint\PlainTimeEquals;
use PHPUnit\Framework\TestCase;

abstract class CalendricsTestCase extends TestCase
{
    public const int YEAR_MIN = -271_821;
    public const int YEAR_MAX = 275_760;

    protected function assertPlainTimeIs(
        int $h,
        int $min,
        int $sec,
        int $ms,
        int $us,
        int $ns,
        PlainTime $time,
        string $message = '',
    ): void {
        static::assertThat($time, new PlainTimeEquals($h, $min, $sec, $ms, $us, $ns), $message);
    }

    protected function assertDurationIs(
        int $years,
        int $months,
        int $weeks,
        int $days,
        int $hours,
        int $minutes,
        int $seconds,
        int $ms,
        int $us,
        int $ns,
        Duration $d,
        string $message = '',
    ): void {
        static::assertThat(
            $d,
            new DurationEquals($years, $months, $weeks, $days, $hours, $minutes, $seconds, $ms, $us, $ns),
            $message,
        );
    }
}
