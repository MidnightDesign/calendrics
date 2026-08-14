<?php

declare(strict_types=1);

namespace Temporal\Spec\Internal;

use Temporal\Exception\RangeError;
use Temporal\Exception\TypeError;
use Temporal\Spec\Duration;
use Temporal\Spec\Internal\Calendar\CalendarFactory;
use Temporal\Spec\ZonedDateTime;

/**
 * Moving a ZonedDateTime along the timeline: `add()`, `subtract()`, and `round()`.
 *
 * All three run into the same fact — that a local day is not reliably 86,400 seconds long
 * — and each has to answer it differently, which is why they sit together here rather than
 * beside the value type. Adding a month must preserve the wall-clock time across a DST
 * change; adding 24 hours must not; rounding to the nearest day must divide by the day's
 * real length. The comments on each method record which spec operation pins that choice.
 *
 * This class lives in `Temporal\Spec\Internal\` and is therefore not part of the public
 * BC contract. Signatures, behavior, and existence may change between any two releases.
 * External code must not depend on it.
 */
final class ZonedDateTimeArithmetic
{
    /**
     * Adds ($sign = 1) or subtracts ($sign = -1) a Duration.
     *
     * The two halves of a Duration move a zoned date-time differently, so TC39
     * AddZonedDateTime applies them in order and this follows: calendar units shift the
     * LOCAL date and are then re-resolved through the zone (adding a month keeps the
     * wall-clock time even across a DST change), while time units are added to the EPOCH
     * (adding 24 hours crosses a DST boundary and lands an hour off the wall clock).
     * Doing it in the other order, or in one step, gives the wrong answer on any day whose
     * length is not 24 hours.
     *
     * @param mixed $options
     */
    public static function addDuration(
        ZonedDateTime $zdt,
        int $sign,
        Duration $dur,
        mixed $options,
    ): ZonedDateTime {
        // A clamped epoch has no recoverable instant, so pure-time arithmetic on it cannot
        // produce an answer. The calendar-unit path is fine: it works from
        // localComponents(), which reads the local date rather than the lost instant, and
        // a blank Duration moves nothing at all.
        if ($zdt->isClampedEpoch()) {
            $isBlank =
                $dur->years === 0
                && $dur->months === 0
                && $dur->weeks === 0
                && $dur->days === 0
                && $dur->hours === 0
                && $dur->minutes === 0
                && $dur->seconds === 0
                && $dur->milliseconds === 0
                && $dur->microseconds === 0
                && $dur->nanoseconds === 0;
            $hasCalendar = $dur->years !== 0 || $dur->months !== 0 || $dur->weeks !== 0 || $dur->days !== 0;
            if (!$isBlank && !$hasCalendar) {
                throw new RangeError('ZonedDateTime arithmetic result is outside the representable range.');
            }
        }

        $overflow = Options::overflowFromBag($options);

        $years = $sign * (int) $dur->years;
        $months = $sign * (int) $dur->months;
        $weeks = $sign * (int) $dur->weeks;
        $days = $sign * (int) $dur->days;
        $hours = $sign * (int) $dur->hours;
        $minutes = $sign * (int) $dur->minutes;
        $seconds = $sign * (int) $dur->seconds;
        $ms = $sign * (int) $dur->milliseconds;
        $us = $sign * (int) $dur->microseconds;
        $ns = $sign * (int) $dur->nanoseconds;

        $hasCalendarUnits = $years !== 0 || $months !== 0 || $weeks !== 0 || $days !== 0;

        if ($hasCalendarUnits) {
            // Get local date/time, apply calendar units, then re-resolve to ZDT.
            $lc = $zdt->localComponents();

            // Use calendar protocol for non-ISO calendars.
            if ($zdt->calendarId !== 'iso8601') {
                $cal = CalendarFactory::get($zdt->calendarId);
                [$newYear, $newMonth, $newDay] = $cal->dateAdd(
                    $lc['year'],
                    $lc['month'],
                    $lc['day'],
                    $years,
                    $months,
                    $weeks,
                    $days,
                    $overflow,
                );
            } else {
                $newYear = $lc['year'] + $years;
                $newMonth = $lc['month'] + $months;

                // Normalize month into 1-12, carrying into year.
                if ($newMonth > 12) {
                    $newYear += intdiv(num1: $newMonth - 1, num2: 12);
                    $newMonth = (($newMonth - 1) % 12) + 1;
                } elseif ($newMonth < 1) {
                    $newYear += intdiv(num1: $newMonth - 12, num2: 12);
                    $newMonth = (((($newMonth - 1) % 12) + 12) % 12) + 1;
                }

                // Clamp or reject day.
                $newDay = $lc['day'];
                $maxDay = CalendarMath::calcDaysInMonth($newYear, $newMonth);
                if ($newDay > $maxDay) {
                    if ($overflow === 'constrain') {
                        $newDay = $maxDay;
                    } else {
                        throw new RangeError("Day {$newDay} is out of range for {$newYear}-{$newMonth}.");
                    }
                }

                // Add weeks and days via JDN.
                $totalDays = ($weeks * 7) + $days;
                $jdn = CalendarMath::toJulianDay($newYear, $newMonth, $newDay) + $totalDays;
                [$newYear, $newMonth, $newDay] = CalendarMath::fromJulianDay($jdn);
            }

            // TC39 AddZonedDateTime: first resolve the new local date+time to
            // an intermediate ZDT epoch, then add time units to the epoch.
            // This correctly handles DST day length differences.

            // Balance time units to nanoseconds.
            $timeNs =
                ($hours * 3_600_000_000_000)
                + ($minutes * 60_000_000_000)
                + ($seconds * EpochLimits::NS_PER_SECOND)
                + ($ms * EpochLimits::NS_PER_MILLISECOND)
                + ($us * EpochLimits::NS_PER_MICROSECOND)
                + $ns;

            if ($timeNs === 0) {
                // No time units: just resolve the new local date with original time.
                return ZonedDateTime::fromLocalParts(
                    $newYear,
                    $newMonth,
                    $newDay,
                    $lc['hour'],
                    $lc['minute'],
                    $lc['second'],
                    $lc['millisecond'],
                    $lc['microsecond'],
                    $lc['nanosecond'],
                    $zdt->timeZoneId,
                    $zdt->calendarId,
                    'compatible',
                );
            }

            // Step 1: Resolve new date + original time to intermediate epoch.
            $epochDays = CalendarMath::toJulianDay($newYear, $newMonth, $newDay) - 2_440_588;
            $wallSec = ($epochDays * 86_400) + ($lc['hour'] * 3600) + ($lc['minute'] * 60) + $lc['second'];
            $intermediateEpochSec = TimeZoneHelper::wallSecToEpochSec($wallSec, $zdt->resolvedTimeZoneId, 'compatible');
            $intermediateSubNs =
                ($lc['millisecond'] * EpochLimits::NS_PER_MILLISECOND)
                + ($lc['microsecond'] * EpochLimits::NS_PER_MICROSECOND)
                + $lc['nanosecond'];

            // Step 2: Add time nanoseconds to the epoch.
            $totalSubNs = $intermediateSubNs + $timeNs;
            $overflowSec = CalendarMath::floorDiv($totalSubNs, EpochLimits::NS_PER_SECOND);
            $resultSubNs = $totalSubNs - ($overflowSec * EpochLimits::NS_PER_SECOND);
            $resultEpochSec = $intermediateEpochSec + $overflowSec;

            return ZonedDateTime::fromEpochParts($resultEpochSec, $resultSubNs, $zdt->timeZoneId, $zdt->calendarId);
        }

        // Pure time units: balance to days + sub-day ns to avoid int64 overflow.
        // Step-by-step carry approach (same as PlainDateTime).
        $hDays = intdiv(num1: $hours, num2: 24);
        $hRem = $hours % 24;

        $totalMin = ($hRem * 60) + $minutes;
        $mDays = intdiv(num1: $totalMin, num2: 1_440);
        $mRem = $totalMin % 1_440;

        $totalSec = ($mRem * 60) + $seconds;
        $sDays = intdiv(num1: $totalSec, num2: 86_400);
        $sRem = $totalSec % 86_400;

        $totalMs = ($sRem * 1_000) + $ms;
        $msDays = intdiv(num1: $totalMs, num2: 86_400_000);
        $msRem = $totalMs % 86_400_000;

        $totalUs = ($msRem * 1_000) + $us;
        $usDays = intdiv(num1: $totalUs, num2: 86_400_000_000);
        $usRem = $totalUs % 86_400_000_000;

        $totalNsRem = ($usRem * 1_000) + $ns;
        $nsDays = intdiv(num1: $totalNsRem, num2: 86_400_000_000_000);
        $nsRem = $totalNsRem % 86_400_000_000_000;

        $totalDays = $hDays + $mDays + $sDays + $msDays + $usDays + $nsDays;

        // Convert days to epoch seconds and add the sub-day ns.
        [$epochSec, $subNsOrig] = $zdt->epochParts();

        $newEpochSec = $epochSec + ($totalDays * 86_400);
        $newSubNs = $subNsOrig + $nsRem;

        // Carry from sub-ns.
        if ($newSubNs >= EpochLimits::NS_PER_SECOND) {
            $carry = intdiv(num1: $newSubNs, num2: EpochLimits::NS_PER_SECOND);
            $newEpochSec += $carry;
            $newSubNs -= $carry * EpochLimits::NS_PER_SECOND;
        } elseif ($newSubNs < 0) {
            $carry = (int) ceil(-$newSubNs / EpochLimits::NS_PER_SECOND);
            $newEpochSec -= $carry;
            $newSubNs += $carry * EpochLimits::NS_PER_SECOND;
        }

        return ZonedDateTime::fromEpochParts($newEpochSec, $newSubNs, $zdt->timeZoneId, $zdt->calendarId);
    }

