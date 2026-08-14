<?php

declare(strict_types=1);

namespace Temporal\Spec\Internal;

use Temporal\Exception\RangeError;
use Temporal\Exception\TypeError;
use Temporal\Spec\Duration;
use Temporal\Spec\PlainDate;
use Temporal\Spec\ZonedDateTime;

/**
 * The engine behind {@see Duration::total()}.
 *
 * Totalling splits into three regimes that share almost no arithmetic, which is
 * why the method is large enough to live on its own:
 *
 *   - **Unanchored time units.** Days and below have fixed lengths, so the whole
 *     answer is one division. No `relativeTo` needed.
 *   - **Zoned time units.** With an IANA anchor a "day" is whatever the zone says
 *     it is, so days are walked one real transition at a time via {@see AnchorMath}.
 *   - **Calendar units.** Years, months and weeks are counted by stepping the anchor
 *     forward a unit at a time and measuring the leftover against the length of the
 *     unit that would come next — TC39 RoundDuration's fractional-unit rule.
 *
 * The float expressions deliberately preserve TC39's evaluation order: float
 * addition is not associative, and reordering these terms changes the last ULP
 * that test262's precision fixtures pin down.
 *
 * @internal
 */
final class DurationTotal
{
    /**
     * Computes the total of $d expressed in $unit.
     *
     * $unit has already been normalized by the caller; $totalOf is the original
     * options bag, still needed here because the `relativeTo` anchor is read from it.
     *
     * @param 'years'|'months'|'weeks'|'days'|'hours'|'minutes'|'seconds'|'milliseconds'|'microseconds'|'nanoseconds' $unit
     * @param string|array<array-key, mixed>|object $totalOf
     * @throws RangeError if the unit is unavailable without relativeTo.
     * @throws TypeError if relativeTo is present but not a valid anchor.
     */
    public static function compute(Duration $d, string $unit, string|array|object $totalOf): int|float
    {
        if ($unit === 'years' || $unit === 'months' || $unit === 'weeks') {
            [$rt, $zdtInfoCal] = self::resolveRelativeTo(
                $totalOf,
                "total() with unit \"{$unit}\" requires a relativeTo option.",
            );
            return self::calendar($d, $unit, $rt, $zdtInfoCal);
        }

        if ($d->years !== 0 || $d->months !== 0 || $d->weeks !== 0) {
            [$rt, $zdtInfoCal] = self::resolveRelativeTo(
                $totalOf,
                'total() on a duration with years, months, or weeks requires a relativeTo option.',
            );
            return self::calendar($d, $unit, $rt, $zdtInfoCal);
        }

        // Validate relativeTo if provided (even for pure-time unit computations).
        if (is_array($totalOf) && array_key_exists('relativeTo', $totalOf)) {
            /** @var mixed $rtRaw */
            $rtRaw = $totalOf['relativeTo'];
            if (is_string($rtRaw)) {
                $parsedRt = RelativeTo::parseString($rtRaw);
                $rtIsZDT = $parsedRt['_isZDT'] === true;
                // For total('days') with ZDT in UTC/fixed-offset: local time must be midnight.
                // This ensures the start-of-next-day is within the representable instant range.
                // (IANA timezones skip this check because DST-aware total legitimately uses non-midnight.)
                if ($unit === 'days' && $rtIsZDT && $parsedRt['_localTimeSec'] !== 0) {
                    // Check if the timezone is IANA (not UTC/fixed-offset).
                    $tzBracket = null;
                    $_m = null;
                    if (preg_match('/\[([^\]=]+)\]\s*$/', $rtRaw, $_m) === 1) {
                        $tzBracket = $_m[1];
                    }
                    $isIanaTz =
                        $tzBracket !== null
                        && $tzBracket !== 'UTC'
                        && preg_match('/^[+\-]\d{2}:\d{2}$/', $tzBracket) !== 1;
                    if (!$isIanaTz) {
                        throw new RangeError("relativeTo ZonedDateTime for total('days') must be at local midnight.");
                    }
                }
                // For non-blank duration: check epoch overflow.
                if (!$d->blank) {
                    $rtTotalSec =
                        ((float) $d->days * 86_400.0)
                        + ((float) $d->hours * 3_600.0)
                        + ((float) $d->minutes * 60.0)
                        + (float) $d->seconds
                        + (
                            (
                                ((float) $d->milliseconds * 1_000_000.0)
                                + ((float) $d->microseconds * 1_000.0)
                                + (float) $d->nanoseconds
                            )
                            / 1_000_000_000.0
                        );
                    if ($rtIsZDT) {
                        if (
                            ((float) $parsedRt['_utcSec'] + $rtTotalSec) > EpochLimits::MAX_EPOCH_SECONDS
                            || ((float) $parsedRt['_utcSec'] + $rtTotalSec) < -EpochLimits::MAX_EPOCH_SECONDS
                        ) {
                            throw new RangeError(
                                'relativeTo ZonedDateTime is outside the representable range after applying duration.',
                            );
                        }
                    } else {
                        // PlainDate: epoch days must be within ±100 000 000.
                        if (abs((int) $parsedRt['_epochDays']) > 100_000_000) {
                            throw new RangeError(
                                'relativeTo PlainDate is outside the representable range after applying duration.',
                            );
                        }
                    }
                }
            } elseif ($rtRaw instanceof PlainDate) {
                // PlainDate objects are always valid for pure-time computations; no extra validation needed.
            } elseif ($rtRaw instanceof ZonedDateTime) {
                // ZonedDateTime objects are valid relativeTo values for pure-time computations.
                // For a non-blank duration, the target instant (anchor epoch + duration) must
                // stay within the representable Temporal range (±8.64e21 ns ≙ ±8.64e12 s).
                // Read the TRUE epoch seconds via epochParts() (sentinel-aware), not the clamped
                // public epochNanoseconds field.
                if (!$d->blank) {
                    [$rtTrueSec, $rtSubNs] = $rtRaw->epochParts();
                    if (RelativeTo::zdtTargetOutOfRange($rtTrueSec, $rtSubNs, $d)) {
                        throw new RangeError(
                            'relativeTo ZonedDateTime is outside the representable range after applying duration.',
                        );
                    }
                }
            } elseif ($rtRaw !== null) {
                if (is_object($rtRaw)) {
                    $rtForVal = RelativeTo::normalizeBag($rtRaw);
                } elseif (is_array($rtRaw)) {
                    $rtForVal = $rtRaw;
                } else {
                    throw new TypeError('relativeTo must be a string or property bag array.');
                }
                RelativeTo::validatePropertyBag($rtForVal);
            }
        }

        // DST-aware computation when relativeTo is a ZDT with IANA timezone.
        /** @var mixed $rtForZdt */
        $rtForZdt = is_array($totalOf) ? $totalOf['relativeTo'] ?? null : null;
        $zdtInfo = $rtForZdt !== null ? RelativeTo::resolveZdt($rtForZdt) : null;

        if ($zdtInfo !== null) {
            // Time-only fields in seconds (sub-second precision preserved).
            $subNs =
                ((float) $d->milliseconds * 1_000_000.0)
                + ((float) $d->microseconds * 1_000.0)
                + (float) $d->nanoseconds;
            $timeOnlySec =
                ((float) $d->hours * 3_600.0)
                + ((float) $d->minutes * 60.0)
                + (float) $d->seconds
                + ($subNs / 1_000_000_000.0);

            $daysField = (int) $d->days;

            if ($unit === 'days') {
                // Convert days to actual epoch seconds, then add time seconds.
                $daysSec = AnchorMath::zdtDaysToSec(
                    $zdtInfo['year'],
                    $zdtInfo['month'],
                    $zdtInfo['day'],
                    $zdtInfo['hour'],
                    $zdtInfo['minute'],
                    $zdtInfo['second'],
                    $zdtInfo['tzId'],
                    $daysField,
                    $zdtInfo['epochSec'],
                );
                $totalSec = $daysSec + $timeOnlySec;
                // Count fractional days using actual day lengths, starting from the ZDT's real epoch.
                $result = AnchorMath::zdtTotalDays(
                    $zdtInfo['epochSec'],
                    $zdtInfo['year'],
                    $zdtInfo['month'],
                    $zdtInfo['day'],
                    $zdtInfo['hour'],
                    $zdtInfo['minute'],
                    $zdtInfo['second'],
                    $zdtInfo['tzId'],
                    $totalSec,
                );
                return self::toIntIfWhole($result);
            }

            // For hours/minutes/seconds/etc: convert days to actual seconds, then total.
            $daysSec = AnchorMath::zdtDaysToSec(
                $zdtInfo['year'],
                $zdtInfo['month'],
                $zdtInfo['day'],
                $zdtInfo['hour'],
                $zdtInfo['minute'],
                $zdtInfo['second'],
                $zdtInfo['tzId'],
                $daysField,
                $zdtInfo['epochSec'],
            );
            $totalSec = $daysSec + $timeOnlySec;

            $result = match ($unit) {
                'hours' => $totalSec / 3_600.0,
                'minutes' => $totalSec / 60.0,
                'seconds' => $totalSec,
                'milliseconds' => $totalSec * 1_000.0,
                'microseconds' => $totalSec * 1_000_000.0,
                'nanoseconds' => $totalSec * 1_000_000_000.0,
            };
            return self::toIntIfWhole($result);
        }

        // Compute in seconds. Combine sub-second fields into nanoseconds first, then divide
        // by 1e9 once — this avoids accumulated float64 rounding error from separate divisions
        // (e.g. 2ms/1000 + 31µs/1e6 gives 0.0020310000000000003 instead of 0.002031).
        $subNs =
            ((float) $d->milliseconds * 1_000_000.0) + ((float) $d->microseconds * 1_000.0) + (float) $d->nanoseconds;
        $totalSec =
            ((float) $d->days * 86_400.0)
            + ((float) $d->hours * 3_600.0)
            + ((float) $d->minutes * 60.0)
            + (float) $d->seconds
            + ($subNs / 1_000_000_000.0);

        $result = match ($unit) {
            'days' => $totalSec / 86_400.0,
            'hours' => $totalSec / 3_600.0,
            'minutes' => $totalSec / 60.0,
            'seconds' => $totalSec,
            'milliseconds' => $totalSec * 1_000.0,
            'microseconds' => $totalSec * 1_000_000.0,
            'nanoseconds' => $totalSec * 1_000_000_000.0,
        };

        // Return int when the result is a whole number (matches JS behavior where
        // e.g. 24 hours total('hours') is 24, not 24.0).
        return self::toIntIfWhole($result);
    }

