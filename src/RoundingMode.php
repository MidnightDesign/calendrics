<?php

declare(strict_types=1);

namespace Calendrics;

use Calendrics\Exception\InvalidArgument;

/**
 * Rounding modes for Temporal arithmetic and formatting operations.
 *
 * This enum defines all 9 rounding modes from the TC39 Temporal specification.
 * PHP 8.4's built-in \RoundingMode covers 7 of 9 cases but is missing
 * HalfCeil and HalfFloor. Use {@see toPhpRoundingMode()} to convert when possible.
 */
enum RoundingMode: string
{
    /** Round toward positive infinity. */
    case Ceil = 'ceil';

    /** Round toward negative infinity. */
    case Floor = 'floor';

    /** Round away from zero. */
    case Expand = 'expand';

    /** Round toward zero (truncate). */
    case Trunc = 'trunc';

    /** Round half toward positive infinity. */
    case HalfCeil = 'halfCeil';

    /** Round half toward negative infinity. */
    case HalfFloor = 'halfFloor';

    /** Round half away from zero (standard mathematical rounding). */
    case HalfExpand = 'halfExpand';

    /** Round half toward zero. */
    case HalfTrunc = 'halfTrunc';

    /** Round half to the nearest even integer (banker's rounding). */
    case HalfEven = 'halfEven';

    /**
     * Convert to PHP's built-in \RoundingMode.
     *
     * @throws InvalidArgument for HalfCeil and HalfFloor, which have no PHP equivalent.
     * @psalm-api
     */
    public function toPhpRoundingMode(): \RoundingMode
    {
        return match ($this) {
            self::Ceil => \RoundingMode::PositiveInfinity,
            self::Floor => \RoundingMode::NegativeInfinity,
            self::Expand => \RoundingMode::AwayFromZero,
            self::Trunc => \RoundingMode::TowardsZero,
            self::HalfExpand => \RoundingMode::HalfAwayFromZero,
            self::HalfTrunc => \RoundingMode::HalfTowardsZero,
            self::HalfEven => \RoundingMode::HalfEven,
            self::HalfCeil, self::HalfFloor => throw new InvalidArgument(
                "{$this->name} has no PHP \\RoundingMode equivalent.",
            ),
        };
    }

    /**
     * Create from PHP's built-in \RoundingMode.
     *
     * @throws InvalidArgument for HalfOdd, which has no Temporal equivalent.
     * @psalm-api
     */
    public static function fromPhpRoundingMode(\RoundingMode $mode): self
    {
        return match ($mode) {
            \RoundingMode::PositiveInfinity => self::Ceil,
            \RoundingMode::NegativeInfinity => self::Floor,
            \RoundingMode::AwayFromZero => self::Expand,
            \RoundingMode::TowardsZero => self::Trunc,
            \RoundingMode::HalfAwayFromZero => self::HalfExpand,
            \RoundingMode::HalfTowardsZero => self::HalfTrunc,
            \RoundingMode::HalfEven => self::HalfEven,
            \RoundingMode::HalfOdd => throw new InvalidArgument('HalfOdd has no Temporal RoundingMode equivalent.'),
        };
    }
}
