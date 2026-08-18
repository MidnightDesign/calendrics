<?php

declare(strict_types=1);

namespace Calendrics\Spec\Internal;

/**
 * Time-zone identifier canonicalization and UTC-offset lookup.
 *
 * A `ZonedDateTime` reaches for its zone in two different ways, and they do not want the
 * same answer:
 *
 *   - {@see canonicalize()} produces the identifier used to *read offset data*, folding
 *     the handful of IDs where ICU's link table disagrees with IANA's onto whichever zone
 *     actually carries the rules; and
 *   - {@see comparisonKey()} produces the identifier used to *compare zones* in
 *     `equals()` and in the same-zone precondition on calendar-unit `since`/`until`,
 *     which per TC39 is the canonical primary — so `Asia/Calcutta` and `Asia/Kolkata`
 *     compare equal, while `Antarctica/McMurdo` and `Pacific/Auckland` do not, despite
 *     sharing every offset rule.
 *
 * Conflating the two is the bug this separation exists to prevent: the McMurdo → Auckland
 * fixup is right for offsets and wrong for equality.
 *
 * Both are memoized, and so is the {@see \DateTimeZone} behind {@see offsetAt()} — all
 * three are hit once per local-component materialization, and a `\DateTimeZone`
 * construction is not free.
 *
 * @internal
 */
final class ZoneOffsets
{
    /**
     * IDs where ICU reports a link target as self-canonical instead of resolving it to the
     * primary zone that owns the offset rules. Applied on the offset-reading path only.
     *
     * @var array<string, string>
     */
    private const array ICU_FIXUPS = [
        'Antarctica/McMurdo' => 'Pacific/Auckland',
        'Antarctica/South_Pole' => 'Antarctica/McMurdo',
        'Asia/Choibalsan' => 'Asia/Ulaanbaatar',
        'CET' => 'Europe/Brussels',
        'CST6CDT' => 'America/Chicago',
        'EET' => 'Europe/Athens',
        'EST' => 'America/Panama',
        'EST5EDT' => 'America/New_York',
        'HST' => 'Pacific/Honolulu',
        'MET' => 'Europe/Brussels',
        'MST' => 'America/Phoenix',
        'MST7MDT' => 'America/Denver',
        'PST8PDT' => 'America/Los_Angeles',
        'WET' => 'Europe/Lisbon',
    ];

    /**
     * Spellings of UTC that all name the same zone. Folded together by
     * {@see comparisonKey()} so a `ZonedDateTime` in `Etc/Zulu` equals one in `UTC`.
     *
     * @var list<string>
     */
    private const array UTC_ALIASES = [
        'etc/utc',
        'etc/gmt',
        'etc/gmt+0',
        'etc/gmt-0',
        'etc/gmt0',
        'etc/greenwich',
        'etc/uct',
        'etc/universal',
        'etc/zulu',
        'gmt',
        'gmt+0',
        'gmt-0',
        'gmt0',
        'greenwich',
        'uct',
        'universal',
        'zulu',
        'utc',
    ];

    /**
     * Resolves a time-zone identifier to the form used for offset and transition lookups.
     *
     * Runs the ICU canonicalization, then applies {@see ICU_FIXUPS} to both the input and
     * the ICU result so a chained link (`Antarctica/South_Pole` → `Antarctica/McMurdo` →
     * `Pacific/Auckland`) lands on the zone that actually has the data.
     */
    public static function canonicalize(string $id): string
    {
        /** @var array<string, string> $cache */
        static $cache = [];
        if (array_key_exists($id, $cache)) {
            return $cache[$id];
        }
        return $cache[$id] = self::canonicalizeUncached($id);
    }

