<?php

declare(strict_types=1);

namespace Calendrics\Tests\Test262;

/**
 * PHP equivalent of the JavaScript `Set` built-in, for fixtures that build a set of
 * candidate values and then remove the ones they are not interested in.
 *
 * Only the surface those fixtures use is modelled: construction from an iterable,
 * `add`, `delete`, `has`, `size`, and `values()`. Membership uses JS SameValueZero,
 * which for the string/number values in the corpus is PHP's `===`. Insertion order is
 * preserved, as in JS — the `calendar-mismatch` fixtures depend on it when they take
 * `values().next().value`.
 *
 * @psalm-api used by dynamically-required test scripts in tests/Test262/scripts/
 */
final class JsSet
{
    /** @var list<mixed> Distinct members, in insertion order. */
    private array $values = [];

    /** @param iterable<mixed> $values */
    public function __construct(iterable $values = [])
    {
        /** @var mixed $value */
        foreach ($values as $value) {
            $this->add($value);
        }
    }

    /**
     * Number of members, mirroring JS `Set.prototype.size`.
     *
     * @psalm-suppress PropertyNotSetInConstructor — virtual property (get-only hook, no backing store)
     * @psalm-suppress PossiblyUnusedProperty — accessed from transpiled test262 scripts
     */
    public int $size {
        get => count($this->values);
    }

    /** Adds a member if absent; returns the set, as JS `Set.prototype.add` does. */
    public function add(mixed $value): self
    {
        if (!$this->has($value)) {
            $this->values[] = $value;
        }
        return $this;
    }

    /** Removes a member; returns whether it was present, as JS `Set.prototype.delete` does. */
    public function delete(mixed $value): bool
    {
        $index = array_search($value, $this->values, strict: true);
        if ($index === false) {
            return false;
        }

        array_splice($this->values, $index, length: 1);
        return true;
    }

    public function has(mixed $value): bool
    {
        return in_array($value, $this->values, strict: true);
    }

    /** Returns an iterator over the members in insertion order, as JS `Set.prototype.values` does. */
    public function values(): JsIterator
    {
        return new JsIterator($this->values);
    }
}
