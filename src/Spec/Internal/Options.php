<?php

declare(strict_types=1);

namespace Temporal\Spec\Internal;

use Stringable;
use Temporal\Exception\RangeError;
use Temporal\Exception\TypeError;

/**
 * Faithful coercion of a TC39 string-typed option value.
 *
 * GetOption(options, prop, "string", ...) applies ToString: a string passes
 * through; a Stringable coerces via (string) (a Symbol-like sentinel's
 * __toString throws Temporal\Exception\TypeError); any other type (number,
 * bool, plain object, null) would ToString to a value that is never a valid
 * option keyword, so it is rejected with a RangeError. The returned string must
 * still be validated against the option's allowed set by the caller.
 *
 * MESSAGE TEXT IS NON-CONTRACTUAL. No test asserts on any exception's message in
 * this class: `tests/Test262/Assert.php::throws()` checks only the exception
 * CLASS, and the project's PHPUnit suites have no message assertions. Only the
 * exception TYPE (RangeError vs. TypeError) is contractual; the wording of every
 * message owned here is free to change. The per-method docblocks below reference
 * this note rather than repeating it verbatim.
 *
 * @internal
 */
final class Options
{
    /**
     * Canonical TC39 rounding-mode keyword set (RoundingMode enum). The order matches
     * the spec's enumeration. Validated against by {@see self::roundingMode()}.
     *
     * This is the strict keyword set only — no legacy ECMA-402 aliases ("truncate"/
     * "ceiling") are accepted anywhere; they were never part of TC39 Temporal.
     *
     * @var list<string>
     */
    public const array ROUNDING_MODES = [
        'ceil',
        'floor',
        'expand',
        'trunc',
        'halfCeil',
        'halfFloor',
        'halfExpand',
        'halfTrunc',
        'halfEven',
    ];

    /**
     * Faithful TC39 GetOption(..., "string", ...) ToString coercion of an option
     * value: a string passes through; a Stringable coerces via __toString (a JsSymbol
     * sentinel's throwing __toString surfaces as Temporal\Exception\TypeError); any
     * other type is rejected with a RangeError. The returned string must still be
     * validated against the option's allowed keyword set by the caller.
     *
     * The RangeError message is owned here and parameterized only by the option's NAME
     * token (e.g. "smallestUnit", "disambiguation"). See the class-level note on message
     * text.
     *
     * @param string $optionName Bare option name interpolated into the RangeError text.
     * @throws RangeError if $value is neither a string nor a Stringable.
     */
    public static function coerceEnumOption(mixed $value, string $optionName): string
    {
        if (is_string($value)) {
            return $value;
        }
        if ($value instanceof Stringable) {
            return (string) $value;
        }

        throw new RangeError("{$optionName} must be a string.");
    }

    /**
     * Coerces and validates a TC39 `overflow` option value, which must stringify to
     * one of "constrain" / "reject".
     *
     * Combines the canonical {@see self::coerceEnumOption()} ToString coercion (a
     * string passes through; a Stringable coerces via __toString — a JsSymbol
     * sentinel's throwing __toString surfaces as Temporal\Exception\TypeError; any
     * other type is a RangeError) with the keyword check that the ~9 inline copies
     * across the Plain... and ZonedDateTime classes perform.
     *
     * Both RangeError messages are owned here. See the class-level note on message text.
     *
     * @throws RangeError if $value does not coerce to a string, or coerces to a string
     *                    that is neither "constrain" nor "reject".
     */
    public static function overflowOption(mixed $value): string
    {
        $overflow = self::coerceEnumOption($value, 'overflow');
        if ($overflow !== 'constrain' && $overflow !== 'reject') {
            throw new RangeError(sprintf(
                'Invalid overflow value: "%s"; must be \'constrain\' or \'reject\'.',
                $overflow,
            ));
        }
        return $overflow;
    }

