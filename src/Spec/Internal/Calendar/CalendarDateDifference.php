<?php

declare(strict_types=1);

namespace Calendrics\Spec\Internal\Calendar;

use Calendrics\Spec\Internal\CalendarMath;

/**
 * Calendar difference using the same constrained addition and projections as callers.
 *
 * @phpstan-require-implements CalendarProtocol
 * @psalm-require-implements CalendarProtocol
 * @internal
 */
trait CalendarDateDifference
{
    abstract private function hasLeapMonths(): bool;

    abstract private function totalMonthsInYearsDirectional(
        int $isoY,
        int $isoM,
        int $isoD,
        int $yearCount,
        int $sign,
    ): int;

    /**
     * @param 'month'|'year' $largestUnit
     * @return array{int, int, int, int}
     */
    #[\Override]
    public function dateUntil(
        int $isoY1,
        int $isoM1,
        int $isoD1,
        int $isoY2,
        int $isoM2,
        int $isoD2,
        string $largestUnit,
        bool $receiverIsLater = false,
    ): array {
        // TC39 CalendarDateUntil: iterate from date1 toward date2 WITHOUT
        // swapping. The direction (sign) determines whether we add positive or
        // negative year/month increments. This is essential for leap-month
        // calendars where forward and backward traversal cross different months.

        $jdn1 = CalendarMath::toJulianDay($isoY1, $isoM1, $isoD1);
        $jdn2 = CalendarMath::toJulianDay($isoY2, $isoM2, $isoD2);

        if ($jdn1 === $jdn2) {
            return [0, 0, 0, 0];
        }

        $sign = $jdn2 > $jdn1 ? 1 : -1;

        // Read calendar fields.
        $calY1 = $this->year($isoY1, $isoM1, $isoD1);

        $calY2 = $this->year($isoY2, $isoM2, $isoD2);

        $years = 0;
        $months = 0;

        if ($largestUnit === 'year') {
            // Find years: start from a conservative estimate and increment in
            // the sign direction until one more would overshoot.
            $yearDiff = abs($calY2 - $calY1);
            $years = max(0, $yearDiff - 1);

            while ($this->trialDateAddDoesNotSurpass($isoY1, $isoM1, $isoD1, $sign * ($years + 1), 0, $jdn2, $sign)) {
                $years++;
            }

            // Find months within remaining partial year, starting from 0.
            while ($this->trialDateAddDoesNotSurpass(
                $isoY1,
                $isoM1,
                $isoD1,
                $sign * $years,
                $sign * ($months + 1),
                $jdn2,
                $sign,
            )) {
                $months++;
            }
        }

        if ($largestUnit === 'month') {
            // Find total months: use a conservative estimate, then increment.
            $yearDiff = abs($calY2 - $calY1);
            if ($yearDiff > 1) {
                // For large spans, estimate conservatively: sum months across
                // intermediate years (excluding start and end partial years),
                // then back off generously to ensure we don't overshoot.
                $monthEstimate = $this->totalMonthsInYearsDirectional(
                    $isoY1,
                    $isoM1,
                    $isoD1,
                    max(0, $yearDiff - 1),
                    $sign,
                );
                $months = max(0, $monthEstimate - 14);
            }

            while ($this->trialDateAddDoesNotSurpass($isoY1, $isoM1, $isoD1, 0, $sign * ($months + 1), $jdn2, $sign)) {
                $months++;
            }
        }

        // Remaining days: add the found years+months from date1, measure JDN to date2.
        [$intIsoY, $intIsoM, $intIsoD] = $this->dateAdd(
            $isoY1,
            $isoM1,
            $isoD1,
            $sign * $years,
            $sign * $months,
            0,
            0,
            'constrain',
        );
        $days = $jdn2 - CalendarMath::toJulianDay($intIsoY, $intIsoM, $intIsoD);

        return [$sign * $years, $sign * $months, 0, $days];
    }

    private function trialDateAddDoesNotSurpass(
        int $isoY1,
        int $isoM1,
        int $isoD1,
        int $years,
        int $months,
        int $targetJdn,
        int $sign,
    ): bool {
        [$tY, $tM, $tD] = $this->dateAdd($isoY1, $isoM1, $isoD1, $years, $months, 0, 0, 'constrain');
        $trialJdn = CalendarMath::toJulianDay($tY, $tM, $tD);

        if ($months === 0) {
            // Year-only trial: check if the calendar day was constrained
            // (e.g. day 30 -> day 29 in a shorter month). If so, the trial
            // didn't preserve the exact date, so use strict inequality.
            $origCalDay = $this->day($isoY1, $isoM1, $isoD1);
            $trialCalDay = $this->day($tY, $tM, $tD);
            $dayConstrained = $trialCalDay < $origCalDay;

            // For leap-month calendars, also check monthCode constraining.
            $monthConstrained = false;
            $constrainedOrdEarlier = false;
            if ($this->hasLeapMonths()) {
                $origMonthCode = $this->monthCode($isoY1, $isoM1, $isoD1);
                $trialMonthCode = $this->monthCode($tY, $tM, $tD);
                if ($origMonthCode !== $trialMonthCode) {
                    $monthConstrained = true;
                    $origOrd = $this->month($isoY1, $isoM1, $isoD1);
                    $trialOrd = $this->month($tY, $tM, $tD);
                    $constrainedOrdEarlier = $trialOrd < $origOrd;
                }
            }

            if ($monthConstrained) {
                // When day is also constrained, always use strict inequality
                // because the position shifted both in month and day.
                if ($dayConstrained) {
                    return $sign > 0 ? $trialJdn < $targetJdn : $trialJdn > $targetJdn;
                }
                if ($sign > 0) {
                    // Forward: ordinal decreased -> use strict <.
                    // Ordinal same/increased -> use <=.
                    return $constrainedOrdEarlier ? $trialJdn < $targetJdn : $trialJdn <= $targetJdn;
                }
                // Backward: ordinal increased/same -> use strict >.
                // Ordinal decreased -> use >=.
                return $constrainedOrdEarlier ? $trialJdn >= $targetJdn : $trialJdn > $targetJdn;
            }

            if ($dayConstrained) {
                // Day was constrained.
                // Forward: use strict < so a trial that landed early (day
                // clamped down) does not prematurely count as "within range".
                // Backward: use non-strict >= so a trial whose day was clamped
                // down (e.g. leap-year M13-day6 → common-year M13-day5) that
                // lands exactly ON the target still counts as a full year
                // difference, matching TC39 NonISODateUntil constrain semantics.
                return $sign > 0 ? $trialJdn < $targetJdn : $trialJdn >= $targetJdn;
            }

            return $sign > 0 ? $trialJdn <= $targetJdn : $trialJdn >= $targetJdn;
        }

        // Month trials: check if day was constrained and adjust.
        $origCalDay = $this->day($isoY1, $isoM1, $isoD1);
        $trialCalDay = $this->day($tY, $tM, $tD);

        if ($trialCalDay < $origCalDay) {
            // Day was constrained. Adjust the JDN to pretend the original day
            // was preserved, ensuring correct month counting at boundaries.
            $trialJdn += $origCalDay - $trialCalDay;
        }

        return $sign > 0 ? $trialJdn <= $targetJdn : $trialJdn >= $targetJdn;
    }
}