    /**
     * Validates and resolves the `relativeTo` option for `Duration::total()`'s
     * calendar paths. Returns the property-bag form of the anchor (with `year`,
     * `month`/`monthCode`, `day` keys guaranteed) plus the ZonedDateTime info
     * record when the original input was a ZDT.
     *
     * @param mixed  $totalOf  the options bag passed to `total()` (string-form
     *     totalOf is invalid here — calendar units require an options object,
     *     never a bare smallestUnit string).
     * @param string $missingMsg  message for RangeError when the
     *     `relativeTo` key is absent. The TypeError thrown for an explicit null
     *     and the field-shape errors are spec-mandated and identical across
     *     calling sites.
     * @return array{0: array<array-key, mixed>, 1: array{epochSec: int, subNs: int, tzId: string, year: int, month: int, day: int, hour: int, minute: int, second: int}|null}
     *     [resolvedBag, zdtInfo].
     * @throws RangeError if relativeTo is absent.
     * @throws \TypeError if relativeTo is null, not String/Object, or the
     *     resolved bag lacks year, month/monthCode, or day.
     */
    private static function resolveRelativeTo(mixed $totalOf, string $missingMsg): array
    {
        if (!is_array($totalOf) || !array_key_exists('relativeTo', $totalOf)) {
            throw new RangeError($missingMsg);
        }
        // Per TC39 GetTemporalRelativeToOption: present-but-null relativeTo is
        // not a String or Object → TypeError. (Distinct from the absent case,
        // which is RangeError above.) test262
        // Duration/prototype/total/does-not-accept-non-string-primitives-for-relativeTo
        // pins this distinction.
        if ($totalOf['relativeTo'] === null) {
            throw new TypeError('relativeTo must be a string, property bag, or Temporal date/datetime.');
        }
        /** @var mixed $rt */
        $rt = $totalOf['relativeTo'];
        // PlainDate and ZonedDateTime objects are valid relativeTo values; convert to property bag.
        if ($rt instanceof ZonedDateTime) {
            $rt = RelativeTo::zdtToPlainDateBag($rt);
        } elseif ($rt instanceof PlainDate) {
            $rt = ['year' => $rt->isoYear, 'month' => $rt->isoMonth, 'day' => $rt->isoDay];
        } elseif (is_string($rt)) {
            $rt = RelativeTo::parseString($rt);
        } else {
            if (is_object($rt)) {
                $rt = RelativeTo::normalizeBag($rt);
            }
            if (is_array($rt)) {
                RelativeTo::validatePropertyBag($rt);
            } else {
                throw new TypeError('relativeTo must be a string or property bag.');
            }
        }
        // Both 'month' and 'monthCode' are valid month specifiers per TC39.
        $hasYear = array_key_exists('year', $rt);
        $hasMonth = array_key_exists('month', $rt) || array_key_exists('monthCode', $rt);
        $hasDay = array_key_exists('day', $rt);
        if (!$hasYear || !$hasMonth || !$hasDay) {
            throw new TypeError('relativeTo property bag must have year, month/monthCode, and day fields.');
        }
        return [$rt, RelativeTo::resolveZdt($totalOf['relativeTo'])];
    }