    /**
     * Resolves the full GetOptionsObject + GetTemporalOverflowOption pipeline from a
     * RAW options argument to a validated "constrain" / "reject" keyword.
     *
     * This is the single resolver for the `overflow` option across all five Plain...
     * classes. It folds the GetOptionsObject step ({@see self::requireObject()}) and
     * the default-to-"constrain" + keyword-coercion step ({@see self::overflowFromBag()})
     * into one call, so callers no longer copy a `requireObject`-then-`overflowFromBag`
     * two-step or a per-class `resolveOverflowOption` wrapper.
     *
     * GetOptionsObject contract (TC39): the options argument must be undefined (omitted)
     * or an object. Omitted arrives as the empty-array default and resolves to the
     * "constrain" default; an explicit `null` or any other non-object primitive
     * (int/float/string/bool) is a TypeError; a Symbol sentinel (a \Stringable whose
     * __toString throws) is a TypeError. A genuine bag's `overflow` value is then
     * coerced/validated by {@see self::overflowOption()} (string keyword, else
     * RangeError).
     *
     * @param mixed $options Raw options argument (omitted → []; null/primitive → TypeError).
     * @throws TypeError  if $options is an explicit null, a non-object primitive, or a
     *                    Symbol sentinel (GetOptionsObject).
     * @throws RangeError if the `overflow` value is not "constrain"/"reject".
     */
    public static function overflowFromValue(mixed $options): string
    {
        // GetOptionsObject step 3: a non-null, non-array, non-object primitive
        // (int/float/string/bool) is a TypeError — raised here at the spec-layer origin
        // (after a caller's string parse), not by a from()/with() parameter-type guard.
        if ($options !== null && !is_array($options) && !is_object($options)) {
            throw new TypeError('options must be an object.');
        }
        // requireObject turns an explicit null / Symbol sentinel into a TypeError and
        // normalizes an object to an array; the empty-array default passes through.
        return self::overflowFromBag(self::requireObject($options, ['overflow']));
    }

    /**
     * Resolves an already-validated options BAG (post-GetOptionsObject) to a validated
     * "constrain" / "reject" keyword, defaulting to "constrain" when the bag is null
     * (an omitted options argument) or has no `overflow` key.
     *
     * Delegates the keyword coercion/validation to {@see self::overflowOption()}, where
     * an explicit `overflow => null` value coerces to neither keyword and is a RangeError.
     * Callers that need the GetOptionsObject (null-argument → TypeError) step should use
     * {@see self::overflowFromValue()} instead; this helper always defaults a null bag.
     *
     * After the Plain... convergence on {@see self::overflowFromValue()}, the only
     * external caller is {@see Temporal\Spec\ZonedDateTime}, which performs its own
     * GetOptionsObject (null handling) upstream and so wants the bag-only resolver here;
     * internally, {@see self::overflowFromValue()} also delegates to this method after
     * its own GetOptionsObject step.
     *
     * @param array<array-key, mixed>|object|null $options
     * @throws RangeError per {@see self::overflowOption()}.
     */
    public static function overflowFromBag(array|object|null $options): string
    {
        if ($options === null) {
            return 'constrain';
        }
        $options = self::normalizeOptions($options, ['overflow']);
        if (!array_key_exists('overflow', $options)) {
            return 'constrain';
        }
        return self::overflowOption($options['overflow']);
    }

    /**
     * Validates an already-coerced `roundingMode` string against the canonical
     * {@see self::ROUNDING_MODES} set and returns it unchanged.
     *
     * Replaces the ~7 inline `!in_array($mode, ROUNDING_MODES, true)` checks. The
     * RangeError message is owned here and embeds the offending value. See the
     * class-level note on message text.
     *
     * This validates the STRICT keyword set only; no legacy "truncate"/"ceiling"
     * aliases are recognized — they are not part of TC39 Temporal.
     *
     * @throws RangeError if $mode is not one of {@see self::ROUNDING_MODES}.
     */
    public static function roundingMode(string $mode): string
    {
        if (!in_array($mode, self::ROUNDING_MODES, strict: true)) {
            throw new RangeError("Invalid roundingMode: \"{$mode}\".");
        }
        return $mode;
    }

