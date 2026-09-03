<?php

declare(strict_types=1);

namespace Calendrics\Spec\Internal;

use Calendrics\Exception\RangeError;
use Calendrics\Exception\TypeError;
use Calendrics\Spec\Duration;
use Calendrics\Spec\PlainDate;
use Calendrics\Spec\ZonedDateTime;

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
            $roundTo = Options::requireObject($roundTo, [
                'largestUnit',
                'relativeTo',
                'roundingIncrement',
                'roundingMode',
                'smallestUnit',
            ]);
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
        $zdtRelativeTo = $rtRawForZdt instanceof \Calendrics\Spec\ZonedDateTime;
        $zdtInfoRound = $rtRawForZdt !== null ? RelativeTo::resolveZdt($rtRawForZdt) : null;

        // The anchor with its spelling reduced away, so the range guards below can ask
        // what kind of anchor TC39 would have built rather than how it was written.
        // Resolving it here also range-checks the anchor itself, on both paths.
        $anchor = $relativeToProvided ? RelativeTo::resolveAnchor($rtRawForZdt) : null;

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

        // The relativeTo range guards. They sit ahead of the balancing below because an
        // anchor in an IANA zone routes that through DST-aware day math, where an
        // out-of-range magnitude overflows int64 before any guard could fire.
        if ($anchor !== null) {
            if ($anchor->zoned) {
                if ($anchor->targetOutOfRange($d)) {
                    throw new RangeError(
                        'relativeTo ZonedDateTime is outside the representable range after applying duration.',
                    );
                }
                if ($luIdx >= 6 && $anchor->dayBoundaryOutOfRange()) {
                    throw new RangeError(
                        'Duration with ZonedDateTime relativeTo: the day boundary falls outside the valid range.',
                    );
                }
            } elseif (!$d->blank && $anchor->midnightOutOfRange()) {
                // A blank duration never reaches the conversion to a PlainDateTime: TC39
                // returns as soon as the two ends coincide, which is why this one alone is
                // gated on the duration.
                throw new RangeError(
                    'relativeTo PlainDate is outside the representable range after applying duration.',
                );
            }
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
        $signedMode = $sign === -1 ? self::negateRoundingMode($roundingMode) : $roundingMode;
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
            $roundedSubDayNs = EpochRounding::roundAsIfPositive($subDayNs, $nsIncrement, $signedMode);
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
            $roundedAbsDays = (int) self::roundNsFloat($totalAbsDaysF, (float) $increment, $signedMode);
            if (((float) $roundedAbsDays * 86_400.0) >= 9_007_199_254_740_992.0) {
                throw new RangeError('Duration time fields exceed the maximum representable range after rounding.');
            }
            /** @psalm-suppress InvalidOperand */
            return new Duration(0, 0, 0, $sign * $roundedAbsDays, 0, 0, 0, 0, 0, 0);
        }

        // Round on the exact total, carried as a (whole seconds, sub-second nanoseconds)
        // pair. The spec's TimeDuration is an exact nanosecond count that passes int64 long
        // before it reaches MaxTimeDuration, and no single PHP number can hold it: int64
        // wraps, while float64's ulp up there is milliseconds wide, so a rounded total
        // decomposes back into sub-second junk the rounding was supposed to have erased.
        // Splitting at the second keeps both halves inside int64 and the total exact.
        $subSecNs = ($absMs * 1_000_000) + ($absUs * 1_000) + $absNs;
        if ($zdtInfoRound !== null && $absD > 0) {
            // A day in an IANA zone is whatever the zone says it is. Pass the ZDT's actual
            // epoch so that sub-minute offsets (e.g. Pacific/Niue -11:19:40 vs -11:20:00)
            // are preserved instead of being re-resolved from the wall time via compatible
            // disambiguation.
            $daysSec = abs((int) AnchorMath::zdtDaysToSec(
                $zdtInfoRound['year'],
                $zdtInfoRound['month'],
                $zdtInfoRound['day'],
                $zdtInfoRound['hour'],
                $zdtInfoRound['minute'],
                $zdtInfoRound['second'],
                $zdtInfoRound['tzId'],
                $sign * $absD,
                $zdtInfoRound['epochSec'],
            ));
        } else {
            // The $totalAbsSec guard above already capped the total at 2^53 seconds, which
            // bounds $absD at ~1.04e11 — well clear of int64 once multiplied out.
            $daysSec = $absD * 86_400;
        }
        $totalSec = $daysSec + ($absH * 3_600) + ($absM * 60) + $absS;

        [$roundedSec, $roundedSubNs] = EpochRounding::round($totalSec, $subSecNs, $nsIncrement, $signedMode);

        // MaxTimeDuration = 2^53 × 10^9 − 1 ns, so any whole second at or above 2^53 is out.
        if ($roundedSec >= 9_007_199_254_740_992) {
            throw new RangeError('Duration time fields exceed the maximum representable range after rounding.');
        }

        [$rDays, $rH, $rM, $rS, $rMs, $rUs, $rNs] = self::balanceSecondsToFields($roundedSec, $roundedSubNs, $luIdx);

        // A field the spec stores as a float64 Number can round *up* past MaxTimeDuration
        // even when the exact total sits below it — one ulp below the limit, largestUnit
        // 'nanoseconds' puts ~9.0e24 into a single field whose ulp is over a second. When
        // that store carries the value past the limit the Duration is invalid, so throw
        // rather than hand back a field that no longer describes the rounded total. Only
        // the top field can be large enough to matter; the divisors convert it back to
        // seconds for the comparison.
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
     * Balances an exact (whole seconds, sub-second nanoseconds) total into time fields
     * up to largestUnit.
     *
     * The pair is the exact-arithmetic counterpart of {@see balanceNsToFields}: a total
     * that reaches MaxTimeDuration has no combined nanosecond count that fits int64, but
     * split at the second both halves do, all the way up.
     *
     * Field values that exceed 2^53 (Number.MAX_SAFE_INTEGER) are cast to float to
     * simulate JS's float64 storage behavior, matching spec-required precision loss.
     *
     * @param int $totalSec Total non-negative whole seconds.
     * @param int $subNs Sub-second nanoseconds, in [0, 1e9).
     * @param int $largestUnitIdx Unit index (0=ns, 1=us, 2=ms, 3=s, 4=min, 5=h, 6=day).
     * @return array{0: int|float, 1: int|float, 2: int|float, 3: int|float, 4: int|float, 5: int|float, 6: int|float}
     */
    private static function balanceSecondsToFields(int $totalSec, int $subNs, int $largestUnitIdx): array
    {
        $ns = $subNs % 1_000;
        $us = intdiv(num1: $subNs, num2: 1_000) % 1_000;
        $ms = intdiv(num1: $subNs, num2: 1_000_000);
        $s = $totalSec % 60;
        $m = intdiv(num1: $totalSec, num2: 60) % 60;
        $h = intdiv(num1: $totalSec, num2: 3_600) % 24;
        $days = intdiv(num1: $totalSec, num2: 86_400);

        // Each step hands the whole total to the next-finer unit, so the last one to run
        // is largestUnit and it holds everything coarser than itself.
        if ($largestUnitIdx <= 5) {
            $days = 0;
            $h = intdiv(num1: $totalSec, num2: 3_600);
        }
        if ($largestUnitIdx <= 4) {
            $h = 0;
            $m = intdiv(num1: $totalSec, num2: 60);
        }
        if ($largestUnitIdx <= 3) {
            $m = 0;
            $s = $totalSec;
        }
        if ($largestUnitIdx <= 2) {
            // 2^53 seconds in milliseconds still clears int64 by a factor of ~1.02.
            $s = 0;
            $ms = ($totalSec * 1_000) + intdiv(num1: $subNs, num2: 1_000_000);
        }
        if ($largestUnitIdx <= 1) {
            $ms = 0;
            $us = self::scaleSeconds($totalSec, 1_000_000, intdiv(num1: $subNs, num2: 1_000));
        }
        if ($largestUnitIdx === 0) {
            $us = 0;
            $ns = self::scaleSeconds($totalSec, 1_000_000_000, $subNs);
        }

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
     * $sec × $mult + $add, exact while it fits int64 and float64 beyond. $mult is a power
     * of ten and $add is less than it, so the two are simply digit groups of one number.
     *
     * Only the microsecond and nanosecond totals can push a valid duration past int64,
     * and by then they are orders of magnitude beyond 2^53 — where the spec stores the
     * field as a float64 Number regardless, so nothing the result would have kept is lost.
     * What does matter is rounding to that Number exactly once: multiplying and adding in
     * float rounds twice and can land a whole ulp low, and one ulp up here is over a
     * second — the difference between a duration inside MaxTimeDuration and one past it.
     * Spelling the exact value out in digits and parsing it back rounds once, as the
     * spec's single Number conversion does.
     */
    private static function scaleSeconds(int $sec, int $mult, int $add): int|float
    {
        if ($sec <= intdiv(num1: PHP_INT_MAX - $add, num2: $mult)) {
            return ($sec * $mult) + $add;
        }
        $addDigits = strlen((string) $mult) - 1;
        return floatval(
            $sec . str_pad(string: (string) $add, length: $addDigits, pad_string: '0', pad_type: STR_PAD_LEFT),
        );
    }

    /**
     * Mirrors a directed rounding mode (floor/ceil, halfFloor/halfCeil) across zero;
     * symmetric modes pass through unchanged.
     *
     * TC39 RoundTimeDuration rounds the signed total, and every rounding helper here works
     * on a magnitude with the sign reapplied afterwards, which reverses the two directions.
     * That is not the AsIfPositive rule {@see EpochRounding} is named for: an epoch
     * nanosecond count is a point in time, where `floor` means earlier whatever the sign.
     */
    private static function negateRoundingMode(string $mode): string
    {
        return match ($mode) {
            'floor' => 'ceil',
            'ceil' => 'floor',
            'halfFloor' => 'halfCeil',
            'halfCeil' => 'halfFloor',
            default => $mode,
        };
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
        $sign = $totalNs >= 0 ? 1 : -1;
        $absNs = abs($totalNs);
        $signedMode = $sign === -1 ? self::negateRoundingMode($roundingMode) : $roundingMode;

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
                $roundedSubDayNs = EpochRounding::roundAsIfPositive($preSubDayNs, $nsIncrement, $signedMode);
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
                    $absSubDayNs = EpochRounding::roundAsIfPositive($absSubDayNs, $nsIncrement, $signedMode);
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
                $roundedAbsNs = EpochRounding::roundAsIfPositive($absNs, $nsIncrement, $signedMode);
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
     * @param int|float $totalNs Signed total nanoseconds from start to end.
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
            // NS_PER_DAY exceeds int64 (~106k days, ~292 years) and PHP promotes the
            // product to float.
            if (is_int($totalNs)) {
                $calendarDaysFromNs = intdiv($totalNs - ($totalNs % $nsPerDay), $nsPerDay);
                $timePartNs = $totalNs % $nsPerDay;
            } else {
                $nsPerDayF = (float) $nsPerDay;
                $calendarDaysFromNs = (int) (($totalNs - fmod(num1: $totalNs, num2: $nsPerDayF)) / $nsPerDayF);
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
        // $totalNs and $r1Ns are close together and can both exceed 2^53, where casting
        // each to float before subtracting would discard the low bits that make up the
        // difference. Stay in int whenever $totalNs still is one.
        $elapsedNs = is_int($totalNs) ? $totalNs - $r1Ns : $totalNs - (float) $r1Ns;
        $progress = $denominator === 0 ? 0.0 : (float) $elapsedNs / (float) $denominator;
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