    /**
     * Implements total() for calendar units (years/months/weeks) given an ISO PlainDate
     * relativeTo bag. Unknown keys in the bag are silently ignored per TC39.
     *
     * @param 'years'|'months'|'weeks'|'days'|'hours'|'minutes'|'seconds'|'milliseconds'|'microseconds'|'nanoseconds' $unit
     * @param array<array-key,mixed> $relativeTo Validated plain-date property bag.
     * @param null|array{epochSec: int, subNs: int, tzId: string, year: int, month: int, day: int, hour: int, minute: int, second: int} $zdtInfo Optional ZDT info for DST-aware day lengths.
     */
    private static function calendar(Duration $d, string $unit, array $relativeTo, ?array $zdtInfo = null): int|float
    {
        [$year, $month, $day] = RelativeTo::anchorYmd($relativeTo);

        $tz = new \DateTimeZone('UTC');
        $start = new \DateTimeImmutable('now', $tz)
            ->setDate($year, $month, $day)
            ->setTime(0, 0, 0);

        // Compute calendar days: apply years/months/weeks to get endDate, count days.
        // Use TC39-compliant clamped arithmetic to avoid PHP month-overflow (e.g. Jan 31 + 1M = Mar 2 in PHP).
        $calendarDateEnd = $start;
        $calSign = $d->sign;
        if ((int) $d->years !== 0) {
            $calendarDateEnd = AnchorMath::addYearsClamped($calendarDateEnd, $calSign * abs((int) $d->years));
        }
        if ((int) $d->months !== 0) {
            $calendarDateEnd = AnchorMath::addMonthsClamped($calendarDateEnd, $calSign * abs((int) $d->months));
        }
        if ((int) $d->weeks !== 0) {
            $aw = $calSign * abs((int) $d->weeks) * 7;
            $calendarDateEnd = $calendarDateEnd->modify(sprintf('%+d days', $aw));
        }
        $calendarDays = (int) $start->diff($calendarDateEnd)->format('%r%a');

        // Total days = calendar days from calendar fields + the 'days' field.
        $nsPerDay = 86_400_000_000_000;
        $daysField = (int) $d->days;
        $totalWholeDays = $calendarDays + $daysField;

        // Sub-day nanoseconds (hours..nanoseconds fields only).
        $fracNs =
            ((int) $d->hours * 3_600_000_000_000)
            + ((int) $d->minutes * 60_000_000_000)
            + ((int) $d->seconds * 1_000_000_000)
            + ((int) $d->milliseconds * 1_000_000)
            + ((int) $d->microseconds * 1_000)
            + (int) $d->nanoseconds;

        // Validate that the effective end (startDate + totalWholeDays + time) is within range.
        $startEpochDay = AnchorMath::isoDateToEpochDays($year, $month, $day);
        $endEpochDay = $startEpochDay + $totalWholeDays;

        if (
            abs($endEpochDay) > 100_000_000
            || $endEpochDay === 100_000_000 && $fracNs > 0
            || $endEpochDay === -100_000_000 && $fracNs < 0
        ) {
            throw new RangeError('Duration with relativeTo exceeds the maximum representable date range.');
        }

        $fracDay = (float) $fracNs / (float) $nsPerDay;

        // For every unit (including years/months): validate that the total fractional days don't
        // exceed the maximum representable range (±100 000 000 days). This catches cases where
        // large time fields (e.g. seconds = 2^53 - 1) push the total far beyond the limit. TC39
        // AdjustDateDurationRecord (total §13.d) raises RangeError here; without this guard the
        // years/months paths reach totalCalendar{Years,Months}() with an overflowed float $fracNs
        // and throw a TypeError instead.
        $totalDaysF = (float) $totalWholeDays + $fracDay;
        if (abs($totalDaysF) > 100_000_000.0) {
            throw new RangeError('Duration with relativeTo exceeds the maximum representable date range.');
        }

        // For ZDT with IANA timezone: use DST-aware day lengths for days/hours/etc.
        if ($zdtInfo !== null && $unit !== 'months' && $unit !== 'years' && $unit !== 'weeks') {
            // Convert totalWholeDays + fracNs to actual epoch seconds using DST-aware arithmetic.
            $daysActualSec = AnchorMath::zdtDaysToSec(
                $zdtInfo['year'],
                $zdtInfo['month'],
                $zdtInfo['day'],
                $zdtInfo['hour'],
                $zdtInfo['minute'],
                $zdtInfo['second'],
                $zdtInfo['tzId'],
                $totalWholeDays,
            );
            $timeOnlySec = (float) $fracNs / 1_000_000_000.0;
            $totalActualSec = $daysActualSec + $timeOnlySec;

            if ($unit === 'days') {
                $result = AnchorMath::zdtTotalDays(
                    $zdtInfo['epochSec'],
                    $zdtInfo['year'],
                    $zdtInfo['month'],
                    $zdtInfo['day'],
                    $zdtInfo['hour'],
                    $zdtInfo['minute'],
                    $zdtInfo['second'],
                    $zdtInfo['tzId'],
                    $totalActualSec,
                );
                return self::toIntIfWhole($result);
            }

            $result = match ($unit) {
                'hours' => $totalActualSec / 3_600.0,
                'minutes' => $totalActualSec / 60.0,
                'seconds' => $totalActualSec,
                'milliseconds' => $totalActualSec * 1_000.0,
                'microseconds' => $totalActualSec * 1_000_000.0,
                'nanoseconds' => $totalActualSec * 1_000_000_000.0,
            };
            return self::toIntIfWhole($result);
        }

        return match ($unit) {
            'months' => self::calendarMonths($d, $start, $totalWholeDays, $fracNs, $nsPerDay, $zdtInfo),
            'years' => self::calendarYears($d, $start, $totalWholeDays, $fracNs, $zdtInfo),
            // For weeks: use floor(days/7) + ((days%7 + fracDay)/7) to match TC39 test precision.
            // Non-associative float: (totalDays+fracDay)/7 ≠ floor(totalDays/7)+((rem+fracDay)/7).
            'weeks' => self::toIntIfWhole(
                (float) intdiv(num1: $totalWholeDays, num2: 7) + (((float) ($totalWholeDays % 7) + $fracDay) / 7.0),
            ),
            'days' => self::toIntIfWhole((float) $totalWholeDays + $fracDay),
            'hours' => self::toIntIfWhole(((float) $totalWholeDays * 24.0) + ((float) $fracNs / 3_600_000_000_000.0)),
            'minutes' => self::toIntIfWhole(((float) $totalWholeDays * 1_440.0) + ((float) $fracNs / 60_000_000_000.0)),
            'seconds' => self::toIntIfWhole(((float) $totalWholeDays * 86_400.0) + ((float) $fracNs / 1_000_000_000.0)),
            'milliseconds' => self::toIntIfWhole(((float) $totalWholeDays * 86_400_000.0)
            + ((float) $fracNs / 1_000_000.0)),
            'microseconds' => self::toIntIfWhole(((float) $totalWholeDays * 86_400_000_000.0)
            + ((float) $fracNs / 1_000.0)),
            'nanoseconds' => self::toIntIfWhole(((float) $totalWholeDays * 86_400_000_000_000.0) + (float) $fracNs),
        };
    }