    /**
     * Normalises a singular or plural Temporal unit name to its canonical plural form.
     *
     * RangeError message is owned here and embeds the offending value. See the
     * class-level note on message text.
     *
     * @return 'years'|'months'|'weeks'|'days'|'hours'|'minutes'|'seconds'|'milliseconds'|'microseconds'|'nanoseconds'
     * @throws RangeError for unknown unit names.
     */
    public static function normalizeUnit(string $unit): string
    {
        return match ($unit) {
            'year', 'years' => 'years',
            'month', 'months' => 'months',
            'week', 'weeks' => 'weeks',
            'day', 'days' => 'days',
            'hour', 'hours' => 'hours',
            'minute', 'minutes' => 'minutes',
            'second', 'seconds' => 'seconds',
            'millisecond', 'milliseconds' => 'milliseconds',
            'microsecond', 'microseconds' => 'microseconds',
            'nanosecond', 'nanoseconds' => 'nanoseconds',
            default => throw new RangeError("Unknown duration unit: \"{$unit}\"."),
        };
    }

    /**
     * Performs the universal part of TC39 ToTemporalRoundingIncrement on an already-
     * read `roundingIncrement` value: ToIntegerWithTruncation followed by the
     * "finite and ≥ 1" validation, returning the truncated integer.
     *
     * A Number (int or float) truncates toward zero; a non-finite float (NaN/±∞) is a
     * RangeError. Other scalar inputs (numeric string, bool) are coerced through PHP's
     * int cast, faithfully reproducing the inlined original this replaces — which
     * relied on a loose `(int)` cast over the int|float|string|bool values the option
     * resolver produces — without its suppression. Operation-specific bounds (the
     * per-unit maximum and the even-divisibility check) are deliberately left at the
     * call sites; only the coerce + finite + ≥ 1 core lives here.
     *
     * Two-tier design: this is the Duration-facing core (no upper bound). Plain* and
     * ZonedDateTime use {@see CalendarMath::validateRoundingIncrement()}, which adds the
     * universal 1e9 upper bound for time-domain increments.
     *
     * The two RangeError messages match the {@see Temporal\Spec\Duration::round()}
     * original byte-for-byte (the test262 suite asserts on them).
     *
     * @throws RangeError if the value is a non-finite number or rounds to < 1.
     */
    public static function roundingIncrement(mixed $value): int
    {
        // Mirror the original `is_float($v) ? $v : (int) $v` shape: a float keeps its
        // NaN/±∞ check before truncation; int/string/bool go straight through the int
        // cast. Any other type never reaches here from the option resolver; it maps to
        // 0 so the ≥ 1 check rejects it (matching the original's effective behavior).
        if (is_float($value)) {
            // @infection-ignore-all || ⇒ && is equivalent under test262: is_nan and
            // is_infinite are mutually exclusive, so the && form never enters this branch,
            // but every non-finite float then casts to (int) 0 (PHP: (int) NAN/INF/-INF === 0)
            // and is rejected by the `< 1` check below — still a RangeError, only the message
            // differs, and test262 asserts the exception type, not the text.
            if (is_nan($value) || is_infinite($value)) {
                throw new RangeError('roundingIncrement must be a finite positive integer.');
            }
            $increment = (int) $value;
        } elseif (is_int($value) || is_string($value) || is_bool($value)) {
            $increment = (int) $value;
        } else {
            $increment = 0;
        }
        if ($increment < 1) {
            throw new RangeError('roundingIncrement must be at least 1.');
        }
        return $increment;
    }

