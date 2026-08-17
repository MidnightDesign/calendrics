<?php

declare(strict_types=1);

namespace Temporal\Spec\Internal;

use Temporal\Exception\RangeError;

/**
 * Internal helpers for timezone identifier normalization and wall-clock-to-epoch
 * resolution shared between ZonedDateTime, PlainDate, PlainDateTime, Instant, and
 * Duration.
 *
 * This class lives in `Temporal\Spec\Internal\` and is therefore not part of the
 * public BC contract. Signatures, behavior, and existence may change between
 * any two releases. External code must not depend on it.
 */
final class TimeZoneHelper
{
    public static function normalizeTimezoneId(string $id, bool $rejectDatetimeStrings = false): string
    {
        // Split caches per flag so the hot path skips the "R\0"/"N\0" prefix
        // concat that the single-cache variant used to build the lookup key.
        /** @var array<string, string> $cacheR */
        static $cacheR = [];
        /** @var array<string, string> $cacheN */
        static $cacheN = [];
        if ($rejectDatetimeStrings) {
            if (array_key_exists($id, $cacheR)) {
                return $cacheR[$id];
            }
            $result = self::normalizeTimezoneIdUncached($id, true);
            if (count($cacheR) >= 1024) {
                $cacheR = [];
            }
            return $cacheR[$id] = $result;
        }
        if (array_key_exists($id, $cacheN)) {
            return $cacheN[$id];
        }
        $result = self::normalizeTimezoneIdUncached($id, false);
        if (count($cacheN) >= 1024) {
            $cacheN = [];
        }
        return $cacheN[$id] = $result;
    }

