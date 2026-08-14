<?php

declare(strict_types=1);

namespace Temporal\Tests\Test262;

/**
 * A property bag that logs every `Get(O, P)` performed against it.
 *
 * TC39's order-of-operations fixtures pass one of these where an options bag or a
 * field bag is expected, then assert the exact sequence of property reads the
 * operation performed. The JS harness uses a Proxy; PHP's equivalent is `__get`,
 * which the spec layer reaches because it reads bags through
 * {@see \Temporal\Spec\Internal\Options::bagGet()} rather than `get_object_vars()`.
 *
 * Every property is private, so `get_object_vars()` from `Options`' scope sees
 * nothing and each recognized name funnels through `__get()`. `__isset()` answers
 * `isset()` probes without logging, matching the JS harness, whose Proxy traps
 * `get` only.
 *
 * String values are wrapped in a {@see StringCoercionObserver} so their ToString
 * coercion is logged too, unless the fixture listed the name in $skipToPrimitive.
 *
 * @psalm-api used by dynamically-required test262 scripts in tests/Test262/scripts/
 */
final class PropertyBagObserver
{
    /**
     * Names whose value is handed over as a real string rather than an observer.
     *
     * ToTemporalCalendarIdentifier and ToTemporalTimeZoneIdentifier REQUIRE a String
     * instead of coercing to one, so these two never produce a `.toString` event in a
     * fixture's expected trace — and wrapping them would turn a valid bag into a
     * TypeError rather than observe it.
     *
     * @var list<string>
     */
    private const array NEVER_COERCED = ['calendar', 'timeZone'];

    /**
     * @param ObserverTrace $calls Shared trace the reads are recorded into.
     * @param array<array-key, mixed> $bag The values this bag exposes.
     * @param string $name Bag name used in the logged events (e.g. "options").
     * @param list<string>|null $skipToPrimitive Names handed over without ToString wrapping.
     */
    public function __construct(
        private ObserverTrace $calls,
        private array $bag,
        private string $name,
        private ?array $skipToPrimitive = null,
    ) {}

    public function __isset(string $prop): bool
    {
        return array_key_exists($prop, $this->bag);
    }

    public function __get(string $prop): mixed
    {
        $this->calls->record("get {$this->name}.{$prop}");

        if (!array_key_exists($prop, $this->bag)) {
            return null;
        }

        /** @var mixed $value */
        $value = $this->bag[$prop];

        if (
            !is_string($value)
            || in_array($prop, self::NEVER_COERCED, strict: true)
            || in_array($prop, $this->skipToPrimitive ?? [], strict: true)
        ) {
            return $value;
        }

        return new StringCoercionObserver($this->calls, $value, "{$this->name}.{$prop}");
    }
}