    /**
     * TC39 GetOptionsObject: the options argument must be undefined (omitted) or an
     * object. An explicit `null` (or any other non-object primitive — those are
     * already rejected by the parameter type) is a TypeError. A Symbol reaching here
     * (a \Stringable whose __toString throws) is likewise a TypeError.
     *
     * Omitted options arrive as the empty-array default, which passes through as "no
     * options". A genuine options object/array is returned normalized to an array.
     *
     * $props is the exhaustive list of option names the calling operation reads; an
     * object bag is snapshotted through {@see self::bagSnapshot()} so that one exposing
     * its options via `__get` is seen rather than silently read as empty. It is a
     * required argument precisely so that a new call site cannot quietly reintroduce
     * that blind spot.
     *
     * The parameter is `mixed` rather than `array|object|null` on purpose: a
     * primitive options argument must be rejected HERE, at the point GetOptionsObject
     * runs, not by a parameter-type guard on the public method. PHP checks a typed
     * parameter before the body executes, which would raise the TypeError before the
     * operation's primary argument had been converted — and TC39 requires that
     * conversion, with the property reads it performs, to happen first.
     *
     * @param mixed $options Raw options argument (null/primitive → TypeError).
     * @param list<string> $props Option names this operation recognizes.
     * @return array<array-key, mixed>
     */
    public static function requireObject(mixed $options, array $props): array
    {
        if ($options === null || !is_array($options) && !is_object($options)) {
            throw new TypeError('options must be an object.');
        }
        if (is_object($options)) {
            if ($options instanceof Stringable) {
                // JsSymbol sentinel: __toString throws Temporal\Exception\TypeError.
                // For any other Stringable (e.g. JsUndefined which returns 'undefined'),
                // the cast succeeds and we fall through to the snapshot.
                (string) $options;
            }
            return self::bagSnapshot($options, $props);
        }
        return $options;
    }

    /**
     * TC39 GetOptionsObject with NO reads: validates that the argument is an options
     * object and hands it back untouched.
     *
     * {@see self::requireObject()} snapshots every recognized name in one pass, which
     * is right when an operation reads its options as a block. It is wrong when the
     * spec interleaves a read with the CONVERSION of what it read — `total()` and
     * `round()` resolve `relativeTo` completely, property bag and all, before they so
     * much as look at the next option. Those callers take the bag from here and drive
     * the reads themselves with {@see self::bagGet()} / {@see self::bagSnapshot()}.
     *
     * @return array<array-key, mixed>|object The argument, unread.
     * @throws TypeError if $options is null, a non-object primitive, or a Symbol sentinel.
     */
    public static function asOptionsBag(mixed $options): array|object
    {
        if ($options === null || !is_array($options) && !is_object($options)) {
            throw new TypeError('options must be an object.');
        }
        if ($options instanceof Stringable) {
            // JsSymbol sentinel: __toString throws. Must propagate.
            (string) $options;
        }

        return $options;
    }

    /**
     * TC39 GetOptionsObject applied to an OPTIONAL options argument: null / omitted
     * means "use defaults" (returns an empty array), but a Stringable sentinel that
     * behaves like a Symbol (its __toString throws) is still a TypeError.  Ordinary
     * objects (including JsUndefined, whose __toString returns 'undefined') are
     * normalised to an array via get_object_vars(); that matches the spec's
     * GetOptionsObject(options) step which returns an ordinary empty object for
     * `undefined`.
     *
     * Use this helper wherever the TC39 spec step is:
     *   "If options is undefined, set options to OrdinaryObjectCreate(null)"
     * i.e. null/undefined is valid (use defaults) but non-object non-undefined is TypeError.
     *
     * $props carries the same contract as in {@see self::requireObject()}: the exhaustive
     * list of option names the calling operation reads, so an object bag exposing its
     * options through `__get` is seen instead of snapshotting empty.
     *
     * `mixed` for the same reason as {@see self::requireObject()}: a primitive must be
     * rejected at the GetOptionsObject step inside the body, not by a parameter-type
     * guard that fires before the primary argument is converted.
     *
     * @param mixed $options Raw options argument (null → defaults; primitive → TypeError).
     * @param list<string> $props Option names this operation recognizes.
     * @return array<array-key, mixed>
     */
    public static function normalizeOptions(mixed $options, array $props): array
    {
        if ($options === null) {
            return [];
        }
        if (!is_array($options) && !is_object($options)) {
            throw new TypeError('options must be an object.');
        }
        if (is_object($options)) {
            if ($options instanceof Stringable) {
                // JsSymbol sentinel: __toString throws Temporal\Exception\TypeError.
                // This must propagate — do not catch it.
                (string) $options;
            }
            return self::bagSnapshot($options, $props);
        }
        return $options;
    }