    /**
     * Counts fractional months from $start spanning $wholeDays days + $fracNs nanoseconds.
     * Implements TC39 RoundDuration for unit = "months".
     *
     * @param null|array{epochSec: int, subNs: int, tzId: string, year: int, month: int, day: int, hour: int, minute: int, second: int} $zdtInfo Optional ZDT info for DST-aware day lengths.
     */
    private static function calendarMonths(
        Duration $d,
        \DateTimeImmutable $start,
        int $wholeDays,
        int $fracNs,
        int $nsPerDay,
        ?array $zdtInfo = null,
    ): int|float {
        // Balance time (fracNs) into wholeDays so the month-counting loop crosses calendar boundaries
        // contained in the time portion (e.g. an until() result of N hours that spans a full month).
        $extraDays = intdiv(num1: $fracNs, num2: $nsPerDay);
        $wholeDays += $extraDays;
        $fracNs -= $extraDays * $nsPerDay;

        $absWholeDays = abs($wholeDays);
        $dir = $wholeDays >= 0 ? '+' : '-';
        $sign = $wholeDays >= 0 ? 1 : -1;
        $end = $start->modify("{$dir}{$absWholeDays} days");

        $months = 0;
        $current = $start;
        while (true) {
            $next = AnchorMath::addMonthsClamped($current, $sign);
            if ($sign > 0 ? $next > $end : $next < $end) {
                break;
            }
            $months++;
            $current = $next;
        }

        $remainingDays = $current->diff($end)->days;
        // Use start-anchored r2 to match TC39 spec (daysUntil(r1, r2) where
        // r2 = start + (months+1) months, not current + 1 month).
        $r2 = AnchorMath::addMonthsClamped($start, $sign * ($months + 1));
        // The r2 boundary may fall beyond the representable ISO date-time range
        // when the anchor sits near the limit; per TC39 RoundDuration this is a
        // RangeError.
        AnchorMath::assertCalendarBoundaryInRange($r2);
        $daysInNextMonth = $current->diff($r2)->days;

        if ($zdtInfo !== null) {
            // DST-aware: compute the actual epoch seconds spanning from $current to $r2
            // to get the real time length of the fractional month period.
            $currentY = (int) $current->format('Y');
            $currentM = (int) $current->format('n');
            $currentD = (int) $current->format('j');
            $actualSpanSec = AnchorMath::zdtDaysToSec(
                $currentY,
                $currentM,
                $currentD,
                $zdtInfo['hour'],
                $zdtInfo['minute'],
                $zdtInfo['second'],
                $zdtInfo['tzId'],
                $sign * $daysInNextMonth,
            );
            $actualRemainingSec = AnchorMath::zdtDaysToSec(
                $currentY,
                $currentM,
                $currentD,
                $zdtInfo['hour'],
                $zdtInfo['minute'],
                $zdtInfo['second'],
                $zdtInfo['tzId'],
                $sign * $remainingDays,
            );
            // Work in seconds to avoid float precision loss from nanosecond conversion.
            $fracSec = (float) $fracNs / 1_000_000_000.0;
            $absSpan = abs($actualSpanSec);
            $progress = (abs($actualRemainingSec) + abs($fracSec)) / $absSpan;
            $result = (float) ($months * $sign) + ($sign > 0 ? $progress : -$progress);
            return self::toIntIfWhole($result);
        }

        $result =
            (float) ($months * $sign)
            + ((float) ($sign * $remainingDays) / (float) $daysInNextMonth)
            + ((float) ($sign * $fracNs) / ((float) $nsPerDay * (float) $daysInNextMonth));

        return self::toIntIfWhole($result);
    }

