<?php

declare(strict_types=1);

namespace Temporal\Spec\Internal;

use Temporal\Exception\RangeError;
use Temporal\Exception\TypeError;
use Temporal\Spec\Duration;
use Temporal\Spec\PlainDate;
use Temporal\Spec\ZonedDateTime;

/**
 * The engine behind {@see Duration::round()}.
 *
 * Rounding a Duration is really three algorithms wearing one name, selected by
 * whether the request crosses a boundary whose size is not fixed:
 *
 *   - **Pure time rounding.** With no calendar unit in play, the duration collapses
 *     to a nanosecond count, gets rounded there, and is re-balanced into fields.
 *     Exact int64 arithmetic where the count fits, float fallback where it does not.
 *   - **Anchored day rounding.** A `relativeTo` in an IANA zone makes "one day"
 *     a zone-dependent quantity, so day boundaries are walked through
 *     {@see AnchorMath} instead of assumed to be 86 400 seconds apart.
 *   - **Calendar-unit nudging.** Rounding *to* weeks, months or years cannot go
 *     through a nanosecond count at all: TC39 NudgeToCalendarUnit brackets the
 *     duration between two calendar boundaries and picks one by comparing the
 *     progress between them against the rounding mode.
 *
 * Every method takes the Duration being rounded as its first argument rather than
 * reading `$this`, which keeps the whole engine free of hidden state.
 *
 * @internal
 */