    /**
     * Sentinel returned by {@see self::bagGet()} when a property is ABSENT from the
     * bag (the JS equivalent of `Get(O, P)` yielding `undefined` for a missing own
     * property). Distinct from a declared property whose value is `null`, which
     * {@see self::bagGet()} returns as `null`.
     */
    public const string ABSENT = "\0Temporal\\Spec\\Internal\\Options::ABSENT\0";

    /**
     * Bag entries TC39 reads with ToString, and which {@see self::bagSnapshot()}
     * therefore stringifies at read time.
     *
     * Covers both option keywords (GetOption with type string) and the string-valued
     * date fields (PrepareCalendarFields' ToPrimitiveAndRequireString entries). The
     * numeric fields are absent because PHP reaches ToNumber only for real int/float
     * values, and so is `calendar`: ToTemporalCalendarIdentifier REQUIRES a String
     * rather than coercing to one, so a non-string there stays a TypeError.
     */
    private const array STRING_VALUED = [
        'calendarName',
        'direction',
        'disambiguation',
        'era',
        'fractionalSecondDigits',
        'largestUnit',
        'monthCode',
        'offset',
        'overflow',
        'roundingMode',
        'smallestUnit',
        'timeZoneName',
        'unit',
    ];

    /**
     * Faithful TC39 `Get(O, P)` for a property bag.
     *
     * Unlike {@see self::normalizeOptions()} (which snapshots an object's declared
     * public properties via get_object_vars() and therefore never triggers PHP's
     * `__get` magic), this reads a single named property in a way that fires an
     * accessor getter when one is defined — matching JS's `[[Get]]`, where reading
     * a property invokes its getter. The order of checks mirrors the JS semantics:
     *
     *   - array bag: present key → its value; otherwise {@see self::ABSENT}.
     *   - object with a DECLARED property `$p` (including a declared `null` value):
     *     direct read, never dispatching through `__get`.
     *   - object exposing `__get`: read `$o->$p`, firing the accessor getter (whose
     *     body may legitimately throw — that throw must propagate). A getter that
     *     yields `null` reports {@see self::ABSENT}: `null` is the PHP rendering of
     *     the `undefined` a JS `[[Get]]` returns for a property the object does not
     *     carry, and every algorithm here treats `undefined` as "field not supplied".
     *     A DECLARED `null` keeps meaning "present, and its value is null", which is
     *     what makes `{month: null}` a rejected field rather than an omitted one.
     *   - otherwise: {@see self::ABSENT}.
     *
     * @param array<array-key, mixed>|object $bag
     *
     * @return mixed the property value, or {@see self::ABSENT} if not present.
     */
    public static function bagGet(array|object $bag, string $prop): mixed
    {
        if (is_array($bag)) {
            return array_key_exists($prop, $bag) ? $bag[$prop] : self::ABSENT;
        }
        // Declared public property (including a declared `null`): read it without
        // dispatching through __get. get_object_vars() from this scope returns only
        // public properties, matching what a property bag exposes.
        $vars = get_object_vars($bag);
        if (array_key_exists($prop, $vars)) {
            return $vars[$prop];
        }
        if (method_exists($bag, '__get')) {
            // Accessor getter: a runtime property name is intrinsic to Get(O, P);
            // the getter body may legitimately throw, which must propagate.
            /**
             * @var mixed $value
             * @phpstan-ignore property.dynamicName
             */
            $value = $bag->{$prop};
            return $value ?? self::ABSENT;
        }
        return self::ABSENT;
    }