    /**
     * Counts fractional years from $start spanning $wholeDays days + $fracNs nanoseconds.
     * Implements TC39 RoundDuration for unit = "years".
     */
    /**
     * @param array{epochSec:int,subNs:int,tzId:string,year:int,month:int,day:int,hour:int,minute:int,second:int}|null $zdtInfo
     */
    private static function calendarYears(
        Duration $d,
        \DateTimeImmutable $start,
        int $wholeDays,
        int $fracNs,
        ?array $zdtInfo = null,
    ): int|float {
        unset($zdtInfo); // Not currently used; reserved for future ZDT-aware year total.
        // Balance time (fracNs) into wholeDays so the year-counting loop crosses calendar boundaries
        // contained in the time portion (e.g. an until() result of 13152 hours = 548 days that spans
        // a full year). Without this, time-only durations always report 0 whole years.
        $nsPerDay = 86_400_000_000_000;
        $extraDays = intdiv(num1: $fracNs, num2: $nsPerDay);
        $wholeDays += $extraDays;
        $fracNs -= $extraDays * $nsPerDay;

        $absWholeDays = abs($wholeDays);
        $dir = $wholeDays >= 0 ? '+' : '-';
        $sign = $wholeDays >= 0 ? 1 : -1;
        $end = $start->modify("{$dir}{$absWholeDays} days");

        $years = 0;
        $current = $start;
        while (true) {
            $next = AnchorMath::addYearsClamped($current, $sign);
            if ($sign > 0 ? $next > $end : $next < $end) {
                break;
            }
            $years++;
            $current = $next;
        }

        $remainingDays = $current->diff($end)->days;
        // Use start-anchored r2 to match TC39 spec (daysUntil(r1, r2) where
        // r2 = start + (years+1) years, not current + 1 year).
        $r2 = AnchorMath::addYearsClamped($start, $sign * ($years + 1));
        // The r2 boundary may fall beyond the representable ISO date-time range
        // when the anchor sits near the limit; per TC39 RoundDuration this is a
        // RangeError.
        AnchorMath::assertCalendarBoundaryInRange($r2);
        $daysInNextYear = $current->diff($r2)->days;
        // Convert fracNs → ms → fracDays via two exact divisions.
        // Direct division fracNs / (nsPerDay * 365) loses precision (86400e9 * 365 > 2^53).
        // Dividing fracNs by 1e6 first (ns → ms) gives the same float64 as the JS test's
        // ms-level computation (fracMs / dayMs), avoiding the 1-ULP rounding difference.
        $fracDays = ((float) ($sign * $fracNs) / 1_000_000.0) / 86_400_000.0;
        // Compute fractional part first (matching TC39 test evaluation order):
        // test: $fractionalYear = $partialYearDays / 365 + ($fractionalDay / 365)
        // then: $fullYears + $fractionalYear
        // Float addition is non-associative: (a+b)+c ≠ a+(b+c) at this precision.
        $fracPart =
            ((float) ($sign * $remainingDays) / (float) $daysInNextYear) + ($fracDays / (float) $daysInNextYear);
        $result = (float) ($years * $sign) + $fracPart;

        return self::toIntIfWhole($result);
    }

    private static function toIntIfWhole(float $result): int|float
    {
        return fmod(num1: $result, num2: 1.0) === 0.0 ? (int) $result : $result;
    }
}