final class DurationRounding
{
    /**
     * Rounds $d to the given unit/options.
     *
     * @param string|array<array-key, mixed>|object $roundTo string (smallestUnit) or options array.
     * @throws TypeError if $roundTo is not a string or array.
     * @throws RangeError if options are invalid or calendar units are used without a relativeTo anchor.
     */
    public static function round(Duration $d, string|array|object $roundTo): Duration
    {
        if (is_string($roundTo)) {
            $roundTo = ['smallestUnit' => $roundTo];
        } else {
            // TC39: if roundTo is undefined, throw TypeError (required arg).
            // GetOptionsObject: a Symbol sentinel object => TypeError (via Stringable cast).
            // JsUndefined sentinel: Stringable returning 'undefined' → TypeError (undefined is not an Object).
            if (is_object($roundTo) && $roundTo instanceof \Stringable) {
                $str = (string) $roundTo; // JsSymbol: throws TypeError; JsUndefined: returns 'undefined'
                if ($str === 'undefined') {
                    throw new TypeError('Duration::round() requires a non-undefined options argument.');
                }
            }
            // The five options are read alphabetically, but `relativeTo` is not merely
            // read: GetTemporalRelativeToOption CONVERTS it where it stands, walking a
            // property bag field by field before `roundingIncrement` is looked at. So
            // the snapshot is split around it rather than taken in one pass.
            $bag = Options::asOptionsBag($roundTo);
            $roundTo = Options::bagSnapshot($bag, ['largestUnit']);
            /** @var mixed $relativeTo */
            $relativeTo = RelativeTo::readOption($bag);
            $roundTo = array_merge($roundTo, Options::bagSnapshot($bag, [
                'roundingIncrement',
                'roundingMode',
                'smallestUnit',
            ]));
            // Merged last: an ARRAY bag snapshots as itself, so an earlier merge would
            // be overwritten by the raw value the second snapshot carries along.
            if ($relativeTo !== Options::ABSENT) {
                $roundTo = array_merge($roundTo, ['relativeTo' => $relativeTo]);
            }
        }

        /** @var mixed $suRaw */
        $suRaw = $roundTo['smallestUnit'] ?? null;
        /** @var mixed $luRaw */
        $luRaw = $roundTo['largestUnit'] ?? null;
        /** @var mixed $rmRaw */
        $rmRaw = $roundTo['roundingMode'] ?? 'halfExpand';
        /** @var mixed $incRaw */
        $incRaw = $roundTo['roundingIncrement'] ?? 1;

        // Validate roundingIncrement. The universal coerce + finite + ≥1 core lives in
        // Options::roundingIncrement(); only Duration's operation-specific upper bound
        // stays here.
        $increment = Options::roundingIncrement($incRaw);
        if ($increment > 1_000_000_000) {
            throw new RangeError('roundingIncrement must not exceed 10^9.');
        }

        $roundingMode = $rmRaw === null
            ? 'halfExpand'
            : Options::roundingMode(Options::coerceEnumOption($rmRaw, 'roundingMode'));

        // At least one of smallestUnit or largestUnit must be provided.
        $suProvided = $suRaw !== null;
        $luProvided = $luRaw !== null;
        if (!$suProvided && !$luProvided) {
            throw new RangeError('Duration::round() requires at least one of smallestUnit or largestUnit.');
        }

        // Validate and normalize units.
        /** @var array<string,int> Unit index (0=nanosecond, 9=year). */
        static $UNIT_IDX = [
            'nanoseconds' => 0,
            'microseconds' => 1,
            'milliseconds' => 2,
            'seconds' => 3,
            'minutes' => 4,
            'hours' => 5,
            'days' => 6,
            'weeks' => 7,
            'months' => 8,
            'years' => 9,
        ];

        $suNorm = $suProvided ? Options::normalizeUnit(Options::coerceEnumOption($suRaw, 'smallestUnit')) : null;
        $luIsAuto = !$luProvided || $luRaw === 'auto';
        $luNorm = $luIsAuto ? null : Options::normalizeUnit(Options::coerceEnumOption($luRaw, 'largestUnit'));

        // Calendar smallestUnit or largestUnit require relativeTo.
        $suIsCalendar = $suNorm !== null && array_key_exists($suNorm, $UNIT_IDX) && $UNIT_IDX[$suNorm] >= 7;
        $luIsCalendar = $luNorm !== null && array_key_exists($luNorm, $UNIT_IDX) && $UNIT_IDX[$luNorm] >= 7;

        // Duration itself has calendar units.
        $durationHasCalendar = $d->years !== 0 || $d->months !== 0 || $d->weeks !== 0;

        $needsRelativeTo = $suIsCalendar || $luIsCalendar || $durationHasCalendar;

        $relativeToProvided = RelativeTo::isPresent($roundTo);

        // Detect ZonedDateTime relativeTo for sub-day rounding behavior.
        // $roundTo is always an array at this point (strings/objects normalized above).
        /** @var mixed $rtRawForZdt */
        $rtRawForZdt = $roundTo['relativeTo'] ?? null;
        $zdtRelativeTo = $rtRawForZdt instanceof \Temporal\Spec\ZonedDateTime;
        $zdtInfoRound = $rtRawForZdt !== null ? RelativeTo::resolveZdt($rtRawForZdt) : null;

        if ($needsRelativeTo && !$relativeToProvided) {
            // Distinguish "key absent" (= JS undefined → RangeError) from "key
            // present with PHP null" (= JS null → TypeError per
            // GetTemporalRelativeToOption "If value is not a String or an Object,
            // throw a TypeError"). extractRelativeTo collapses both to false
            // for the unneeded path; only re-inspect here when the option matters.
            if (array_key_exists('relativeTo', $roundTo) && $roundTo['relativeTo'] === null) {
                throw new TypeError('relativeTo must be a string, property bag, or Temporal date/datetime.');
            }
            throw new RangeError(
                'Duration::round() with calendar units (years, months, weeks) requires a relativeTo option.',
            );
        }
        if ($needsRelativeTo) {
            /** @var mixed $rtRaw */
            $rtRaw = $roundTo['relativeTo'] ?? null;
            return self::roundWithRelativeTo(
                $d,
                $rtRaw,
                $suNorm,
                $luIsAuto,
                $luNorm,
                $increment,
                $roundingMode,
                $UNIT_IDX,
            );
        }

        // For pure-time rounds: validate relativeTo and check overflow for non-blank durations.
        if ($relativeToProvided && !$d->blank) {
            /** @var mixed $rtRaw */
            $rtRaw = $roundTo['relativeTo'] ?? null;
            if (is_string($rtRaw)) {
                $parsedRt = RelativeTo::parseString($rtRaw);
                $rtIsZDT = $parsedRt['_isZDT'] === true;
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
        }

        // Default smallestUnit is 'nanoseconds'.
        $suIdx = $suNorm !== null ? $UNIT_IDX[$suNorm] : 0;

        // Resolve 'auto' largestUnit: largest non-zero time field (days..ns), or if all zero, use smallestUnit.
        if ($luIsAuto) {
            $luIdx = self::autoLargestUnit($d, $suIdx);
            // 'auto' must be at least as large as smallestUnit.
            if ($luIdx < $suIdx) {
                $luIdx = $suIdx;
            }
        } else {
            $luIdx = $UNIT_IDX[$luNorm ?? 'nanoseconds'];
        }

        // largestUnit must be >= smallestUnit.
        if ($luIdx < $suIdx) {
            throw new RangeError('largestUnit must be at least as large as smallestUnit.');
        }

        // Prevent undefined behavior from (int) cast on float Duration fields > PHP int64.
        // This can occur with very large float microseconds/nanoseconds values.
        foreach ([
            $d->days,
            $d->hours,
            $d->minutes,
            $d->seconds,
            $d->milliseconds,
            $d->microseconds,
            $d->nanoseconds,
        ] as $_field) {
            if (is_float($_field) && abs($_field) >= 9.223_372_036_854_776e18) {
                throw new RangeError('Duration time fields exceed the maximum representable range after rounding.');
            }
        }

        // Compute total absolute nanoseconds, balancing all sub-day fields first.
        $sign = $d->sign;
        $absNs = (int) abs((float) $d->nanoseconds);
        $absUs = (int) abs((float) $d->microseconds);
        $absMs = (int) abs((float) $d->milliseconds);
        $absS = (int) abs((float) $d->seconds);
        $absM = (int) abs((float) $d->minutes);
        $absH = (int) abs((float) $d->hours);
        $absD = (int) abs((float) $d->days);

        // Balance up to get exact integers.
        $absUs += intdiv(num1: $absNs, num2: 1_000);
        $absNs %= 1_000;
        $absMs += intdiv(num1: $absUs, num2: 1_000);
        $absUs %= 1_000;
        $absS += intdiv(num1: $absMs, num2: 1_000);
        $absMs %= 1_000;
        $absM += intdiv(num1: $absS, num2: 60);
        $absS %= 60;
        $absH += intdiv(num1: $absM, num2: 60);
        $absM %= 60;

        // Balance hours into days: DST-aware when ZDT IANA relativeTo is present.
        if ($zdtInfoRound !== null) {
            $timeOnlyNs =
                ($absH * 3_600_000_000_000)
                + ($absM * 60_000_000_000)
                + ($absS * 1_000_000_000)
                + ($absMs * 1_000_000)
                + ($absUs * 1_000)
                + $absNs;
            [$absD, $timeOnlyNs] = AnchorMath::zdtBalanceTimeToDays(
                $zdtInfoRound['year'],
                $zdtInfoRound['month'],
                $zdtInfoRound['day'],
                $zdtInfoRound['hour'],
                $zdtInfoRound['minute'],
                $zdtInfoRound['second'],
                $zdtInfoRound['tzId'],
                $timeOnlyNs,
                $absD,
                $sign,
                $zdtInfoRound['epochSec'],
            );
            // Re-extract sub-fields from the balanced time nanoseconds.
            $absH = intdiv($timeOnlyNs, num2: 3_600_000_000_000);
            $timeOnlyNs -= $absH * 3_600_000_000_000;
            $absM = intdiv($timeOnlyNs, num2: 60_000_000_000);
            $timeOnlyNs -= $absM * 60_000_000_000;
            $absS = intdiv($timeOnlyNs, num2: 1_000_000_000);
            $timeOnlyNs -= $absS * 1_000_000_000;
            $absMs = intdiv($timeOnlyNs, num2: 1_000_000);
            $timeOnlyNs -= $absMs * 1_000_000;
            $absUs = intdiv($timeOnlyNs, num2: 1_000);
            $absNs = $timeOnlyNs - ($absUs * 1_000);
        } else {
            $absD += intdiv(num1: $absH, num2: 24);
            $absH %= 24;
        }

        // Compute totalNs, guarding against int64 overflow for large day counts.
        $subDayNs =
            ($absH * 3_600_000_000_000)
            + ($absM * 60_000_000_000)
            + ($absS * 1_000_000_000)
            + ($absMs * 1_000_000)
            + ($absUs * 1_000)
            + $absNs;

        // Validate: total seconds must not exceed MaxTimeDuration (MAX_SAFE_INT seconds).
        // Use float arithmetic to avoid int64 overflow in the check.
        $totalAbsSec =
            ((float) $absD * 86_400.0)
            + ((float) $absH * 3_600.0)
            + ((float) $absM * 60.0)
            + (float) $absS
            + ((float) $absMs / 1_000.0)
            + ((float) $absUs / 1_000_000.0)
            + ((float) $absNs / 1_000_000_000.0);
        if ($totalAbsSec > 9_007_199_254_740_992.0) {
            throw new RangeError('Duration time fields exceed the maximum representable range after rounding.');
        }

        // For ZonedDateTime relativeTo: the result instant must stay within ±8.64e21 ns
        // (the valid Temporal.Instant range). Check zdtEpoch ± duration in seconds.
        if ($rtRawForZdt instanceof \Temporal\Spec\ZonedDateTime) {
            // Use the true-parts accessor (not the clamped public epochNanoseconds field)
            // so the range guard reflects the real instant for over-int64 anchors.
            [$zdtTrueSec] = $rtRawForZdt->epochParts();
            $zdtEpochSec = (float) $zdtTrueSec;
            $zdtResultSec = $zdtEpochSec + ((float) $sign * $totalAbsSec);
            if ($zdtResultSec > EpochLimits::MAX_EPOCH_SECONDS || $zdtResultSec < -EpochLimits::MAX_EPOCH_SECONDS) {
                throw new RangeError(
                    'Duration with ZonedDateTime relativeTo would move the instant outside the valid range.',
                );
            }

            // Next-day boundary guard (mirrors TC39's hoursInDay / day-length computation).
            // When the largestUnit is days-or-coarser, RoundRelativeDuration must determine the
            // length of the current day, which requires AddZonedDateTime to find the start of the
            // NEXT day (and, on the negative edge, the start of the current/prior day). If that
            // boundary instant falls outside the representable Temporal range it must throw —
            // independent of the duration's magnitude (so this fires even for a blank duration,
            // unlike the magnitude-gated guard above).
            if ($luIdx >= 6) {
                // For UTC/fixed-offset zones (resolveRelativeToZdt() returns null) the day length
                // is a fixed 86_400 s, so the next-day start is the day-floored epoch + 86_400 s
                // and the day start is the day-floor itself. For IANA zones the resolved info
                // would route through the DST-aware path above; the fixed-offset edge is what the
                // over-int64 fixtures exercise here.
                $dayFloorSec = floor($zdtEpochSec / 86_400.0) * 86_400.0;
                $nextDayStartSec = $dayFloorSec + 86_400.0;
                if (
                    $nextDayStartSec > EpochLimits::MAX_EPOCH_SECONDS
                    || $dayFloorSec < -EpochLimits::MAX_EPOCH_SECONDS
                ) {
                    throw new RangeError(
                        'Duration with ZonedDateTime relativeTo: the day boundary falls outside the valid range.',
                    );
                }
            }
        }

        // Nanoseconds per unit (time units only; days and above handled separately).
        /** @var array<string,int> */
        static $NS_PER_UNIT = [
            'nanoseconds' => 1,
            'microseconds' => 1_000,
            'milliseconds' => 1_000_000,
            'seconds' => 1_000_000_000,
            'minutes' => 60_000_000_000,
            'hours' => 3_600_000_000_000,
        ];

        // Sub-day smallest unit: compute nanoseconds per increment and validate.
        // The 'days' case is handled separately below (early return) to avoid int64 overflow.
        $suNormResolved = $suNorm ?? 'nanoseconds';
        if ($suNormResolved !== 'days') {
            $nsPerSmallest = $NS_PER_UNIT[$suNormResolved] ?? 1;
            $nsIncrement = $nsPerSmallest * $increment;
        } else {
            $nsIncrement = 0; // placeholder; the 'days' path returns early below before using this.
        }

        // Validate increment: must be strictly less than the next-higher-unit count and divide it evenly.
        // Per TC39: e.g. minutes increment must be < 60 and divide 60 evenly.
        if ($suNormResolved !== 'days' && $suIdx < 6) {
            /** @var array<string,int> */
            static $MAX_PER_UNIT = [
                'nanoseconds' => 1_000,
                'microseconds' => 1_000,
                'milliseconds' => 1_000,
                'seconds' => 60,
                'minutes' => 60,
                'hours' => 24,
            ];
            $maxPerUnit = $MAX_PER_UNIT[$suNormResolved] ?? 1;
            if ($increment >= $maxPerUnit) {
                throw new RangeError("roundingIncrement {$increment} is too large for unit \"{$suNormResolved}\".");
            }
            if (($maxPerUnit % $increment) !== 0) {
                throw new RangeError(
                    "roundingIncrement {$increment} does not evenly divide into the next unit for \"{$suNormResolved}\".",
                );
            }
        }

        // ZDT sub-day rounding: for ZonedDateTime relativeTo with a time smallestUnit and
        // largestUnit >= days, keep whole days intact and round only the sub-day portion.
        // This differs from PlainDate behavior (which rounds the total nanoseconds).
        if (($zdtRelativeTo || $zdtInfoRound !== null) && $suNormResolved !== 'days' && $luIdx >= 6) {
            $roundedSubDayNs = self::roundNsPositive($subDayNs, $nsIncrement, $roundingMode);
            // If rounding carried the sub-day portion beyond one full day, add extra days.
            // Use DST-aware day length when available.
            if ($zdtInfoRound !== null) {
                [$absD, $roundedSubDayNs] = AnchorMath::zdtBalanceTimeToDays(
                    $zdtInfoRound['year'],
                    $zdtInfoRound['month'],
                    $zdtInfoRound['day'],
                    $zdtInfoRound['hour'],
                    $zdtInfoRound['minute'],
                    $zdtInfoRound['second'],
                    $zdtInfoRound['tzId'],
                    $roundedSubDayNs,
                    $absD,
                    $sign,
                    $zdtInfoRound['epochSec'],
                );
            } else {
                $extraDays = intdiv(num1: $roundedSubDayNs, num2: 86_400_000_000_000);
                $roundedSubDayNs -= $extraDays * 86_400_000_000_000;
                $absD += $extraDays;
            }
            // Balance the sub-day ns to fields. When ZDT IANA is present, cap at hours
            // (luIdx=5) since days were already computed by zdtBalanceTimeToDays with
            // actual day lengths — re-balancing at 24h/day would produce incorrect results.
            // $luIdx is guaranteed >= 6 by the enclosing branch.
            $balanceIdx = $zdtInfoRound !== null ? 5 : $luIdx;
            [$rDays, $rH, $rM, $rS, $rMs, $rUs, $rNs] = self::balanceNsToFields($roundedSubDayNs, $balanceIdx);
            /** @psalm-suppress InvalidOperand — balanceNsToFields returns int|float; $absD is int */
            $rDays += $absD;
            /** @psalm-suppress InvalidOperand — $sign (int) * int|float fields */
            return new Duration(
                0,
                0,
                0,
                $sign * $rDays,
                $sign * $rH,
                $sign * $rM,
                $sign * $rS,
                $sign * $rMs,
                $sign * $rUs,
                $sign * $rNs,
            );
        }

        // For 'days' smallest unit: work in day units to avoid int64 overflow for large increments.
        // roundingIncrement=1e9 would give nsIncrement=8.64e22 > PHP_INT_MAX, breaking integer math.
        // In the pure-time path largestUnit is always 'days' when smallestUnit='days'
        // (weeks/months/years require relativeTo → calendar path via roundWithRelativeTo).
        if ($suNormResolved === 'days') {
            // For ZDT IANA: use the actual day length for fractional day computation.
            if ($zdtInfoRound !== null) {
                $dayLenSec = abs(AnchorMath::zdtDayLengthSec(
                    $zdtInfoRound['year'],
                    $zdtInfoRound['month'],
                    $zdtInfoRound['day'],
                    $zdtInfoRound['hour'],
                    $zdtInfoRound['minute'],
                    $zdtInfoRound['second'],
                    $zdtInfoRound['tzId'],
                ));
                // After DST-aware balancing, $absD days are consumed and $subDayNs remains.
                // Compute the fractional day using the actual day length at the current position.
                // Walk forward from the ZDT by $absD days to find the relevant day.
                if ($absD > 0) {
                    $baseDt = new \DateTimeImmutable(sprintf(
                        '%04d-%02d-%02d',
                        $zdtInfoRound['year'],
                        $zdtInfoRound['month'],
                        $zdtInfoRound['day'],
                    ));
                    $afterDays = $baseDt->modify(sprintf('%+d days', $sign * $absD));
                    $dayLenSec = abs(AnchorMath::zdtDayLengthSec(
                        (int) $afterDays->format('Y'),
                        (int) $afterDays->format('n'),
                        (int) $afterDays->format('j'),
                        $zdtInfoRound['hour'],
                        $zdtInfoRound['minute'],
                        $zdtInfoRound['second'],
                        $zdtInfoRound['tzId'],
                    ));
                }
                $dayLenNs = (float) $dayLenSec * 1_000_000_000.0;
                $totalAbsDaysF = (float) $absD + ($dayLenNs > 0 ? (float) $subDayNs / $dayLenNs : 0.0);
            } else {
                $totalAbsDaysF = (float) $absD + ((float) $subDayNs / 86_400_000_000_000.0);
            }
            $roundedAbsDays = (int) self::roundNsFloat($totalAbsDaysF, (float) $increment, $roundingMode);
            if (((float) $roundedAbsDays * 86_400.0) >= 9_007_199_254_740_992.0) {
                throw new RangeError('Duration time fields exceed the maximum representable range after rounding.');
            }
            /** @psalm-suppress InvalidOperand */
            return new Duration(0, 0, 0, $sign * $roundedAbsDays, 0, 0, 0, 0, 0, 0);
        }

        // Compute totalNs as int when it fits in int64, float otherwise.
        // Safe threshold: 106_750 * 86_400_000_000_000 + 86_399_999_999_999 < PHP_INT_MAX.
        // Direct comparison (not a bool variable) lets Psalm narrow $absD's range inside the block.
        // For ZDT IANA: convert days to actual nanoseconds using DST-aware day lengths.
        if ($zdtInfoRound !== null && $absD > 0) {
            // Pass the ZDT's actual epoch so that sub-minute offsets (e.g. Pacific/Niue
            // -11:19:40 vs -11:20:00) are preserved instead of being re-resolved from
            // the wall time via compatible disambiguation.
            $actualDaysSec = (int) AnchorMath::zdtDaysToSec(
                $zdtInfoRound['year'],
                $zdtInfoRound['month'],
                $zdtInfoRound['day'],
                $zdtInfoRound['hour'],
                $zdtInfoRound['minute'],
                $zdtInfoRound['second'],
                $zdtInfoRound['tzId'],
                $sign * $absD,
                $zdtInfoRound['epochSec'],
            );
            $dayNsActual = abs($actualDaysSec) * 1_000_000_000;
            if ($absD <= 106_750) {
                $totalNsInt = $dayNsActual + $subDayNs;
                $roundedNsInt = self::roundNsPositive($totalNsInt, $nsIncrement, $roundingMode);
                if (((float) $roundedNsInt / 1_000_000_000.0) >= 9_007_199_254_740_992.0) {
                    throw new RangeError('Duration time fields exceed the maximum representable range after rounding.');
                }
                [$rDays, $rH, $rM, $rS, $rMs, $rUs, $rNs] = self::balanceNsToFields($roundedNsInt, $luIdx);
            } else {
                $totalNsFloat = (float) $dayNsActual + (float) $subDayNs;
                $roundedNsFloat = self::roundNsFloat($totalNsFloat, (float) $nsIncrement, $roundingMode);
                if (($roundedNsFloat / 1_000_000_000.0) >= 9_007_199_254_740_992.0) {
                    throw new RangeError('Duration time fields exceed the maximum representable range after rounding.');
                }
                [$rDays, $rH, $rM, $rS, $rMs, $rUs, $rNs] = self::balanceNsToFields((int) $roundedNsFloat, $luIdx);
            }
            $signF = (float) $sign;
            return new Duration(
                0,
                0,
                0,
                $signF * (float) $rDays,
                $signF * (float) $rH,
                $signF * (float) $rM,
                $signF * (float) $rS,
                $signF * (float) $rMs,
                $signF * (float) $rUs,
                $signF * (float) $rNs,
            );
        }
        if ($absD <= 106_750) {
            $totalNsInt = ($absD * 86_400_000_000_000) + $subDayNs;
            // Round the total nanoseconds (int path).
            $roundedNsInt = self::roundNsPositive($totalNsInt, $nsIncrement, $roundingMode);
            // Validate rounded result is within MaxTimeDuration (MAX_SAFE_INT seconds).
            // MaxTimeDuration = 9_007_199_254_740_991 seconds + 999_999_999 ns.
            // 9_007_199_254_740_992 * 1e9 exceeds MaxTimeDuration, so use >=.
            if (((float) $roundedNsInt / 1_000_000_000.0) >= 9_007_199_254_740_992.0) {
                throw new RangeError('Duration time fields exceed the maximum representable range after rounding.');
            }
            // Balance the rounded ns into fields up to largestUnit.
            [$rDays, $rH, $rM, $rS, $rMs, $rUs, $rNs] = self::balanceNsToFields($roundedNsInt, $luIdx);
        } else {
            // Float path: totalNs > PHP_INT_MAX.
            $totalNsFloat = ((float) $absD * 86_400_000_000_000.0) + (float) $subDayNs;
            $roundedNsFloat = self::roundNsFloat($totalNsFloat, (float) $nsIncrement, $roundingMode);
            // Validate rounded result.
            if (($roundedNsFloat / 1_000_000_000.0) >= 9_007_199_254_740_992.0) {
                throw new RangeError('Duration time fields exceed the maximum representable range after rounding.');
            }
            // When no rounding occurred (increment=1 or value was already aligned), use exact
            // integer field accumulation to avoid PHP x87 extended-precision errors in balance.
            if ($roundedNsFloat === $totalNsFloat) {
                // Boundary check for largestUnit=nanoseconds: PHP's float arithmetic may round
                // the total ns value DOWN where IEEE 754 requires rounding UP (ties-to-even).
                // This happens when totalSeconds=MAX_SAFE_INT and subNs >= 463_129_088.
                // The constant 463_129_088 = halfUlp(float64(MAX_SAFE_INT * 1e9)) − offset,
                // where offset = exact(MAX_SAFE_INT * 1e9) − float64(MAX_SAFE_INT * 1e9).
                // Derivation: float64(MAX_SAFE_INT * 1e9) = 9007199254740990926258176,
                // exact = 9007199254740991000000000, offset = 73741824,
                // halfUlp = 536870912, threshold = 536870912 − 73741824 = 463129088.
                if ($luIdx === 0) {
                    $totalSecondsExact = ($absD * 86_400) + ($absH * 3_600) + ($absM * 60) + $absS;
                    $subNsExact = ($absMs * 1_000_000) + ($absUs * 1_000) + $absNs;
                    if ($totalSecondsExact === 9_007_199_254_740_991 && $subNsExact >= 463_129_088) {
                        throw new RangeError(
                            'Duration time fields exceed the maximum representable range after rounding.',
                        );
                    }
                }
                [$rDays, $rH, $rM, $rS, $rMs, $rUs, $rNs] = self::accumulateFieldsToUnit(
                    $absD,
                    $absH,
                    $absM,
                    $absS,
                    $absMs,
                    $absUs,
                    $absNs,
                    $luIdx,
                );
                // After accumulation, the top field may have overflowed int64 and been promoted
                // to float by PHP. When the float64-rounded value exceeds MaxTimeDuration, throw.
                // This catches cases like seconds=MAX_SAFE_INT + ms=488 with largestUnit=nanoseconds
                // where the nanoseconds field overflows int64 and rounds up past the limit.
                // The divisors convert the top-field unit back to seconds for comparison.
                $topField = match ($luIdx) {
                    0 => $rNs,
                    1 => $rUs,
                    2 => $rMs,
                    3 => $rS,
                    4 => $rM,
                    5 => $rH,
                    default => $rDays,
                };
                /** @var array<int,float> $TOP_UNIT_TO_NS */
                static $TOP_UNIT_TO_NS = [
                    1_000_000_000.0, // ns: divide by 1e9 to get seconds
                    1_000_000.0, // us: divide by 1e6
                    1_000.0, // ms: divide by 1e3
                    1.0, // s:  no conversion
                    1.0 / 60.0, // min: multiply by 60 → skip (cannot exceed in minutes alone)
                    1.0 / 3_600.0, // h
                    1.0 / 86_400.0, // day
                ];
                if (is_float($topField) && (abs($topField) / $TOP_UNIT_TO_NS[$luIdx]) >= 9_007_199_254_740_992.0) {
                    throw new RangeError('Duration time fields exceed the maximum representable range after rounding.');
                }
            } else {
                // Rounding occurred in float path. Attempt exact integer arithmetic at the
                // coarsest unit level that divides nsIncrement, to avoid float64 precision loss.
                // The spec uses BigInt internally; we simulate by working in a larger unit
                // (µs or ms) where the total fits in int64.
                $result = self::tryRoundExact(
                    $absD,
                    $absH,
                    $absM,
                    $absS,
                    $absMs,
                    $absUs,
                    $absNs,
                    $nsIncrement,
                    $roundingMode,
                    $luIdx,
                );
                if ($result !== null) {
                    [$rDays, $rH, $rM, $rS, $rMs, $rUs, $rNs] = $result;
                } else {
                    [$rDays, $rH, $rM, $rS, $rMs, $rUs, $rNs] = self::balanceNsFloatToFields($roundedNsFloat, $luIdx);
                }
            }
        }

        // Apply sign and return.
        /** @psalm-suppress InvalidOperand — $sign (int) * int|float fields */
        return new Duration(
            0,
            0,
            0,
            $sign * $rDays,
            $sign * $rH,
            $sign * $rM,
            $sign * $rS,
            $sign * $rMs,
            $sign * $rUs,
            $sign * $rNs,
        );
    }

    /**
     * Balances total absolute nanoseconds into time fields up to largestUnit.
     *
     * Field values that exceed 2^53 (Number.MAX_SAFE_INTEGER) are cast to float to
     * simulate JS's float64 storage behavior, matching spec-required precision loss.
     *
     * @param int $totalAbsNs Total non-negative nanoseconds.
     * @param int $largestUnitIdx Unit index (0=ns, 1=us, 2=ms, 3=s, 4=min, 5=h, 6=day).
     * @return array{0: int|float, 1: int|float, 2: int|float, 3: int|float, 4: int|float, 5: int|float, 6: int|float}
     */
    private static function balanceNsToFields(int $totalAbsNs, int $largestUnitIdx): array
    {
        $ns = $totalAbsNs % 1_000;
        $rem = intdiv(num1: $totalAbsNs, num2: 1_000);
        $us = $rem % 1_000;
        $rem = intdiv(num1: $rem, num2: 1_000);
        $ms = $rem % 1_000;
        $rem = intdiv(num1: $rem, num2: 1_000);
        $s = $rem % 60;
        $rem = intdiv(num1: $rem, num2: 60);
        $m = $rem % 60;
        $rem = intdiv(num1: $rem, num2: 60);
        $h = $rem % 24;
        $days = intdiv(num1: $rem, num2: 24);

        // Bubble excess upward when largestUnit is smaller than 'day' (idx 6).
        if ($largestUnitIdx < 6) {
            $h += $days * 24;
            $days = 0;
        }
        if ($largestUnitIdx < 5) {
            $m += $h * 60;
            $h = 0;
        }
        if ($largestUnitIdx < 4) {
            $s += $m * 60;
            $m = 0;
        }
        if ($largestUnitIdx < 3) {
            $ms += $s * 1_000;
            $s = 0;
        }
        if ($largestUnitIdx < 2) {
            $us += $ms * 1_000;
            $ms = 0;
        }
        if ($largestUnitIdx < 1) {
            $ns += $us * 1_000;
            $us = 0;
        }

        // Apply float64 rounding to field values that exceed 2^53 (MAX_SAFE_INTEGER).
        // JS stores Duration fields as float64; integers > 2^53 lose precision when stored.
        // We simulate this by casting to float, which PHP performs with float64 rounding.
        $floatMax = 9_007_199_254_740_992;
        /** @return int|float */
        $f64 = static function (int|float $v) use ($floatMax): int|float {
            if (is_float($v)) {
                return $v;
            }
            return $v >= $floatMax || $v <= -$floatMax ? (float) $v : $v;
        };

        return [$f64($days), $f64($h), $f64($m), $f64($s), $f64($ms), $f64($us), $f64($ns)];
    }

    /**
     * Rounds a non-negative nanosecond total to the given increment using the specified rounding mode.
     *
     * @param int    $ns        Non-negative nanoseconds.
     * @param int    $increment Rounding increment in nanoseconds (>= 1).
     * @param string $mode      TC39 rounding mode name.
     * @return int Rounded nanoseconds (a multiple of $increment).
     * @throws RangeError for unknown rounding modes.
     */
    private static function roundNsPositive(int $ns, int $increment, string $mode): int
    {
        $q = intdiv(num1: $ns, num2: $increment);
        $d1 = $ns - ($q * $increment); // remainder, >= 0
        $r2 = $q + 1;
        if ($mode === 'halfEven') {
            $cmp = $d1 * 2;
            if ($cmp < $increment) {
                $rounded = $q;
            } elseif ($cmp > $increment) {
                $rounded = $r2;
            } else {
                $rounded = ($q % 2) === 0 ? $q : $r2;
            }
        } else {
            $rounded = match ($mode) {
                'trunc', 'floor' => $q,
                'ceil', 'expand' => $d1 === 0 ? $q : $r2,
                'halfExpand', 'halfCeil' => ($d1 * 2) >= $increment ? $r2 : $q,
                'halfTrunc', 'halfFloor' => ($d1 * 2) > $increment ? $r2 : $q,
                default => throw new RangeError("Invalid roundingMode \"{$mode}\"."),
            };
        }
        return $rounded * $increment;
    }

    /**
     * Accumulates exact-integer time fields into a single target-unit representation.
     *
     * Takes already-balanced fields (each within its normal range: h<24, m<60, etc.)
     * and accumulates them upward to largestUnitIdx using exact integer arithmetic.
     * Field values that exceed 2^53 are cast to float64 to simulate JS number storage.
     *
     * @return array{0: int|float, 1: int|float, 2: int|float, 3: int|float, 4: int|float, 5: int|float, 6: int|float}
     */
    private static function accumulateFieldsToUnit(
        int $absD,
        int $absH,
        int $absM,
        int $absS,
        int $absMs,
        int $absUs,
        int $absNs,
        int $largestUnitIdx,
    ): array {
        $floatMax = 9_007_199_254_740_992;
        /** @return int|float */
        $f64 = static function (int|float $v) use ($floatMax): int|float {
            if (is_float($v)) {
                return $v;
            }
            return $v >= $floatMax || $v <= -$floatMax ? (float) $v : $v;
        };

        // Compute the result by distributing all fields into their positions relative to largestUnit.
        // For the top field (largestUnit), accumulate all coarser units into it.
        // For fields below largestUnit, keep the remainder within their normal range.

        // All intermediates fit in int64 for valid durations with absD <= MaxTimeDuration days
        // and fields within their normal ranges after balancing.

        // Nanosecond remainder.
        $ns = $absNs % 1_000;
        $carryUs = intdiv(num1: $absNs, num2: 1_000) + $absUs;

        // Microsecond level.
        $us = $carryUs % 1_000;
        $carryMs = intdiv(num1: $carryUs, num2: 1_000) + $absMs;

        // Millisecond level.
        $ms = $carryMs % 1_000;
        $carryS = intdiv(num1: $carryMs, num2: 1_000) + $absS;

        // Second level.
        $s = $carryS % 60;
        $carryM = intdiv(num1: $carryS, num2: 60) + $absM;

        // Minute level.
        $m = $carryM % 60;
        $carryH = intdiv(num1: $carryM, num2: 60) + $absH;

        // Hour level.
        $h = $carryH % 24;
        $days = intdiv(num1: $carryH, num2: 24) + $absD;

        // Now: days, h(0-23), m(0-59), s(0-59), ms(0-999), us(0-999), ns(0-999).
        // Bubble up: if largestUnit is smaller than 'day', fold days into h, etc.
        if ($largestUnitIdx < 6) {
            $h += $days * 24;
            $days = 0;
        }
        if ($largestUnitIdx < 5) {
            $m += $h * 60;
            $h = 0;
        }
        if ($largestUnitIdx < 4) {
            $s += $m * 60;
            $m = 0;
        }
        if ($largestUnitIdx < 3) {
            $ms += $s * 1_000;
            $s = 0;
        }
        if ($largestUnitIdx < 2) {
            $us += $ms * 1_000;
            $ms = 0;
        }
        if ($largestUnitIdx < 1) {
            $ns += $us * 1_000;
            $us = 0;
        }

        return [$f64($days), $f64($h), $f64($m), $f64($s), $f64($ms), $f64($us), $f64($ns)];
    }

    /**
     * Attempts to round and balance using exact int64 arithmetic by working in a coarser unit.
     *
     * The spec uses BigInt for NormalizedTimeDuration. This method simulates that by finding
     * the coarsest unit (µs or ms) whose per-unit nanosecond count evenly divides nsIncrement,
     * computing the total in that unit as an exact int64, rounding, and balancing back to fields.
     *
     * Returns null if integer arithmetic is not feasible (totalInUnit overflows int64, or no
     * suitable coarser unit evenly divides nsIncrement).
     *
     * @param int    $absD         Absolute days after balance.
     * @param int    $absH         Absolute hours (0-23).
     * @param int    $absM         Absolute minutes (0-59).
     * @param int    $absS         Absolute seconds (0-59).
     * @param int    $absMs        Absolute milliseconds (0-999).
     * @param int    $absUs        Absolute microseconds (0-999).
     * @param int    $absNs        Absolute nanoseconds (0-999).
     * @param int    $nsIncrement  Rounding increment in nanoseconds.
     * @param string $roundingMode TC39 rounding mode.
     * @param int    $luIdx        Largest unit index (0=ns … 6=day).
     * @return array{0:int|float,1:int|float,2:int|float,3:int|float,4:int|float,5:int|float,6:int|float}|null
     */
    private static function tryRoundExact(
        int $absD,
        int $absH,
        int $absM,
        int $absS,
        int $absMs,
        int $absUs,
        int $absNs,
        int $nsIncrement,
        string $roundingMode,
        int $luIdx,
    ): ?array {
        // The float path is taken because the total nanosecond count overflows int64.
        // Attempt integer arithmetic at a coarser level (ms or µs) to avoid float64 precision loss.
        // The spec uses BigInt; this is an approximation valid when nsIncrement is divisible by the
        // working unit's nanosecond count and no sub-unit remainder exists.

        $floatMax = 9_007_199_254_740_992;
        /** @return int|float */
        $f64 = static function (int|float $v) use ($floatMax): int|float {
            if (is_float($v)) {
                return $v;
            }
            return $v >= $floatMax || $v <= -$floatMax ? (float) $v : $v;
        };

        // Try ms level first (coarser), then µs level.
        // Entry: [nsPerWorkUnit, d-coeff, h-coeff, m-coeff, s-coeff, ms-coeff, us-coeff-in-work-unit]
        foreach ([
            [1_000_000, 86_400_000,     3_600_000,     60_000,     1_000,     1,     0], // ms level
            [1_000,     86_400_000_000, 3_600_000_000, 60_000_000, 1_000_000, 1_000, 1], // µs level
        ] as [$nsPerWu, $dC, $hC, $mC, $sC, $msC, $usC]) {
            if (($nsIncrement % $nsPerWu) !== 0) {
                continue;
            }
            $incWu = intdiv(num1: $nsIncrement, num2: $nsPerWu);

            // Verify that no precision is lost by working at this level.
            // For ms: sub-ms fields (us, ns) must be zero.
            // For µs: sub-µs field (ns) must be zero.
            if ($nsPerWu === 1_000_000 && ($absUs !== 0 || $absNs !== 0)) {
                continue;
            }
            if ($nsPerWu === 1_000 && $absNs !== 0) {
                continue;
            }

            // Guard against int64 overflow in the total computation.
            $floatTotal =
                ((float) $absD * (float) $dC)
                + ((float) $absH * (float) $hC)
                + ((float) $absM * (float) $mC)
                + ((float) $absS * (float) $sC)
                + ((float) $absMs * (float) $msC)
                + ((float) $absUs * (float) $usC);
            if ($floatTotal >= (float) PHP_INT_MAX || $floatTotal <= (float) PHP_INT_MIN) {
                continue;
            }

            $totalWu =
                ($absD * $dC) + ($absH * $hC) + ($absM * $mC) + ($absS * $sC) + ($absMs * $msC) + ($absUs * $usC);
            $roundedWu = self::roundNsPositive($totalWu, $incWu, $roundingMode);

            // Decompose roundedWu back into fields.
            // First separate the sub-ms parts (us, ns are always zero at this point since
            // the rounded value is a multiple of incWu which is >= 1 ms or >= 1 µs).
            if ($nsPerWu === 1_000_000) {
                // Working in ms: us and ns remainders are zero.
                $rNs = 0;
                $rUs = 0;
                $rMs = $roundedWu % 1_000;
                $carry = intdiv(num1: $roundedWu, num2: 1_000);
            } else {
                // Working in µs: ns remainder is zero. Separate us and ms.
                $rNs = 0;
                $rUs = $roundedWu % 1_000;
                $rMs = intdiv(num1: $roundedWu, num2: 1_000) % 1_000;
                $carry = intdiv(num1: $roundedWu, num2: 1_000_000);
            }
            $rS = $carry % 60;
            $carry = intdiv(num1: $carry, num2: 60);
            $rM = $carry % 60;
            $carry = intdiv(num1: $carry, num2: 60);
            $rH = $carry % 24;
            $rD = intdiv(num1: $carry, num2: 24);

            // Bubble up for largestUnit < day.
            if ($luIdx < 6) {
                $rH += $rD * 24;
                $rD = 0;
            }
            if ($luIdx < 5) {
                $rM += $rH * 60;
                $rH = 0;
            }
            if ($luIdx < 4) {
                $rS += $rM * 60;
                $rM = 0;
            }
            if ($luIdx < 3) {
                $rMs += $rS * 1_000;
                $rS = 0;
            }
            if ($luIdx < 2) {
                $rUs += $rMs * 1_000;
                $rMs = 0;
            }
            if ($luIdx < 1) {
                $rNs += $rUs * 1_000;
                $rUs = 0;
            }

            return [$f64($rD), $f64($rH), $f64($rM), $f64($rS), $f64($rMs), $f64($rUs), $f64($rNs)];
        }

        return null;
    }

    /**
     * Float-based rounding for very large nanosecond totals (> PHP_INT_MAX).
     * Uses float64 arithmetic to match JS's Number semantics for large values.
     *
     * @param float  $ns        Non-negative nanoseconds as float.
     * @param float  $increment Rounding increment (nanoseconds).
     * @param string $mode      TC39 rounding mode name.
     */
    private static function roundNsFloat(float $ns, float $increment, string $mode): float
    {
        $q = floor($ns / $increment);
        $d1 = $ns - ($q * $increment); // >= 0
        $r2 = $q + 1.0;
        if ($mode === 'halfEven') {
            $cmp = $d1 * 2.0;
            if ($cmp < $increment) {
                $rounded = $q;
            } elseif ($cmp > $increment) {
                $rounded = $r2;
            } else {
                $rounded = fmod(num1: $q, num2: 2.0) === 0.0 ? $q : $r2;
            }
        } else {
            $rounded = match ($mode) {
                'trunc', 'floor' => $q,
                'ceil', 'expand' => $d1 === 0.0 ? $q : $r2,
                'halfExpand', 'halfCeil' => ($d1 * 2.0) >= $increment ? $r2 : $q,
                'halfTrunc', 'halfFloor' => ($d1 * 2.0) > $increment ? $r2 : $q,
                default => throw new RangeError("Invalid roundingMode \"{$mode}\"."),
            };
        }
        return $rounded * $increment;
    }

    /**
     * Float-based balance of nanoseconds into time fields up to largestUnit.
     * Produces float64-rounded field values, matching JS Number semantics.
     *
     * @param float $totalAbsNs Non-negative nanoseconds as float.
     * @param int   $largestUnitIdx Unit index (0=ns, 1=us, 2=ms, 3=s, 4=min, 5=h, 6=day).
     * @return array{0: int|float, 1: int|float, 2: int|float, 3: int|float, 4: int|float, 5: int|float, 6: int|float}
     */
    private static function balanceNsFloatToFields(float $totalAbsNs, int $largestUnitIdx): array
    {
        // Convert to the target largest unit using float division, then distribute downward.
        // This matches JS's approach of computing the balance via float64 arithmetic.
        $floatMax = (float) PHP_INT_MAX;
        $toIntSafe = static fn(float $v): int|float => abs($v) < $floatMax ? (int) $v : $v;

        $days = 0;
        $ns = $totalAbsNs;

        if ($largestUnitIdx >= 6) {
            $days = floor($ns / 86_400_000_000_000.0);
            $ns -= $days * 86_400_000_000_000.0;
        }
        $h = 0;
        if ($largestUnitIdx >= 5) {
            $h = floor($ns / 3_600_000_000_000.0);
            $ns -= $h * 3_600_000_000_000.0;
        }
        $m = 0;
        if ($largestUnitIdx >= 4) {
            $m = floor($ns / 60_000_000_000.0);
            $ns -= $m * 60_000_000_000.0;
        }
        $s = 0;
        if ($largestUnitIdx >= 3) {
            $s = floor($ns / 1_000_000_000.0);
            $ns -= $s * 1_000_000_000.0;
        }
        $ms = 0;
        if ($largestUnitIdx >= 2) {
            $ms = floor($ns / 1_000_000.0);
            $ns -= $ms * 1_000_000.0;
        }
        $us = 0;
        if ($largestUnitIdx >= 1) {
            $us = floor($ns / 1_000.0);
            $ns -= $us * 1_000.0;
        }

        return [
            $toIntSafe($days),
            $toIntSafe($h),
            $toIntSafe($m),
            $toIntSafe($s),
            $toIntSafe($ms),
            $toIntSafe($us),
            $toIntSafe($ns),
        ];
    }

    /**
     * Determines the 'auto' largestUnit index: largest non-zero time field
     * among days(6), hours(5), minutes(4), seconds(3), ms(2), us(1), ns(0).
     * Falls back to $suIdx when all fields are zero.
     */
    private static function autoLargestUnit(Duration $d, int $suIdx): int
    {
        if ($d->days !== 0) {
            return 6;
        }
        if ($d->hours !== 0) {
            return 5;
        }
        if ($d->minutes !== 0) {
            return 4;
        }
        if ($d->seconds !== 0) {
            return 3;
        }
        if ($d->milliseconds !== 0) {
            return 2;
        }
        if ($d->microseconds !== 0) {
            return 1;
        }
        if ($d->nanoseconds !== 0) {
            return 0;
        }
        return $suIdx;
    }

    /**
     * Determines the largest non-zero unit index including calendar fields.
     * Used when largestUnit is 'auto' for calendar rounding.
     * Returns 0 (nanoseconds) when all fields are zero.
     */
    private static function autoLargestUnitCalendar(Duration $d, int $suIdx): int
    {
        if ($d->years !== 0) {
            return 9;
        }
        if ($d->months !== 0) {
            return 8;
        }
        if ($d->weeks !== 0) {
            return 7;
        }
        return self::autoLargestUnit($d, $suIdx);
    }

    /**
     * Applies calendar rounding to select either $r1 or $r2 based on progress and mode.
     * For NudgeToCalendarUnit: $r1 and $r2 are the lower and upper calendar boundaries,
     * $progress is (total - r1) / (r2 - r1) in [0, 1].
     *
     * @param int    $r1       Lower boundary count (in the calendar unit).
     * @param int    $r2       Upper boundary count (= $r1 + $increment).
     * @param float  $progress Fractional progress from r1 to r2 (0 = at r1, 1 = at r2).
     * @param string $mode     TC39 rounding mode.
     * @param bool   $positive Whether the duration is positive.
     */
    private static function applyCalendarRounding(int $r1, int $r2, float $progress, string $mode, bool $positive): int
    {
        if ($progress >= 1.0) {
            return $r2;
        }
        // When progress = 0, the value is exactly at r1; all rounding modes return r1.
        if ($progress === 0.0) {
            return $r1;
        }
        if ($mode === 'halfFloor') {
            if ($positive) {
                return $progress > 0.5 ? $r2 : $r1;
            }
            return $progress >= 0.5 ? $r2 : $r1;
        }
        if ($mode === 'halfCeil') {
            if ($positive) {
                return $progress >= 0.5 ? $r2 : $r1;
            }
            return $progress > 0.5 ? $r2 : $r1;
        }
        if ($mode === 'halfEven') {
            if ($progress > 0.5) {
                return $r2;
            }
            if ($progress < 0.5) {
                return $r1;
            }
            return ($r1 % 2) === 0 ? $r1 : $r2;
        }
        return match ($mode) {
            'trunc' => $r1,
            'floor' => $positive ? $r1 : $r2,
            'ceil' => $positive ? $r2 : $r1,
            'expand' => $r2,
            'halfExpand' => $progress >= 0.5 ? $r2 : $r1,
            'halfTrunc' => $progress > 0.5 ? $r2 : $r1,
            default => throw new RangeError("Invalid roundingMode \"{$mode}\"."),
        };
    }

    /**
     * Balances signed total days into years, months, weeks, days relative to $startDate.
     * Implements BalanceDateDurationRelative for PlainDate.
     *
     * @param \DateTimeImmutable $startDate UTC midnight on relativeTo date.
     * @param int $totalDays Signed total days to balance.
     * @param int $luIdx Largest unit index (6=days, 7=weeks, 8=months, 9=years).
     * @param int $suIdx Smallest unit index.
     * @return array{0: int, 1: int, 2: int, 3: int} [years, months, weeks, days]
     */
    private static function balanceDateDuration(
        \DateTimeImmutable $startDate,
        int $totalDays,
        int $luIdx,
        int $suIdx,
    ): array {
        if ($totalDays === 0) {
            return [0, 0, 0, 0];
        }
        $sign = $totalDays > 0 ? 1 : -1;
        $dir = $sign > 0 ? '+' : '-';
        $absDays = abs($totalDays);
        $endDate = $startDate->modify("{$dir}{$absDays} days");

        $years = 0;
        $months = 0;
        $weeks = 0;

        $current = $startDate;

        // Accumulate full years when largestUnit >= 'years'.
        if ($luIdx >= 9) {
            while (true) {
                $next = AnchorMath::addYearsClamped($current, $sign);
                if ($sign > 0 ? $next > $endDate : $next < $endDate) {
                    break;
                }
                $years++;
                $current = $next;
            }
        }

        // Accumulate full months when largestUnit >= 'months' and smallestUnit != 'years'.
        if ($luIdx >= 8 && $suIdx < 9) {
            while (true) {
                $next = AnchorMath::addMonthsClamped($current, $sign);
                if ($sign > 0 ? $next > $endDate : $next < $endDate) {
                    break;
                }
                $months++;
                $current = $next;
            }
        }

        // remainingDays is signed (negative when direction is negative).
        $remainingDays = (int) $current->diff($endDate)->format('%r%a');

        // Distribute remaining days into weeks when:
        // - largestUnit is exactly 'weeks' (idx=7): weeks are the top unit, so split remaining into weeks+days.
        // - smallestUnit is 'weeks' (suIdx=7): weeks must appear in the output (e.g. 5Y 7M 4W).
        //   In this case days would be 0 (since we rounded to a week boundary).
        // When largestUnit > 'weeks' (months/years) and smallestUnit < 'weeks' (days/hours/...),
        // remaining days stay as plain days (no weeks distribution).
        if ($luIdx === 7 || $suIdx === 7) {
            $weeks = intdiv(num1: $remainingDays, num2: 7);
            $remainingDays %= 7;
        }

        // $years and $months are unsigned counters; apply sign. $weeks and $remainingDays
        // are already signed (derived from the signed diff), so return them directly.
        return [$sign * $years, $sign * $months, $weeks, $remainingDays];
    }

    /**
     * Implements Duration::round() when calendar arithmetic is needed (relativeTo is a PlainDate).
     *
     * @param mixed $rtRaw Already-validated relativeTo value (PlainDate, string, or array).
     * @param ?string $suNorm Normalized smallestUnit or null.
     * @param bool $luIsAuto Whether largestUnit is 'auto'.
     * @param ?string $luNorm Normalized largestUnit or null.
     * @param int $increment Rounding increment.
     * @param string $roundingMode TC39 rounding mode.
     * @param array<string,int> $UNIT_IDX Unit name → index mapping.
     */
    private static function roundWithRelativeTo(
        Duration $d,
        mixed $rtRaw,
        ?string $suNorm,
        bool $luIsAuto,
        ?string $luNorm,
        int $increment,
        string $roundingMode,
        array $UNIT_IDX,
    ): Duration {
        $bag = RelativeTo::toPlainDateBag($rtRaw);
        $zdtInfoRWR = RelativeTo::resolveZdt($rtRaw);
        // When relativeTo resolves to a ZonedDateTime, use the ZDT's local date
        // (which accounts for UTC offset to local time conversion, e.g. Z+IANA strings).
        if ($zdtInfoRWR !== null) {
            $bag = [
                'year' => $zdtInfoRWR['year'],
                'month' => $zdtInfoRWR['month'],
                'day' => $zdtInfoRWR['day'],
            ];
        }
        $tz = new \DateTimeZone('UTC');
        $startDate = new \DateTimeImmutable('now', $tz)
            ->setDate($bag['year'], $bag['month'], $bag['day'])
            ->setTime(0, 0, 0);

        // Compute effective largestUnit index.
        $suIdx = $suNorm !== null ? $UNIT_IDX[$suNorm] : 0;
        if ($luIsAuto) {
            $luIdx = self::autoLargestUnitCalendar($d, $suIdx);
            if ($luIdx < $suIdx) {
                $luIdx = $suIdx;
            }
        } else {
            $luIdx = $UNIT_IDX[$luNorm ?? 'nanoseconds'];
        }

        if ($luIdx < $suIdx) {
            throw new RangeError('largestUnit must be at least as large as smallestUnit.');
        }

        // TC39: disallow increment > 1 when balancing to a larger calendar-or-day unit.
        // e.g. smallestUnit='months', largestUnit='years', increment=8 → RangeError.
        // e.g. smallestUnit='days', largestUnit='weeks', increment=30 → RangeError.
        if ($increment > 1 && $luIdx > $suIdx && $suIdx >= 6) {
            throw new RangeError(
                "roundingIncrement > 1 is not allowed when smallestUnit is \"{$suNorm}\" and largestUnit is a larger unit.",
            );
        }

        // Apply the full duration to the start date to get end date + calendar day count.
        // applyCalendarToDate throws RangeError if totalDays > ±100M.
        [, $calendarDays] = AnchorMath::applyCalendarToDate($d, $startDate);

        $timeNs =
            ((int) $d->hours * 3_600_000_000_000)
            + ((int) $d->minutes * 60_000_000_000)
            + ((int) $d->seconds * 1_000_000_000)
            + ((int) $d->milliseconds * 1_000_000)
            + ((int) $d->microseconds * 1_000)
            + (int) $d->nanoseconds;

        $nsPerDay = 86_400_000_000_000;

        // Total nanoseconds = calendar days * nsPerDay + time fields.
        $totalNs = ($calendarDays * $nsPerDay) + $timeNs;
        $isPositive = $totalNs >= 0;

        // Guard: a time component so large it balances into a date beyond the representable
        // range (±100 000 000 days) must throw RangeError up front — TC39 AdjustDateDurationRecord
        // (round §28.d) — rather than spin the calendar-nudge loop forever. $totalNs may have
        // overflowed to a float (e.g. seconds = 2^53 - 1), so divide as float.
        if (abs((float) $totalNs / (float) $nsPerDay) > 100_000_000.0) {
            throw new RangeError('Duration with relativeTo exceeds the maximum representable date range.');
        }

        // -----------------------------------------------------------------------
        // Round based on smallestUnit
        // -----------------------------------------------------------------------

        if ($suIdx >= 8) {
            // Smallest unit is months or years: NudgeToCalendarUnit
            return self::nudgeToCalendarMonthsOrYears(
                $d,
                $startDate,
                $totalNs,
                $nsPerDay,
                $suIdx,
                $luIdx,
                $increment,
                $roundingMode,
                $isPositive,
                $zdtInfoRWR,
            );
        }

        if ($suIdx === 7) {
            // Smallest unit is weeks: NudgeToCalendarUnit for weeks
            return self::nudgeToCalendarWeeks(
                $d,
                $startDate,
                $totalNs,
                $nsPerDay,
                $luIdx,
                $increment,
                $roundingMode,
                $isPositive,
            );
        }

        // Smallest unit is days or smaller: NudgeToTimeUnit
        /** @var array<string,int> */
        static $NS_PER_UNIT = [
            'nanoseconds' => 1,
            'microseconds' => 1_000,
            'milliseconds' => 1_000_000,
            'seconds' => 1_000_000_000,
            'minutes' => 60_000_000_000,
            'hours' => 3_600_000_000_000,
        ];
        $suNormResolved = $suNorm ?? 'nanoseconds';

        // Validate sub-day increment: must be strictly less than next-higher-unit count and divide it evenly.
        // Per TC39: e.g. minutes increment must be < 60 and divide 60 evenly.
        if ($suIdx < 6) {
            /** @var array<string,int> */
            static $MAX_PER_UNIT_RWR = [
                'nanoseconds' => 1_000,
                'microseconds' => 1_000,
                'milliseconds' => 1_000,
                'seconds' => 60,
                'minutes' => 60,
                'hours' => 24,
            ];
            $maxPerUnit = $MAX_PER_UNIT_RWR[$suNormResolved] ?? 1;
            if ($increment >= $maxPerUnit) {
                throw new RangeError("roundingIncrement {$increment} is too large for unit \"{$suNormResolved}\".");
            }
            if (($maxPerUnit % $increment) !== 0) {
                throw new RangeError(
                    "roundingIncrement {$increment} does not evenly divide into the next unit for \"{$suNormResolved}\".",
                );
            }
        }

        // Round the signed total nanoseconds.
        // TC39 uses signed (ApplyUnsignedRoundingMode on signed fractional value), so for negative
        // durations floor rounds toward -∞ (larger abs) and ceil rounds toward zero (smaller abs).
        // Since roundNsPositive works on absolute values, swap floor↔ceil and halfFloor↔halfCeil
        // when the duration is negative so the absolute-value rounding matches signed semantics.
        $sign = $totalNs >= 0 ? 1 : -1;
        $absNs = abs($totalNs);
        $signedMode = $sign < 0
            ? match ($roundingMode) {
                'floor' => 'ceil',
                'ceil' => 'floor',
                'halfFloor' => 'halfCeil',
                'halfCeil' => 'halfFloor',
                default => $roundingMode,
            }
            : $roundingMode;

        // For 'days' smallest unit: work in day units to avoid int64 overflow when increment is large
        // (e.g. roundingIncrement=1e9 days → nsIncrement=8.64e22 would overflow PHP_INT_MAX=9.2e18).
        // For sub-day units: round in nanoseconds using integer arithmetic.
        $roundedAbsNs = 0; // initialised here; only used in the sub-day path (luIdx < 6)
        if ($suNormResolved === 'days') {
            if ($zdtInfoRWR !== null) {
                // For ZDT: use DST-aware day lengths to compute fractional days.
                // Balance the time portion into days using actual day lengths first,
                // then compute the fractional remainder for rounding.
                $calDateEnd = $startDate;
                $applySign = $d->sign;
                if ((int) $d->years !== 0) {
                    $calDateEnd = AnchorMath::addYearsClamped($calDateEnd, $applySign * abs((int) $d->years));
                }
                if ((int) $d->months !== 0) {
                    $calDateEnd = AnchorMath::addMonthsClamped($calDateEnd, $applySign * abs((int) $d->months));
                }
                if ((int) $d->weeks !== 0) {
                    $awDays = $applySign * abs((int) $d->weeks) * 7;
                    $calDateEnd = $calDateEnd->modify(sprintf('%+d days', $awDays));
                }
                $absRawDays = abs((int) $d->days);
                $absTimeOnlyNs = abs($timeNs);
                $calEndY = (int) $calDateEnd->format('Y');
                $calEndM = (int) $calDateEnd->format('n');
                $calEndD = (int) $calDateEnd->format('j');
                // Balance raw time ns into DST-aware days.
                [$timeDays, $remainNs] = AnchorMath::zdtBalanceTimeToDays(
                    $calEndY,
                    $calEndM,
                    $calEndD,
                    $zdtInfoRWR['hour'],
                    $zdtInfoRWR['minute'],
                    $zdtInfoRWR['second'],
                    $zdtInfoRWR['tzId'],
                    $absTimeOnlyNs,
                    $absRawDays,
                    $sign,
                );
                $totalAbsDays = $timeDays;
                // Compute fractional day from the remainder using the next day's actual length.
                $afterDaysDate = $calDateEnd->modify(sprintf('%+d days', $sign * $totalAbsDays));
                $nextDayLengthSec = abs(AnchorMath::zdtDayLengthSec(
                    (int) $afterDaysDate->format('Y'),
                    (int) $afterDaysDate->format('n'),
                    (int) $afterDaysDate->format('j'),
                    $zdtInfoRWR['hour'],
                    $zdtInfoRWR['minute'],
                    $zdtInfoRWR['second'],
                    $zdtInfoRWR['tzId'],
                ));
                $nextDayLengthNs = $nextDayLengthSec * 1_000_000_000;
                $fracDay = $nextDayLengthNs > 0 ? (float) $remainNs / (float) $nextDayLengthNs : 0.0;
                $totalAbsDaysF = (float) $totalAbsDays + $fracDay;
                $roundedAbsDays = (int) self::roundNsFloat($totalAbsDaysF, (float) $increment, $signedMode);
                // Re-add calendar-only days.
                $roundedDays = $sign * ($roundedAbsDays + abs($calendarDays) - $absRawDays);
            } else {
                // Express total as fractional days and round using float arithmetic.
                $totalAbsDaysF = (float) $absNs / (float) $nsPerDay;
                $roundedAbsDays = (int) self::roundNsFloat($totalAbsDaysF, (float) $increment, $signedMode);
                $roundedDays = $sign * $roundedAbsDays;
            }
            $subDayNs = 0;
        } else {
            $nsPerSmallest = $NS_PER_UNIT[$suNormResolved] ?? 1;
            $nsIncrement = $nsPerSmallest * $increment;
            // For ZDT IANA: balance time into DST-aware days BEFORE rounding,
            // round only the sub-day remainder, then check for day overflow.
            if ($zdtInfoRWR !== null) {
                // Compute the date after adding calendar fields (years/months/weeks) only.
                $calDateEnd = $startDate;
                $applySign = $d->sign;
                if ((int) $d->years !== 0) {
                    $calDateEnd = AnchorMath::addYearsClamped($calDateEnd, $applySign * abs((int) $d->years));
                }
                if ((int) $d->months !== 0) {
                    $calDateEnd = AnchorMath::addMonthsClamped($calDateEnd, $applySign * abs((int) $d->months));
                }
                if ((int) $d->weeks !== 0) {
                    $awDays = $applySign * abs((int) $d->weeks) * 7;
                    $calDateEnd = $calDateEnd->modify(sprintf('%+d days', $awDays));
                }
                // Get the raw time-only nanoseconds (H/M/S/ms/us/ns + day field converted).
                $absTimeOnlyNs = abs($timeNs) + (abs((int) $d->days) * $nsPerDay);
                $calEndY = (int) $calDateEnd->format('Y');
                $calEndM = (int) $calDateEnd->format('n');
                $calEndD = (int) $calDateEnd->format('j');
                // Balance raw time into DST-aware days before rounding.
                [$preDays, $preSubDayNs] = AnchorMath::zdtBalanceTimeToDays(
                    $calEndY,
                    $calEndM,
                    $calEndD,
                    $zdtInfoRWR['hour'],
                    $zdtInfoRWR['minute'],
                    $zdtInfoRWR['second'],
                    $zdtInfoRWR['tzId'],
                    $absTimeOnlyNs,
                    0,
                    $sign,
                );
                // Round only the sub-day remainder.
                $roundedSubDayNs = self::roundNsPositive($preSubDayNs, $nsIncrement, $signedMode);
                // Check if the rounded value overflows the current day's length.
                $afterPreDaysDate = $calDateEnd->modify(sprintf('%+d days', $sign * $preDays));
                $adY = (int) $afterPreDaysDate->format('Y');
                $adM = (int) $afterPreDaysDate->format('n');
                $adD = (int) $afterPreDaysDate->format('j');
                [$extraDays, $absSubDayNs] = AnchorMath::zdtBalanceTimeToDays(
                    $adY,
                    $adM,
                    $adD,
                    $zdtInfoRWR['hour'],
                    $zdtInfoRWR['minute'],
                    $zdtInfoRWR['second'],
                    $zdtInfoRWR['tzId'],
                    $roundedSubDayNs,
                    0,
                    $sign,
                );
                $roundedAbsDays = $preDays + $extraDays;
                // If time overflowed into an extra day, re-round the new remainder.
                if ($extraDays > 0) {
                    $absSubDayNs = self::roundNsPositive($absSubDayNs, $nsIncrement, $signedMode);
                    $afterAllDaysDate = $calDateEnd->modify(sprintf('%+d days', $sign * $roundedAbsDays));
                    $aaY = (int) $afterAllDaysDate->format('Y');
                    $aaM = (int) $afterAllDaysDate->format('n');
                    $aaD = (int) $afterAllDaysDate->format('j');
                    [$moreDays, $absSubDayNs] = AnchorMath::zdtBalanceTimeToDays(
                        $aaY,
                        $aaM,
                        $aaD,
                        $zdtInfoRWR['hour'],
                        $zdtInfoRWR['minute'],
                        $zdtInfoRWR['second'],
                        $zdtInfoRWR['tzId'],
                        $absSubDayNs,
                        0,
                        $sign,
                    );
                    $roundedAbsDays += $moreDays;
                }
                // For the luIdx < 6 path (largestUnit < days), compute total rounded ns.
                $roundedAbsNs = (($roundedAbsDays + abs($calendarDays)) * $nsPerDay) + $absSubDayNs;
                // Re-add the calendar days to get the total day count for balanceDateDuration.
                $roundedDays = $sign * ($roundedAbsDays + abs($calendarDays));
                $subDayNs = $sign * $absSubDayNs;
            } else {
                $roundedAbsNs = self::roundNsPositive($absNs, $nsIncrement, $signedMode);
                $roundedNs = $sign * $roundedAbsNs;
                $roundedDays = intdiv(num1: $roundedNs, num2: $nsPerDay);
                $subDayNs = $roundedNs - ($roundedDays * $nsPerDay);
            }
        }

        // Balance calendar fields (years/months/weeks/days) from roundedDays.
        if ($luIdx >= 7) {
            // largestUnit is weeks, months, or years: split days into calendar units.
            [$ry, $rm, $rw, $rd] = self::balanceDateDuration($startDate, $roundedDays, $luIdx, $suIdx);
            // Distribute sub-day ns into time fields.
            [$rH, $rM, $rS, $rMs, $rUs, $rNs] = self::distributeSubDayNs($subDayNs);
        } elseif ($luIdx === 6) {
            // largestUnit is days: keep as-is, distribute sub-day to time fields.
            $ry = 0;
            $rm = 0;
            $rw = 0;
            $rd = $roundedDays;
            [$rH, $rM, $rS, $rMs, $rUs, $rNs] = self::distributeSubDayNs($subDayNs);
        } else {
            // largestUnit < days (hours, minutes, seconds, …): fold days into time units.
            // Use balanceNsToFields on the absolute rounded nanoseconds so that excess days
            // are absorbed by the largest time unit (e.g. hours = roundedDays * 24 + subDayH).
            [$rDaysFolded, $rH, $rM, $rS, $rMs, $rUs, $rNs] = self::balanceNsToFields($roundedAbsNs, $luIdx);
            $ry = 0;
            $rm = 0;
            $rw = 0;
            // Cast sign to float so that float * float avoids Psalm strict InvalidOperand errors.
            $signF = (float) $sign;
            $rd = $signF * (float) $rDaysFolded; // should be 0 when luIdx < 6
            $rH = $signF * (float) $rH;
            $rM = $signF * (float) $rM;
            $rS = $signF * (float) $rS;
            $rMs = $signF * (float) $rMs;
            $rUs = $signF * (float) $rUs;
            $rNs = $signF * (float) $rNs;
        }

        return new Duration($ry, $rm, $rw, $rd, $rH, $rM, $rS, $rMs, $rUs, $rNs);
    }

    /**
     * Distributes signed sub-day nanoseconds into time fields.
     * When largestUnit < days (idx < 6), folds days back into hours, etc.
     *
     * @param int $subDayNs Signed sub-day nanoseconds (−86_400_000_000_000 < subDayNs < 86_400_000_000_000).
     * @return array{0: int, 1: int, 2: int, 3: int, 4: int, 5: int} [h, min, s, ms, us, ns]
     */
    private static function distributeSubDayNs(int $subDayNs): array
    {
        $sign = $subDayNs >= 0 ? 1 : -1;
        $abs = abs($subDayNs);

        $rNs = $abs % 1_000;
        $abs = intdiv(num1: $abs, num2: 1_000);
        $rUs = $abs % 1_000;
        $abs = intdiv(num1: $abs, num2: 1_000);
        $rMs = $abs % 1_000;
        $abs = intdiv(num1: $abs, num2: 1_000);
        $rS = $abs % 60;
        $abs = intdiv(num1: $abs, num2: 60);
        $rM = $abs % 60;
        $abs = intdiv(num1: $abs, num2: 60);
        $rH = $abs; // remaining hours (< 24 for valid sub-day ns)

        return [$sign * $rH, $sign * $rM, $sign * $rS, $sign * $rMs, $sign * $rUs, $sign * $rNs];
    }

    /**
     * NudgeToCalendarUnit for smallestUnit = 'weeks'.
     * Finds the nearest week boundary relative to startDate and rounds.
     *
     * @param \DateTimeImmutable $startDate UTC midnight on relativeTo date.
     * @param int $totalNs Signed total nanoseconds from start to end.
     * @param int $nsPerDay Nanoseconds per day (86_400_000_000_000).
     * @param int $luIdx Largest unit index.
     * @param int $increment Rounding increment in weeks.
     * @param string $roundingMode TC39 rounding mode.
     * @param bool $isPositive Whether the duration is positive.
     */
    private static function nudgeToCalendarWeeks(
        Duration $d,
        \DateTimeImmutable $startDate,
        int $totalNs,
        int $nsPerDay,
        int $luIdx,
        int $increment,
        string $roundingMode,
        bool $isPositive,
    ): Duration {
        $sign = $totalNs >= 0 ? 1 : -1;
        // Work with absolute nanoseconds throughout so that applyCalendarRounding
        // receives unsigned r1/r2 values (same pattern as nudgeToCalendarMonthsOrYears).
        $absNs = abs($totalNs);

        if ($luIdx >= 8) {
            // When largestUnit >= months: first count full calendar months from startDate
            // in the sign direction, then round the remaining fractional weeks.
            // The month count is not stored; only $current (the last whole-month boundary) matters.
            $current = $startDate;
            while (true) {
                $next = AnchorMath::addMonthsClamped($current, $sign);
                $absNextNs = abs((int) $startDate->diff($next)->format('%r%a')) * $nsPerDay;
                if ($absNextNs > $absNs) {
                    break;
                }
                $current = $next;
            }
            // monthsSignedDays is signed (negative when going backward).
            $monthsSignedDays = (int) $startDate->diff($current)->format('%r%a');
            $absMonthsNs = abs($monthsSignedDays) * $nsPerDay;
            $absRemainingNs = $absNs - $absMonthsNs;

            // Round remaining fractional weeks using unsigned counts.
            $absRemainingDaysF = (float) $absRemainingNs / (float) $nsPerDay;
            $nLow = (int) floor($absRemainingDaysF / (7.0 * (float) $increment)) * $increment;
            $r1Ns = $absMonthsNs + ($nLow * 7 * $nsPerDay);
            $r2Ns = $absMonthsNs + (($nLow + $increment) * 7 * $nsPerDay);
            $denominator = $r2Ns - $r1Ns;
            $progress = $denominator === 0 ? 0.0 : (float) ($absNs - $r1Ns) / (float) $denominator;
            // $roundedWeeks is unsigned; sign is applied below.
            $roundedWeeks = self::applyCalendarRounding(
                $nLow,
                $nLow + $increment,
                $progress,
                $roundingMode,
                $isPositive,
            );
            $roundedDays = $monthsSignedDays + ($sign * $roundedWeeks * 7);

            [$ry, $rm, $rw, $rd] = self::balanceDateDuration($startDate, $roundedDays, $luIdx, 7);
            return new Duration($ry, $rm, $rw, $rd, 0, 0, 0, 0, 0, 0);
        }

        // largestUnit = weeks: pure week rounding from absolute total days.
        $absTotalDaysF = (float) $absNs / (float) $nsPerDay;
        $nLow = (int) floor($absTotalDaysF / (7.0 * (float) $increment)) * $increment;
        $r1Ns = $nLow * 7 * $nsPerDay;
        $r2Ns = ($nLow + $increment) * 7 * $nsPerDay;
        $denominator = $r2Ns - $r1Ns;
        $progress = $denominator === 0 ? 0.0 : (float) ($absNs - $r1Ns) / (float) $denominator;
        // $roundedWeeks is unsigned; sign applied below.
        $roundedWeeks = self::applyCalendarRounding($nLow, $nLow + $increment, $progress, $roundingMode, $isPositive);
        $roundedDays = $sign * $roundedWeeks * 7;

        [$ry, $rm, $rw, $rd] = self::balanceDateDuration($startDate, $roundedDays, $luIdx, 7);
        return new Duration($ry, $rm, $rw, $rd, 0, 0, 0, 0, 0, 0);
    }

    /**
     * NudgeToCalendarUnit for smallestUnit = 'months' or 'years'.
     * Finds the nearest month (or year) boundary and rounds.
     *
     * @param \DateTimeImmutable $startDate UTC midnight on relativeTo date.
     * @param int $totalNs Signed total nanoseconds from start to end.
     * @param int $nsPerDay Nanoseconds per day (fixed 86400e9).
     * @param int $suIdx Smallest unit index (8=months, 9=years).
     * @param int $luIdx Largest unit index.
     * @param int $increment Rounding increment in the smallest unit.
     * @param string $roundingMode TC39 rounding mode.
     * @param bool $isPositive Whether the duration is positive.
     * @param ?array{epochSec: int, subNs: int, tzId: string, year: int, month: int, day: int, hour: int, minute: int, second: int} $zdtInfo ZDT info for DST-aware computation.
     */
    private static function nudgeToCalendarMonthsOrYears(
        Duration $d,
        \DateTimeImmutable $startDate,
        int|float $totalNs,
        int $nsPerDay,
        int $suIdx,
        int $luIdx,
        int $increment,
        string $roundingMode,
        bool $isPositive,
        ?array $zdtInfo = null,
    ): Duration {
        // $totalNs is int|float to accommodate calendar progressions across
        // multi-millennium spans where days × NS_PER_DAY exceeds int64. The function
        // never decomposes $totalNs into days/sub-day components except in the ZDT
        // branch (which handles its own conversion against actual epoch seconds);
        // all other uses are sign checks and ratio comparisons that work for both
        // int and float operands.
        $sign = $totalNs >= 0 ? 1 : -1;

        // For ZDT with IANA timezone, recompute totalNs using actual epoch seconds
        // so DST transitions are accounted for.
        if ($zdtInfo !== null) {
            // Decompose $totalNs into (calendarDaysFromNs, timePartNs). The int branch
            // is the common case; the float branch is reached only when calendarDays ×
            // NS_PER_DAY exceeds int64 (~106k days, ~292 years).
            //
            // Static analyzers (Psalm, Mago) infer $totalNs as `int` from the caller's
            // own arithmetic and flag the float branch as dead. The runtime contract is
            // wider — `nudgeToCalendarMonthsOrYears` is widened to `int|float` for exactly
            // the overflow case the spec layer must support — so the suppressions are
            // correct. Keeping the suppressions next to the branch they describe rather
            // than the file head; remove them only if the analyzers learn that PHP's
            // int*int can overflow to float.
            /**
             * @psalm-suppress RedundantCondition
             */
            if (is_int($totalNs)) {
                $calendarDaysFromNs = intdiv($totalNs - ($totalNs % $nsPerDay), $nsPerDay);
                $timePartNs = $totalNs % $nsPerDay;
            } else {
                // @mago-ignore analysis:unreachable-else-clause
                // @mago-ignore analysis:no-value
                $nsPerDayF = (float) $nsPerDay;
                /** @psalm-suppress NoValue */
                $calendarDaysFromNs = (int) (($totalNs - fmod(num1: $totalNs, num2: $nsPerDayF)) / $nsPerDayF);
                /** @psalm-suppress NoValue */
                $timePartNs = (int) fmod(num1: $totalNs, num2: $nsPerDayF);
            }
            $actualDaysSec = (int) AnchorMath::zdtDaysToSec(
                $zdtInfo['year'],
                $zdtInfo['month'],
                $zdtInfo['day'],
                $zdtInfo['hour'],
                $zdtInfo['minute'],
                $zdtInfo['second'],
                $zdtInfo['tzId'],
                $calendarDaysFromNs,
                $zdtInfo['epochSec'],
            );
            $totalNs = ($actualDaysSec * 1_000_000_000) + $timePartNs;
        }

        // Count full months (or years) from startDate that fit within totalNs.
        $isYears = $suIdx >= 9;

        $totalUnits = 0;
        $current = $startDate;
        while (true) {
            $next = $isYears
                ? AnchorMath::addYearsClamped($current, $sign)
                : AnchorMath::addMonthsClamped($current, $sign);
            // Check if the next boundary in ns is still <= totalNs (in absolute terms).
            $nextDays = (int) $startDate->diff($next)->format('%r%a');
            if ($zdtInfo !== null) {
                $nextSec = (int) AnchorMath::zdtDaysToSec(
                    $zdtInfo['year'],
                    $zdtInfo['month'],
                    $zdtInfo['day'],
                    $zdtInfo['hour'],
                    $zdtInfo['minute'],
                    $zdtInfo['second'],
                    $zdtInfo['tzId'],
                    $nextDays,
                    $zdtInfo['epochSec'],
                );
                $nextNs = $nextSec * 1_000_000_000;
            } else {
                $nextNs = $nextDays * $nsPerDay;
            }
            // Compare: if moving positive, $nextNs <= $totalNs; if negative, $nextNs >= $totalNs.
            if ($sign > 0 ? $nextNs > $totalNs : $nextNs < $totalNs) {
                break;
            }
            $totalUnits++;
            $current = $next;
        }

        // Snap to lower increment boundary.
        $nLow = intdiv(num1: $totalUnits, num2: $increment) * $increment;
        $r1 = $nLow;
        $r2 = $nLow + $increment;

        // Compute r1 and r2 dates relative to startDate using TC39-compliant clamped arithmetic.
        $r1Date = $isYears
            ? AnchorMath::addYearsClamped($startDate, $sign * $r1)
            : AnchorMath::addMonthsClamped($startDate, $sign * $r1);
        $r2Date = $isYears
            ? AnchorMath::addYearsClamped($startDate, $sign * $r2)
            : AnchorMath::addMonthsClamped($startDate, $sign * $r2);
        // The upper boundary may fall beyond the representable ISO date-time range
        // when the anchor sits near the limit; per TC39 RoundDuration this is a
        // RangeError. Keeps round() consistent
        // with the total() calendar path.
        AnchorMath::assertCalendarBoundaryInRange($r2Date);
        $r1Days = (int) $startDate->diff($r1Date)->format('%r%a');
        $r2Days = (int) $startDate->diff($r2Date)->format('%r%a');
        if ($zdtInfo !== null) {
            $r1Ns =
                (int) AnchorMath::zdtDaysToSec(
                    $zdtInfo['year'],
                    $zdtInfo['month'],
                    $zdtInfo['day'],
                    $zdtInfo['hour'],
                    $zdtInfo['minute'],
                    $zdtInfo['second'],
                    $zdtInfo['tzId'],
                    $r1Days,
                    $zdtInfo['epochSec'],
                ) * 1_000_000_000;
            $r2Ns =
                (int) AnchorMath::zdtDaysToSec(
                    $zdtInfo['year'],
                    $zdtInfo['month'],
                    $zdtInfo['day'],
                    $zdtInfo['hour'],
                    $zdtInfo['minute'],
                    $zdtInfo['second'],
                    $zdtInfo['tzId'],
                    $r2Days,
                    $zdtInfo['epochSec'],
                ) * 1_000_000_000;
        } else {
            $r1Ns = $r1Days * $nsPerDay;
            $r2Ns = $r2Days * $nsPerDay;
        }

        $denominator = $r2Ns - $r1Ns;
        $progress = $denominator === 0 ? 0.0 : (float) ($totalNs - $r1Ns) / (float) $denominator;
        $roundedUnits = self::applyCalendarRounding($r1, $r2, $progress, $roundingMode, $isPositive);

        // Balance rounded units into the largestUnit.
        if ($suIdx >= 9) {
            // Rounding to years: result has only years (and possibly months if luIdx > 9, but luIdx max is 9).
            return new Duration($sign * $roundedUnits, 0, 0, 0, 0, 0, 0, 0, 0, 0);
        }

        // Rounding to months: balance months into years+months if luIdx >= 9.
        if ($luIdx >= 9) {
            // Convert months → years + remaining months.
            $absMonths = abs($roundedUnits);
            $years = intdiv(num1: $absMonths, num2: 12);
            $remainMonths = $absMonths % 12;
            return new Duration($sign * $years, $sign * $remainMonths, 0, 0, 0, 0, 0, 0, 0, 0);
        }

        return new Duration(0, $sign * $roundedUnits, 0, 0, 0, 0, 0, 0, 0, 0);
    }
}