    /**
     * Rounds a ZonedDateTime to the given unit and increment.
     *
     * Rounding is always measured from local midnight rather than from the epoch, so an
     * increment that divides the day evenly lands on a wall-clock boundary even in a zone
     * whose offset is not a whole number of hours. For 'day' the divisor is the day's
     * ACTUAL length, which a DST transition makes 23 or 25 hours.
     *
     * @param string|array<array-key, mixed>|object $options string smallestUnit or array with keys:
     *   - smallestUnit (required): 'day'|'hour'|'minute'|'second'|'millisecond'|'microsecond'|'nanosecond'
     *   - roundingMode (default 'halfExpand')
     *   - roundingIncrement (default 1)
     */
    public static function round(ZonedDateTime $zdt, string|array|object $options): ZonedDateTime
    {
        if (is_string($options)) {
            $options = ['smallestUnit' => $options];
        } elseif (is_object($options)) {
            // TC39: if options is undefined, throw TypeError (required arg).
            if ($options instanceof \Stringable) {
                $str = (string) $options; // JsSymbol: throws; JsUndefined: returns 'undefined'
                if ($str === 'undefined') {
                    throw new TypeError('ZonedDateTime::round() requires a non-undefined options argument.');
                }
            }
            $options = Options::requireObject($options, ['roundingIncrement', 'roundingMode', 'smallestUnit']);
        }

        /** @var mixed $suRaw */
        $suRaw = $options['smallestUnit'] ?? null;
        if ($suRaw === null) {
            throw new RangeError('Temporal\\ZonedDateTime::round() requires smallestUnit.');
        }
        $suRaw = Options::coerceEnumOption($suRaw, 'smallestUnit');

        // [nsPerUnit, maxIncrement (next-unit size, or 1 for day)]
        $unitMap = [
            'day' => [86_400_000_000_000, 1],
            'days' => [86_400_000_000_000, 1],
            'hour' => [3_600_000_000_000, 24],
            'hours' => [3_600_000_000_000, 24],
            'minute' => [60_000_000_000, 60],
            'minutes' => [60_000_000_000, 60],
            'second' => [EpochLimits::NS_PER_SECOND, 60],
            'seconds' => [EpochLimits::NS_PER_SECOND, 60],
            'millisecond' => [EpochLimits::NS_PER_MILLISECOND, 1_000],
            'milliseconds' => [EpochLimits::NS_PER_MILLISECOND, 1_000],
            'microsecond' => [EpochLimits::NS_PER_MICROSECOND, 1_000],
            'microseconds' => [EpochLimits::NS_PER_MICROSECOND, 1_000],
            'nanosecond' => [1, 1_000],
            'nanoseconds' => [1, 1_000],
        ];
        if (!array_key_exists($suRaw, $unitMap)) {
            throw new RangeError("Invalid smallestUnit \"{$suRaw}\" for Temporal\\ZonedDateTime::round().");
        }
        [$nsPerUnit, $maxDivisor] = $unitMap[$suRaw];

        $roundingMode = 'halfExpand';
        if (array_key_exists('roundingMode', $options) && $options['roundingMode'] !== null) {
            $rmRaw = Options::coerceEnumOption($options['roundingMode'], 'roundingMode');
            $roundingMode = $rmRaw;
        }

        $increment = 1;
        if (array_key_exists('roundingIncrement', $options) && $options['roundingIncrement'] !== null) {
            // Per TC39 ToTemporalRoundingIncrement: GetOption with type «Number» calls ToNumber,
            // which coerces booleans/numeric strings. CalendarMath::toFiniteInt mirrors that.
            $rawIncrement = CalendarMath::toFiniteInt($options['roundingIncrement'], 'roundingIncrement');
            if ($rawIncrement < 1) {
                throw new RangeError('roundingIncrement must be a positive integer.');
            }
            $increment = $rawIncrement;
        }
        if ($maxDivisor === 1) {
            if ($increment !== 1) {
                throw new RangeError("roundingIncrement {$increment} is invalid for unit \"{$suRaw}\".");
            }
        } elseif ($increment >= $maxDivisor || ($maxDivisor % $increment) !== 0) {
            throw new RangeError(
                "roundingIncrement {$increment} does not evenly divide {$maxDivisor} for unit \"{$suRaw}\".",
            );
        }

        $nsIncrement = $nsPerUnit * $increment;
        $isDay = str_starts_with($suRaw, 'day');

        // ZonedDateTime rounding is always relative to local midnight (start of day).
        // Get local midnight epoch seconds and the offset from midnight in nanoseconds.
        $lc = $zdt->localComponents();
        $epochDays = CalendarMath::toJulianDay($lc['year'], $lc['month'], $lc['day']) - 2_440_588;
        $midnightWallSec = $epochDays * 86_400;
        $midnightEpochSec = TimeZoneHelper::wallSecToEpochSecStartOfDay($midnightWallSec, $zdt->resolvedTimeZoneId);

        // Compute offset from midnight using true epoch parts to handle sentinels.
        [$thisEpochSec, $thisSubNs] = $zdt->epochParts();
        $offsetFromMidnight = (($thisEpochSec - $midnightEpochSec) * EpochLimits::NS_PER_SECOND) + $thisSubNs;

        if ($isDay) {
            // Compute actual day length for DST-aware day rounding.
            $nextDayWallSec = $midnightWallSec + 86_400;
            $nextDayEpochSec = TimeZoneHelper::wallSecToEpochSecStartOfDay($nextDayWallSec, $zdt->resolvedTimeZoneId);

            // Spec (round step 18): GetStartOfDay(dateStart)/GetStartOfDay(dateEnd) must
            // throw when either day boundary falls outside the representable range.
            if (
                abs($midnightEpochSec) > EpochLimits::MAX_EPOCH_SECONDS
                || abs($nextDayEpochSec) > EpochLimits::MAX_EPOCH_SECONDS
            ) {
                throw new RangeError('ZonedDateTime day-rounding boundary is outside the representable range.');
            }

            $dayLengthNs = ($nextDayEpochSec - $midnightEpochSec) * EpochLimits::NS_PER_SECOND;

            $roundedOffsetNs = self::roundDayNs($offsetFromMidnight, $dayLengthNs, $roundingMode);
        } elseif ($nsIncrement === 1) {
            $roundedOffsetNs = $offsetFromMidnight;
        } else {
            // Round the offset from midnight, then add back midnight.
            $roundedOffsetNs = EpochRounding::roundAsIfPositive($offsetFromMidnight, $nsIncrement, $roundingMode);
        }

        // Compute the rounded result as epoch seconds + sub-ns.
        $roundedEpochSec = $midnightEpochSec + intdiv(num1: $roundedOffsetNs, num2: EpochLimits::NS_PER_SECOND);
        $roundedSubNs = $roundedOffsetNs % EpochLimits::NS_PER_SECOND;
        if ($roundedSubNs < 0) {
            $roundedEpochSec--;
            $roundedSubNs += EpochLimits::NS_PER_SECOND;
        }

        return ZonedDateTime::fromEpochParts($roundedEpochSec, $roundedSubNs, $zdt->timeZoneId, $zdt->calendarId);
    }

    /**
     * Rounds a nanosecond offset within a day for day-level rounding.
     *
     * Uses the actual day length (which may differ from 86400s due to DST).
     */
    private static function roundDayNs(int $offsetNs, int $dayLengthNs, string $mode): int
    {
        if ($mode === 'halfEven') {
            $cmp = $offsetNs * 2;
            if ($cmp < $dayLengthNs) {
                return 0;
            }
            return $cmp > $dayLengthNs ? $dayLengthNs : 0;
        }
        return match ($mode) {
            'trunc', 'floor' => 0,
            'ceil', 'expand' => $offsetNs === 0 ? 0 : $dayLengthNs,
            'halfExpand', 'halfCeil' => ($offsetNs * 2) >= $dayLengthNs ? $dayLengthNs : 0,
            'halfTrunc', 'halfFloor' => ($offsetNs * 2) > $dayLengthNs ? $dayLengthNs : 0,
            default => throw new RangeError("Invalid roundingMode \"{$mode}\"."),
        };
    }
}
