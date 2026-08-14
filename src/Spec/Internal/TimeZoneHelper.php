<?php

declare(strict_types=1);

namespace Temporal\Spec\Internal;

use Temporal\Exception\RangeError;

/**
 * Offset-domain timezone helpers: what a zone's UTC offset is at a given instant, when
 * it next changes, and which epoch second a wall-clock reading maps to. Shared between
 * ZonedDateTime, PlainDate, PlainDateTime, Instant, and Duration.
 *
 * Questions about the identifier STRING — is it valid, what is its canonical spelling,
 * do two spellings name the same zone — belong to {@see TimeZoneIdentity}. Callers hand
 * this class an identifier that has already been through
 * {@see TimeZoneIdentity::canonicalId()}, because offsets must be read from the zone the
 * IANA links resolve to.
 *
 * This class lives in `Temporal\Spec\Internal\` and is therefore not part of the
 * public BC contract. Signatures, behavior, and existence may change between
 * any two releases. External code must not depend on it.
 */
final class TimeZoneHelper
{
    /** Half-width of the transition search window: no zone goes 200 years without a change. */
    private const int TRANSITION_SEARCH_SECONDS = 200 * 365 * 86_400;

    /**
     * Returns the UTC offset in seconds for a canonical zone at a given epoch second.
     *
     * - 'UTC' → 0 (via the empty transition list PHP reports for it).
     * - '±HH:MM' → ±(H×3600 + M×60), read straight off the identifier.
     * - IANA name → `DateTimeZone::getOffset()`.
     */
    public static function offsetSecondsAt(string $resolvedTzId, int $epochSec): int
    {
        $m = null;
        if (preg_match('/^([+\-])(\d{2}):(\d{2})$/', $resolvedTzId, $m) === 1) {
            $sign = $m[1] === '+' ? 1 : -1;
            return $sign * (((int) $m[2] * 3600) + ((int) $m[3] * 60));
        }
        return self::zone($resolvedTzId)->getOffset(new \DateTimeImmutable(sprintf('@%d', $epochSec)));
    }

    /**
     * Finds the epoch second of the offset transition adjacent to a given instant, or
     * null when the zone has none in that direction.
     *
     * TC39 defines a transition as a change in UTC offset, so entries that repeat the
     * preceding offset (which PHP reports for the window boundary and for DST rule
     * renewals that keep the same offset) are skipped rather than returned.
     *
     * The instant is taken as (epochSec, subNs) because "previous" is strictly before
     * the receiver: a transition landing exactly on epochSec counts as previous only
     * when the receiver carries sub-second nanoseconds past it.
     *
     * @param string $direction 'next' or 'previous'.
     */
    public static function findTransition(string $resolvedTzId, int $epochSec, int $subNs, string $direction): ?int
    {
        if (preg_match('/^[+\-]\d{2}:\d{2}$/', $resolvedTzId) === 1) {
            return null;
        }
        $tz = self::zone($resolvedTzId);

        if ($direction === 'next') {
            $transitions = self::safeGetTransitions($tz, $epochSec, $epochSec + self::TRANSITION_SEARCH_SECONDS);
            if (count($transitions) < 2) {
                return null;
            }
            // Skip index 0 (the zone's state at the window start, not a transition).
            $prevOffset = $transitions[0]['offset'];
            $nTransitions = count($transitions);
            for ($i = 1; $i < $nTransitions; $i++) {
                $curOffset = $transitions[$i]['offset'];
                if ($curOffset !== $prevOffset) {
                    return $transitions[$i]['ts'];
                }
                $prevOffset = $curOffset;
            }
            return null;
        }

        // 'previous': the most recent transition strictly BEFORE the instant. The end
        // bound is epochSec+1 so a transition at exactly epochSec is still in the
        // window — some PHP/ICU versions exclude the boundary second — and the
        // strictly-before test below is what actually rejects it.
        $transitions = self::safeGetTransitions($tz, $epochSec - self::TRANSITION_SEARCH_SECONDS, $epochSec + 1);
        if (count($transitions) < 2) {
            return null;
        }
        for ($i = count($transitions) - 1; $i >= 1; $i--) {
            $ts = $transitions[$i]['ts'];
            $isBefore = $ts < $epochSec || $ts === $epochSec && $subNs > 0;
            if ($transitions[$i]['offset'] !== $transitions[$i - 1]['offset'] && $isBefore) {
                return $ts;
            }
        }
        return null;
    }

    /**
     * Returns a cached DateTimeZone for a canonical identifier.
     *
     * Offset and transition lookups run per property access on ZonedDateTime, so the
     * objects are memoized rather than rebuilt for every question about the same zone.
     */
    private static function zone(string $resolvedTzId): \DateTimeZone
    {
        /** @var array<string, \DateTimeZone> $cache */
        static $cache = [];
        $tz = $cache[$resolvedTzId] ?? null;
        if ($tz === null) {
            /** @psalm-suppress ArgumentTypeCoercion — identifiers are validated non-empty before reaching here */
            $tz = new \DateTimeZone($resolvedTzId);
            $cache[$resolvedTzId] = $tz;
        }
        return $tz;
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
        $tz = self::zone($tzId);
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
        $tz = self::zone($tzId);

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
