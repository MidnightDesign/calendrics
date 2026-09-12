<?php

declare(strict_types=1);

namespace Calendrics\Spec\Internal;

use Calendrics\Spec\PlainDate;
use Calendrics\Spec\PlainDateTime;
use Calendrics\Spec\PlainMonthDay;
use Calendrics\Spec\PlainTime;
use Calendrics\Spec\PlainYearMonth;

/**
 * Canonical formatting projection for the zoneless Temporal types.
 *
 * @internal
 */
final readonly class PlainLocaleFormat
{
    private function __construct(
        public LocaleComponentMode $components,
        public ?string $calendarId,
        public int $epochSec,
        public int $subNs,
    ) {}

    public static function from(PlainLocaleFormattable $value): self
    {
        [$components, $calendarId, $isoYear, $isoMonth, $isoDay] = match (true) {
            $value instanceof PlainDate => [
                LocaleComponentMode::Date,
                $value->calendarId,
                $value->isoYear,
                $value->isoMonth,
                $value->isoDay,
            ],
            $value instanceof PlainDateTime => [
                LocaleComponentMode::DateTime,
                $value->calendarId,
                $value->isoYear,
                $value->isoMonth,
                $value->isoDay,
            ],
            $value instanceof PlainYearMonth => [
                LocaleComponentMode::YearMonth,
                $value->calendarId,
                $value->isoYear,
                $value->isoMonth,
                $value->referenceISODay,
            ],
            $value instanceof PlainMonthDay => [
                LocaleComponentMode::MonthDay,
                $value->calendarId,
                $value->referenceISOYear,
                $value->isoMonth,
                $value->isoDay,
            ],
            $value instanceof PlainTime => [LocaleComponentMode::Time, null, 1970, 1, 1],
            default => throw new \LogicException(sprintf('Unsupported plain locale type %s.', $value::class)),
        };

        $epochSec = AnchorMath::isoDateToEpochDays($isoYear, $isoMonth, $isoDay) * 86_400;
        if (!$value instanceof PlainDateTime && !$value instanceof PlainTime) {
            return new self($components, $calendarId, $epochSec, 0);
        }

        return new self(
            $components,
            $calendarId,
            $epochSec + ($value->hour * 3_600) + ($value->minute * 60) + $value->second,
            ($value->millisecond * EpochLimits::NS_PER_MILLISECOND)
            + ($value->microsecond * EpochLimits::NS_PER_MICROSECOND)
            + $value->nanosecond,
        );
    }

    public function isDateOnly(): bool
    {
        return $this->components->isDateOnly();
    }

    public function isTimeOnly(): bool
    {
        return $this->components->isTimeOnly();
    }
}