    /**
     * Returns the key two time-zone identifiers are compared by for `equals()`.
     *
     * Per TC39, zones compare by canonical primary identifier: IANA aliases collapse, and
     * two distinct primaries stay distinct even when their rules are identical. UTC
     * aliases and the `+00:00` / `-00:00` fixed offsets all fold to `'UTC'`.
     *
     * Deliberately does NOT apply the McMurdo → Auckland fixup that {@see canonicalize()}
     * uses: those are separate IANA primaries, and folding them here would make two
     * genuinely different zones compare equal.
     */
    public static function comparisonKey(string $id): string
    {
        /** @var array<string, string> $cache */
        static $cache = [];
        if (array_key_exists($id, $cache)) {
            return $cache[$id];
        }
        return $cache[$id] = self::comparisonKeyUncached($id);
    }

    /**
     * Returns the UTC offset in seconds observed by $resolvedTzId at $epochSec.
     *
     * Expects an identifier already through {@see canonicalize()}: either a fixed
     * `±HH:MM` offset, which is read straight off the string, or an IANA name, whose
     * offset comes from PHP's zone database.
     */
    public static function offsetAt(int $epochSec, string $resolvedTzId): int
    {
        $m = null;
        if (preg_match('/^([+\-])(\d{2}):(\d{2})$/', $resolvedTzId, $m) === 1) {
            $sign = $m[1] === '+' ? 1 : -1;
            return $sign * (((int) $m[2] * 3600) + ((int) $m[3] * 60));
        }

        /** @var array<string, \DateTimeZone> $tzCache */
        static $tzCache = [];
        $tz = $tzCache[$resolvedTzId] ?? null;
        if ($tz === null) {
            /** @psalm-suppress ArgumentTypeCoercion — callers pass a validated non-empty identifier */
            $tz = new \DateTimeZone($resolvedTzId);
            $tzCache[$resolvedTzId] = $tz;
        }
        return $tz->getOffset(new \DateTimeImmutable(sprintf('@%d', $epochSec)));
    }

    private static function canonicalizeUncached(string $id): string
    {
        if (array_key_exists($id, self::ICU_FIXUPS)) {
            $resolved = self::ICU_FIXUPS[$id];
            // Chain through fixups (e.g. South_Pole -> McMurdo -> Auckland).
            return self::ICU_FIXUPS[$resolved] ?? $resolved;
        }
        if (function_exists('intltz_get_canonical_id')) {
            $isSystem = false;
            $canon = \IntlTimeZone::getCanonicalID($id, $isSystem);
            if ($canon !== false && $canon !== '' && $canon !== $id) {
                $canon = self::ICU_FIXUPS[$canon] ?? $canon;
                new \DateTimeZone($canon);
                return $canon;
            }
        }
        return $id;
    }

    private static function comparisonKeyUncached(string $id): string
    {
        $lower = strtolower($id);
        if (in_array($lower, self::UTC_ALIASES, strict: true)) {
            return 'UTC';
        }
        if ($id === '+00:00' || $id === '-00:00') {
            return 'UTC';
        }

        // Case-fold via the properly-cased IANA ID from PHP's zone list; ICU's
        // getCanonicalID is case-sensitive and would pass a mis-cased ID through.
        /** @var array<string, string>|null $lowerMap */
        static $lowerMap = null;
        if ($lowerMap === null) {
            $lowerMap = [];
            foreach (\DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC) as $ident) {
                $lowerMap[strtolower($ident)] = $ident;
            }
        }
        $properCase = $lowerMap[$lower] ?? $id;

        // South_Pole and McMurdo are distinct IANA primaries sharing Auckland's offset
        // data. Collapse the alias pair, but stop short of the offset-path fixup.
        if ($properCase === 'Antarctica/South_Pole' || $properCase === 'Antarctica/McMurdo') {
            return 'Antarctica/McMurdo';
        }

        $resolved = self::canonicalize($properCase);
        if (function_exists('intltz_get_canonical_id')) {
            $isSystem = false;
            $canon = \IntlTimeZone::getCanonicalID($resolved, $isSystem);
            if ($canon !== false && $canon !== '') {
                return $canon;
            }
        }
        return $resolved;
    }
}