    private static function normalizeTimezoneIdUncached(string $id, bool $rejectDatetimeStrings): string
    {
        if ($id === '') {
            throw new RangeError('ZonedDateTime timeZoneId must not be empty.');
        }

        // 'UTC' (case-insensitive).
        if (strtoupper($id) === 'UTC') {
            return 'UTC';
        }

        // Reject minus-zero extended year.
        if (preg_match('/^-0{6}(?:[^0-9]|$)/', $id) === 1) {
            throw new RangeError("Invalid timeZoneId \"{$id}\": minus-zero year.");
        }

        // Datetime strings (have a T-separator after a date part).
        $isDatetime = preg_match('/\d{4,}-\d{2}-\d{2}[Tt]|\d{8}[Tt]/', $id) === 1;

        if ($isDatetime) {
            if ($rejectDatetimeStrings) {
                throw new RangeError(
                    "Invalid timeZoneId \"{$id}\": ISO date-time string is not a valid timezone identifier for ZonedDateTime constructor.",
                );
            }
            // Bracket annotation takes precedence.
            $bm = null;
            if (preg_match('/\[(!?[^\]]+)\]/', $id, $bm) === 1) {
                $bracket = $bm[1];
                if (preg_match('/^[+\-]\d{2}:\d{2}:\d{2}/', $bracket) === 1) {
                    throw new RangeError("Invalid timeZoneId \"{$id}\": sub-minute offset in bracket annotation.");
                }
                if (strtoupper($bracket) === 'UTC') {
                    return 'UTC';
                }
                if (preg_match('/^[+\-]\d{2}:\d{2}$/', $bracket) === 1) {
                    return $bracket;
                }
                // IANA name in bracket.
                try {
                    /** @psalm-suppress ArgumentTypeCoercion — $bracket is non-empty (matched by regex) */
                    new \DateTimeZone($bracket);
                    return $bracket;
                } catch (\Exception) {
                    throw new RangeError("Invalid timeZoneId \"{$id}\": unsupported bracket timezone \"{$bracket}\".");
                }
            }
            // No bracket: use inline offset.
            if (preg_match('/[+\-]\d{2}:\d{2}:\d{2}/i', $id) === 1) {
                throw new RangeError("Invalid timeZoneId \"{$id}\": inline offset contains a seconds component.");
            }
            if (preg_match('/[Zz](?:\[|$)/', $id) === 1) {
                return 'UTC';
            }
            // Extended (±HH:MM) and basic (±HHMM) spellings are both valid inline offsets.
            $om = null;
            if (preg_match('/([+\-])(\d{2}):?(\d{2})(?:\[|$)/', $id, $om) === 1) {
                return sprintf('%s%s:%s', $om[1], $om[2], $om[3]);
            }
            throw new RangeError("Invalid timeZoneId \"{$id}\": bare datetime without Z, offset, or bracket.");
        }

        // Pure UTC-offset strings.
        // ±HH:MM
        if (preg_match('/^([+\-]\d{2}):(\d{2})$/', $id) === 1) {
            return $id;
        }
        // ±HHMM → ±HH:MM
        $m = null;
        if (preg_match('/^([+\-])(\d{2})(\d{2})$/', $id, $m) === 1) {
            return sprintf('%s%s:%s', $m[1], $m[2], $m[3]);
        }
        // ±HH → ±HH:00
        if (preg_match('/^([+\-])(\d{2})$/', $id, $m) === 1) {
            return sprintf('%s%s:00', $m[1], $m[2]);
        }
        // Sub-minute offsets → reject.
        if (preg_match('/^[+\-]\d{2}:\d{2}[:.].*/i', $id) === 1) {
            throw new RangeError("Invalid timeZoneId \"{$id}\": sub-minute offset is not a valid timezone identifier.");
        }

        // IANA timezone name: validate via PHP DateTimeZone (case-insensitive).
        try {
            new \DateTimeZone($id);
        } catch (\Exception) {
            throw new RangeError("Invalid timeZoneId \"{$id}\": not a recognized timezone identifier.");
        }

        // Case-normalize the timezone ID using the canonical timezone list.
        /** @var array<string, string>|null $lowerToCanonical */
        static $lowerToCanonical = null;
        if ($lowerToCanonical === null) {
            $lowerToCanonical = [];
            foreach (\DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC) as $ident) {
                $lowerToCanonical[strtolower($ident)] = $ident;
            }
            // PHP doesn't include Etc/UTC in listIdentifiers but accepts it
            $lowerToCanonical['etc/utc'] = 'Etc/UTC';
        }
        // Must be in the IANA timezone list — reject abbreviations like "AST", "EST".
        $lower = strtolower($id);
        if (!array_key_exists($lower, $lowerToCanonical)) {
            throw new RangeError("Invalid timeZoneId \"{$id}\": not a recognized IANA timezone identifier.");
        }
        return $lowerToCanonical[$lower];
    }

    /**
     * Wraps `DateTimeZone::getTransitions()` to always return a narrowed list.
     *
     * The underlying PHP function may return `false` on failure (per the PHP manual);
     * both phpstan stubs (`tools/phpstan-stubs/DateTimeZone.stub`) and mago model this.
     * This helper normalizes both to an empty list and narrows each element to an
     * array of two ints (epoch second + offset second) so the call sites can treat the
     * result as a typed shape.
     *
     * @return list<array{ts: int, offset: int}>
     */
    public static function safeGetTransitions(\DateTimeZone $tz, int $begin, int $end): array
    {
        $result = $tz->getTransitions($begin, $end);
        if ($result === false) {
            return [];
        }
        $out = [];
        foreach ($result as $t) {
            // intval() is used so that mago (whose stubs treat element values as mixed)
            // and phpstan (whose stubs treat them as int) both see an int result.
            $out[] = ['ts' => intval($t['ts']), 'offset' => intval($t['offset'])];
        }
        return $out;
    }

    /**
     * Like wallSecToEpochSec, but for startOfDay: when midnight is in a gap,
     * returns the transition epoch (first valid instant of the day) instead of
     * the regular gap disambiguation.
     */
    public static function wallSecToEpochSecStartOfDay(int $wallSec, string $tzId): int
    {
        if ($tzId === '' || $tzId === 'UTC' || preg_match('/^[+\-]\d{2}:\d{2}$/', $tzId) === 1) {
            return self::wallSecToEpochSec($wallSec, $tzId);
        }
        $tz = new \DateTimeZone($tzId);
        $approxOffset = $tz->getOffset(new \DateTimeImmutable(sprintf('@%d', $wallSec)));
        $epoch1 = $wallSec - $approxOffset;
        $transitions = self::safeGetTransitions($tz, $epoch1 - 86_400, $epoch1 + 86_400);
        $nTransitions = count($transitions);
        if ($nTransitions >= 2) {
            for ($i = 1; $i < $nTransitions; $i++) {
                $tEpoch = $transitions[$i]['ts'];
                $pre = $transitions[$i - 1]['offset'];
                $post = $transitions[$i]['offset'];
                if ($post > $pre) {
                    // Gap: check if wallSec is in [wallAtPre, wallAtPost)
                    $wallAtPre = $tEpoch + $pre;
                    $wallAtPost = $tEpoch + $post;
                    if ($wallSec >= $wallAtPre && $wallSec < $wallAtPost) {
                        // Midnight is in a gap: return the transition epoch.
                        return $tEpoch;
                    }
                }
            }
        }
        return self::wallSecToEpochSec($wallSec, $tzId);
    }

    /**
     * Converts wall-clock seconds (as if UTC) to epoch seconds given a timezone.
     *
     * For 'UTC' / fixed-offset: subtract the fixed offset.
     * For IANA: use PHP DateTimeZone transition data.
     */
    public static function wallSecToEpochSec(int $wallSec, string $tzId, string $disambiguation = 'compatible'): int
    {
        if ($tzId === 'UTC') {
            return $wallSec;
        }
        // Fixed offset ±HH:MM.
        $m = null;
        if (preg_match('/^([+\-])(\d{2}):(\d{2})$/', $tzId, $m) === 1) {
            $sign = $m[1] === '+' ? 1 : -1;
            $offsetSec = $sign * (((int) $m[2] * 3600) + ((int) $m[3] * 60));
            return $wallSec - $offsetSec;
        }
        // IANA: use PHP's DateTimeZone to resolve wall clock to epoch.
        /** @psalm-suppress ArgumentTypeCoercion — $tzId is validated non-empty before this call */
        $tz = new \DateTimeZone($tzId);

        // Get the standard resolution.
        $approxOffset = $tz->getOffset(new \DateTimeImmutable(sprintf('@%d', $wallSec)));
        $epoch1 = $wallSec - $approxOffset;
        $offset1 = $tz->getOffset(new \DateTimeImmutable(sprintf('@%d', $epoch1)));

        // Check for gap/overlap by looking at timezone transitions near this epoch.
        $transitions = self::safeGetTransitions($tz, $epoch1 - 86_400, $epoch1 + 86_400);
        $transitionEpoch = null;
        $preOffset = null;
        $postOffset = null;
        $nTransitions = count($transitions);
        if ($nTransitions >= 2) {
            for ($i = 1; $i < $nTransitions; $i++) {
                $tEpoch = $transitions[$i]['ts'];
                $pre = $transitions[$i - 1]['offset'];
                $post = $transitions[$i]['offset'];
                // Check if the wall time falls in a gap or overlap around this transition.
                $wallAtPre = $tEpoch + $pre;
                $wallAtPost = $tEpoch + $post;
                if ($pre > $post) {
                    // Fall-back (overlap): wallAtPost < wallAtPre, wall times in [wallAtPost, wallAtPre) are ambiguous.
                    if ($wallSec >= $wallAtPost && $wallSec < $wallAtPre) {
                        $transitionEpoch = $tEpoch;
                        $preOffset = $pre;
                        $postOffset = $post;
                        break;
                    }
                } elseif ($post > $pre) {
                    // Spring-forward (gap): wallAtPre < wallAtPost, wall times in [wallAtPre, wallAtPost) don't exist.
                    if ($wallSec >= $wallAtPre && $wallSec < $wallAtPost) {
                        $transitionEpoch = $tEpoch;
                        $preOffset = $pre;
                        $postOffset = $post;
                        break;
                    }
                }
            }
        }

        if ($transitionEpoch !== null && $preOffset !== null && $postOffset !== null) {
            if ($preOffset > $postOffset) {
                // Overlap (fall-back): two valid epochs.
                $earlierEpoch = $wallSec - $preOffset; // Earlier occurrence (before transition, higher offset)
                $laterEpoch = $wallSec - $postOffset; // Later occurrence (after transition, lower offset)
                return match ($disambiguation) {
                    'earlier', 'compatible' => $earlierEpoch,
                    'later' => $laterEpoch,
                    'reject' => throw new RangeError("Ambiguous wall clock time in timezone {$tzId}."),
                    default => $earlierEpoch,
                };
            }
            // Gap (spring-forward): wall time doesn't exist.
            // TC39: resolve by interpreting the wall time in the opposite offset.
            // 'earlier': use post offset → gives an instant before the gap.
            // 'later'/'compatible': use pre offset → gives an instant after the gap.
            $beforeGapEpoch = $wallSec - $postOffset;
            $afterGapEpoch = $wallSec - $preOffset;
            return match ($disambiguation) {
                'compatible', 'later' => $afterGapEpoch,
                'earlier' => $beforeGapEpoch,
                'reject' => throw new RangeError("Non-existent wall clock time in timezone {$tzId}."),
                default => $afterGapEpoch,
            };
        }

        // No gap/overlap: simple resolution.
        return $wallSec - $offset1;
    }
}
