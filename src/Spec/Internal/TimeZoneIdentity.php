<?php

declare(strict_types=1);

namespace Temporal\Spec\Internal;

use Temporal\Exception\RangeError;

/**
 * Everything Temporal does with a timezone *identifier string* — as opposed to
 * {@see TimeZoneHelper}, which answers questions about a zone's *offsets*.
 *
 * The three operations here answer three different questions about the same name, and
 * conflating them produces wrong answers:
 *
 *   - {@see normalize()} — is this a usable identifier, and what is its canonical
 *     spelling? Accepts 'UTC' aliases, ±HH / ±HHMM / ±HH:MM offsets, IANA names in any
 *     case, and (unless refused) the bracket/offset suffix of an ISO date-time string.
 *   - {@see canonicalId()} — which zone's transition data should offset lookups read?
 *     Resolves IANA links through ICU plus a fixup table for the links ICU reports as
 *     self-canonical.
 *   - {@see comparisonId()} — do two identifiers name the same zone for `equals()`?
 *     Deliberately NOT the same as {@see canonicalId()}: Antarctica/McMurdo and
 *     Pacific/Auckland share offset rules but are distinct IANA primaries, so they
 *     resolve together for offsets and stay apart for equality.
 *
 * This class lives in `Temporal\Spec\Internal\` and is therefore not part of the public
 * BC contract. Signatures, behavior, and existence may change between any two releases.
 * External code must not depend on it.
 */
final class TimeZoneIdentity
{
    /**
     * Known ICU inconsistencies where an IANA link target is reported as self-canonical
     * instead of resolving to its true primary zone.
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

    /** Identifiers that all name UTC and therefore compare equal to it. */
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

    public static function normalize(string $id, bool $rejectDatetimeStrings = false): string
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
            $result = self::normalizeUncached($id, true);
            if (count($cacheR) >= 1024) {
                $cacheR = [];
            }
            return $cacheR[$id] = $result;
        }
        if (array_key_exists($id, $cacheN)) {
            return $cacheN[$id];
        }
        $result = self::normalizeUncached($id, false);
        if (count($cacheN) >= 1024) {
            $cacheN = [];
        }
        return $cacheN[$id] = $result;
    }

    private static function normalizeUncached(string $id, bool $rejectDatetimeStrings): string
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
            $om = null;
            if (preg_match('/([+\-]\d{2}:\d{2})(?:\[|$)/', $id, $om) === 1) {
                return $om[1];
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

        // Must be in the IANA timezone list — reject abbreviations like "AST", "EST".
        $properCase = self::properCase($id);
        if ($properCase === null) {
            throw new RangeError("Invalid timeZoneId \"{$id}\": not a recognized IANA timezone identifier.");
        }
        return $properCase;
    }

    /**
     * Resolves an identifier to the zone whose transition data offset lookups should read.
     *
     * Applies the {@see ICU_FIXUPS} table first, then ICU's own canonicalization; the
     * result is the identifier handed to `DateTimeZone`.
     */
    public static function canonicalId(string $id): string
    {
        /** @var array<string, string> $cache */
        static $cache = [];
        if (array_key_exists($id, $cache)) {
            return $cache[$id];
        }
        return $cache[$id] = self::canonicalIdUncached($id);
    }

    private static function canonicalIdUncached(string $id): string
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
                // Apply fixups to the ICU result as well
                $canon = self::ICU_FIXUPS[$canon] ?? $canon;
                new \DateTimeZone($canon);
                return $canon;
            }
        }
        return $id;
    }

    /**
     * Returns the key two identifiers must share for `ZonedDateTime::equals()` to treat
     * them as the same zone.
     *
     * Per TC39, equality compares canonical PRIMARY identifiers: IANA aliases such as
     * Asia/Calcutta and Asia/Kolkata compare equal, while two distinct primaries that
     * merely share offset rules — Antarctica/McMurdo and Pacific/Auckland — do not.
     * That is why this path folds UTC aliases and the ±00:00 offsets to 'UTC' but stops
     * short of the McMurdo → Auckland fixup {@see canonicalId()} applies.
     */
    public static function comparisonId(string $id): string
    {
        /** @var array<string, string> $cache */
        static $cache = [];
        if (array_key_exists($id, $cache)) {
            return $cache[$id];
        }
        return $cache[$id] = self::comparisonIdUncached($id);
    }

    private static function comparisonIdUncached(string $id): string
    {
        if (in_array(strtolower($id), self::UTC_ALIASES, strict: true)) {
            return 'UTC';
        }
        // Fixed offset +00:00 and -00:00 are equivalent to UTC.
        if ($id === '+00:00' || $id === '-00:00') {
            return 'UTC';
        }
        // Case-fold using the properly-cased IANA ID from PHP's timezone list
        // (ICU's getCanonicalID is case-sensitive).
        $properCase = self::properCase($id) ?? $id;
        // Antarctica/South_Pole and McMurdo are distinct IANA primaries that
        // share offset data with Pacific/Auckland. The offset-resolution fixup
        // (McMurdo -> Auckland) must NOT be applied for comparison.
        if ($properCase === 'Antarctica/South_Pole' || $properCase === 'Antarctica/McMurdo') {
            return 'Antarctica/McMurdo';
        }
        // Apply the shared fixup map (POSIX abbreviations, stale ICU links, etc.)
        // then run the result through ICU for a fully canonical comparison form.
        $resolved = self::canonicalId($properCase);
        if (function_exists('intltz_get_canonical_id')) {
            $isSystem = false;
            $canon = \IntlTimeZone::getCanonicalID($resolved, $isSystem);
            if ($canon !== false && $canon !== '') {
                return $canon;
            }
        }
        return $resolved;
    }

    /**
     * Maps a case-insensitive IANA name to its properly-cased spelling, or null when the
     * name is not in PHP's timezone list.
     *
     * Both callers need the same fold, and both need it to be the same list: normalize()
     * uses "absent from the list" as its rejection test for abbreviations like 'AST',
     * while comparisonId() needs the proper case because ICU's canonicalization is
     * case-sensitive.
     */
    private static function properCase(string $id): ?string
    {
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
        return $lowerToCanonical[strtolower($id)] ?? null;
    }
}