    /**
     * Normalizes a property bag to an array by reading $props through the faithful
     * {@see self::bagGet()}, in the order given.
     *
     * This is the object-bag counterpart to {@see self::normalizeOptions()}. The
     * difference is what an OBJECT bag is allowed to be: `get_object_vars()` sees
     * only declared public properties, so an object that exposes its fields through
     * `__get` — an ordinary PHP DTO, a lazily-hydrated entity, a config wrapper —
     * snapshots as an empty bag and every field silently goes missing. Reading the
     * recognized names one at a time instead fires those accessors, which is what
     * TC39 prescribes: each field is an individual `Get(O, P)`.
     *
     * $props is therefore the exhaustive list of names the calling algorithm
     * recognizes, in the order TC39 reads them (alphabetical, for the calendar field
     * lists that PrepareCalendarFields walks). Names outside it are never probed,
     * which matters for bags whose accessor throws on an unrecognized name — probing
     * one that the spec does not read would invent an error the spec never raises.
     *
     * A name TC39 reads with ToString ({@see self::STRING_VALUED}) is stringified
     * here, as it is read, rather than by whichever caller eventually consumes it.
     * That timing is observable: an accessor may have side effects, and a value that
     * cannot stringify throws. Coercing at read time keeps each read paired with its
     * own coercion, so a bag whose second field throws on access cannot pre-empt the
     * error the first field's value was already going to raise.
     *
     * Array bags are returned unchanged: their keys are already a snapshot, there is
     * no accessor to fire, and passing them through preserves entries the caller
     * inspects but did not list.
     *
     * @param array<array-key, mixed>|object $bag
     * @param list<string> $props Recognized property names, in TC39 read order.
     * @return array<array-key, mixed>
     */
    public static function bagSnapshot(array|object $bag, array $props): array
    {
        if (is_array($bag)) {
            return $bag;
        }

        $snapshot = [];
        foreach ($props as $prop) {
            // Merged as a single-entry array rather than assigned to $snapshot[$prop]:
            // the value is `mixed`, and Psalm rejects a mixed value reaching an array
            // offset directly.
            $read = [$prop => self::bagGet($bag, $prop)];
            if ($read[$prop] === self::ABSENT) {
                continue;
            }
            if ($read[$prop] instanceof Stringable && in_array($prop, self::STRING_VALUED, strict: true)) {
                $read = [$prop => (string) $read[$prop]];
            }
            $snapshot = array_merge($snapshot, $read);
        }

        return $snapshot;
    }

    /**
     * TC39 GetStringOrNumberOption(options, "fractionalSecondDigits", «"auto"», 0, 9, "auto"),
     * applied to an already-read value. A Number (int or float) is range-checked
     * (NaN/±∞ → RangeError) and floored to an integer in 0–9; any non-number value is
     * coerced via ToString and must equal "auto" (a JsSymbol sentinel's throwing
     * __toString surfaces as Temporal\Exception\TypeError, exactly as ToString(Symbol)
     * does in JS). Returns null for "auto" (the no-op default), or the digit count 0–9.
     *
     * @throws RangeError if the value is a non-finite/out-of-range number or a
     *                    non-number that does not stringify to "auto".
     */
    public static function fractionalSecondDigits(mixed $value): ?int
    {
        if (is_int($value) || is_float($value)) {
            if (is_float($value)) {
                if (is_nan($value) || is_infinite($value)) {
                    throw new RangeError("fractionalSecondDigits must be 'auto' or a finite integer 0–9.");
                }
                $value = (int) floor($value);
            }
            if ($value < 0 || $value > 9) {
                throw new RangeError("fractionalSecondDigits {$value} is out of range (must be 0–9).");
            }
            return $value;
        }
        if ($value instanceof Stringable) {
            // JsSymbol sentinel: __toString throws Temporal\Exception\TypeError.
            $value = (string) $value;
        }
        if ($value !== 'auto') {
            throw new RangeError("fractionalSecondDigits must be 'auto' or an integer 0–9.");
        }
        return null;
    }
}
