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

        // Total on the exact value, carried as a (whole seconds, sub-second nanoseconds)
        // pair the way DurationRounding does. The combined nanosecond count passes int64
        // long before MaxTimeDuration, and float64's ulp up there is milliseconds wide,
        // so summing into a float first and scaling afterwards drops digits the spec
        // keeps — 8692288669465520513 ms came back a whole ulp out. Both halves of the
        // pair stay inside int64, and the single conversion at the end is the one
        // rounding TC39 allows.
        $absNs = (int) abs((float) $d->nanoseconds);
        $absUs = (int) abs((float) $d->microseconds);
        $absMs = (int) abs((float) $d->milliseconds);
        $absSec =
            ((int) abs((float) $d->days) * 86_400)
            + ((int) abs((float) $d->hours) * 3_600)
            + ((int) abs((float) $d->minutes) * 60)
            + (int) abs((float) $d->seconds);

        // Carry each sub-second field up separately: a single milliseconds field may hold
        // enough to overflow int64 once multiplied out to nanoseconds.
        $absUs += intdiv(num1: $absNs, num2: 1_000_000_000) * 1_000_000;
        $absMs += intdiv(num1: $absUs, num2: 1_000_000) * 1_000;
        $absSec += intdiv(num1: $absMs, num2: 1_000);
        $subNs = (($absMs % 1_000) * 1_000_000) + (($absUs % 1_000_000) * 1_000) + ($absNs % 1_000_000_000);
        $absSec += intdiv(num1: $subNs, num2: 1_000_000_000);
        $subNs %= 1_000_000_000;

        $result = (float) $d->sign * self::exactTotal($absSec, $subNs, $unit);

        // Return int when the result is a whole number (matches JS behavior where
        // e.g. 24 hours total('hours') is 24, not 24.0).
        return self::toIntIfWhole($result);
    }

    /**
     * Validates and resolves the `relativeTo` option for `Duration::total()`'s
     * calendar paths. Returns the property-bag form of the anchor — one
     * {@see RelativeTo::anchorYmd()} can read a year, month and day out of —
     * plus the ZonedDateTime info record when the original input was a ZDT.
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
     *     resolved bag names no year, month/monthCode, or day.
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

        // DateInterval::$days is int|false, false only for an interval not built by
        // diff(); these always are.
        $remainingDays = intval($current->diff($end)->days);
        // Use start-anchored r2 to match TC39 spec (daysUntil(r1, r2) where
        // r2 = start + (months+1) months, not current + 1 month).
        $r2 = AnchorMath::addMonthsClamped($start, $sign * ($months + 1));
        // The r2 boundary may fall beyond the representable ISO date-time range
        // when the anchor sits near the limit; per TC39 RoundDuration this is a
        // RangeError.
        AnchorMath::assertCalendarBoundaryInRange($r2);
        $daysInNextMonth = intval($current->diff($r2)->days);

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

        // months + (remainingDays + |fracNs| / nsPerDay) / daysInNextMonth, as one exact
        // quotient. Summing the three terms as floats rounds three times, which lands a
        // full ulp out on values like 1 + 11/31.
        $absFracNs = $sign * $fracNs;
        $result =
            (float) $sign
            * self::divideExact(
                ((($months * $daysInNextMonth) + $remainingDays) * 86_400)
                + intdiv(num1: $absFracNs, num2: 1_000_000_000),
                $absFracNs % 1_000_000_000,
                $daysInNextMonth * 86_400,
                0,
            );

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

        // DateInterval::$days is int|false, false only for an interval not built by
        // diff(); these always are.
        $remainingDays = intval($current->diff($end)->days);
        // Use start-anchored r2 to match TC39 spec (daysUntil(r1, r2) where
        // r2 = start + (years+1) years, not current + 1 year).
        $r2 = AnchorMath::addYearsClamped($start, $sign * ($years + 1));
        // The r2 boundary may fall beyond the representable ISO date-time range
        // when the anchor sits near the limit; per TC39 RoundDuration this is a
        // RangeError.
        AnchorMath::assertCalendarBoundaryInRange($r2);
        $daysInNextYear = intval($current->diff($r2)->days);
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
        // Past int64 the cast wraps rather than saturating, and every float that large is
        // a whole number anyway, so there is nothing to gain by narrowing it.
        if (abs($result) >= 9.223_372_036_854_776e18) {
            return $result;
        }
        return fmod(num1: $result, num2: 1.0) === 0.0 ? (int) $result : $result;
    }

    /**
     * The non-negative exact value ($sec + $subNs / 1e9) seconds, expressed in $unit and
     * narrowed to the nearest double exactly once.
     *
     * The quotient is assembled as a decimal string and handed to a single strtod, which
     * is correctly rounded. Every unit length is a power of ten times a small factor
     * (60, 3600, 86400), so the power of ten is a decimal-point shift and only the small
     * factor needs dividing out — done digit by digit, which keeps the value exact
     * however far past int64 it runs.
     *
     * @param 'days'|'hours'|'minutes'|'seconds'|'milliseconds'|'microseconds'|'nanoseconds' $unit
     */
    private static function exactTotal(int $sec, int $subNs, string $unit): float
    {
        // [seconds per unit, decimal places to shift the point right] for each unit.
        [$divisor, $shift] = match ($unit) {
            'days' => [86_400, 0],
            'hours' => [3_600, 0],
            'minutes' => [60, 0],
            'seconds' => [1, 0],
            'milliseconds' => [1, 3],
            'microseconds' => [1, 6],
            'nanoseconds' => [1, 9],
        };

        return self::divideExact($sec, $subNs, $divisor, $shift);
    }

    /**
     * The non-negative exact value ($whole + $frac / 1e9) divided by $divisor, scaled by
     * 10^$shift, narrowed to the nearest double exactly once. $frac must be in [0, 1e9).
     *
     * The quotient's decimal expansion is produced digit by digit and handed to a single
     * strtod, which is correctly rounded. Building the value in float64 instead would
     * round at every step, and the intermediate does not fit int64 anyway: a whole-second
     * count near MaxTimeDuration expressed in nanoseconds needs 25 digits.
     *
     * The expansion is carried far enough past the point that truncating it cannot change
     * which double it rounds to — the remaining tail is smaller than any ulp in range.
     */
    private static function divideExact(int $whole, int $frac, int $divisor, int $shift): float
    {
        // Exact digits of ($whole + $frac / 1e9) × 1e9, i.e. the value with the point nine
        // places from the right.
        $digits = $whole . str_pad(string: (string) $frac, length: 9, pad_string: '0', pad_type: STR_PAD_LEFT);
        $pointFromRight = 9 - $shift;

        if ($divisor > 1) {
            $extra = 40;
            $length = strlen($digits);
            $quotient = '';
            $remainder = 0;
            for ($i = 0, $n = $length + $extra; $i < $n; $i++) {
                $remainder = ($remainder * 10) + ($i < $length ? (int) $digits[$i] : 0);
                $quotient .= intdiv(num1: $remainder, num2: $divisor);
                $remainder %= $divisor;
            }
            $digits = $quotient;
            $pointFromRight += $extra;
        }

        if ($pointFromRight <= 0) {
            return floatval($digits . str_repeat('0', -$pointFromRight));
        }
        $digits = str_pad(string: $digits, length: $pointFromRight + 1, pad_string: '0', pad_type: STR_PAD_LEFT);
        return floatval(sprintf(
            '%s.%s',
            substr(string: $digits, offset: 0, length: -$pointFromRight),
            substr(string: $digits, offset: -$pointFromRight),
        ));
    }
}
