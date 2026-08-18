<?php

declare(strict_types=1);

namespace Calendrics\Tests\Porcelain\Constraint;

use Calendrics\Duration;
use PHPUnit\Framework\Constraint\Constraint;

final class DurationEquals extends Constraint
{
    private const array FIELDS = [
        'years',
        'months',
        'weeks',
        'days',
        'hours',
        'minutes',
        'seconds',
        'milliseconds',
        'microseconds',
        'nanoseconds',
    ];

    public function __construct(
        private readonly int $years,
        private readonly int $months,
        private readonly int $weeks,
        private readonly int $days,
        private readonly int $hours,
        private readonly int $minutes,
        private readonly int $seconds,
        private readonly int $milliseconds,
        private readonly int $microseconds,
        private readonly int $nanoseconds,
    ) {}

    #[\Override]
    public function toString(): string
    {
        return sprintf('is %s', self::describeDurationFields($this->expectedArray()));
    }

    #[\Override]
    protected function matches(mixed $other): bool
    {
        if (!$other instanceof Duration) {
            return false;
        }

        return (
            $other->years === $this->years
            && $other->months === $this->months
            && $other->weeks === $this->weeks
            && $other->days === $this->days
            && $other->hours === $this->hours
            && $other->minutes === $this->minutes
            && $other->seconds === $this->seconds
            && $other->milliseconds === $this->milliseconds
            && $other->microseconds === $this->microseconds
            && $other->nanoseconds === $this->nanoseconds
        );
    }

    #[\Override]
    protected function failureDescription(mixed $other): string
    {
        if (!$other instanceof Duration) {
            return $this->valueToTypeStringFragment($other) . $this->toString();
        }

        return sprintf('%s %s', self::describeDurationFields(self::durationToArray($other)), $this->toString());
    }

    #[\Override]
    protected function additionalFailureDescription(mixed $other): string
    {
        if (!$other instanceof Duration) {
            return '';
        }

        $expected = $this->expectedArray();
        $actual = self::durationToArray($other);
        $lines = [];

        foreach (self::FIELDS as $field) {
            if ($expected[$field] === $actual[$field]) {
                continue;
            }

            $lines[] = sprintf('  %s: expected %d, actual %d', $field, $expected[$field], $actual[$field]);
        }

        return implode("\n", $lines);
    }

    /**
     * Formats a Duration field array as "Duration {field: value, ...}", showing only non-zero fields.
     * Falls back to "Duration {}" when all fields are zero.
     *
     * @param array<string, int> $fields
     */
    private static function describeDurationFields(array $fields): string
    {
        $nonZero = array_filter($fields, static fn(int $v): bool => $v !== 0);

        $parts = [];
        foreach ($nonZero as $name => $value) {
            $parts[] = "{$name}: {$value}";
        }

        return sprintf('Duration {%s}', implode(', ', $parts));
    }

    /** @return array<string, int> */
    private function expectedArray(): array
    {
        return [
            'years' => $this->years,
            'months' => $this->months,
            'weeks' => $this->weeks,
            'days' => $this->days,
            'hours' => $this->hours,
            'minutes' => $this->minutes,
            'seconds' => $this->seconds,
            'milliseconds' => $this->milliseconds,
            'microseconds' => $this->microseconds,
            'nanoseconds' => $this->nanoseconds,
        ];
    }

    /** @return array<string, int> */
    private static function durationToArray(Duration $d): array
    {
        return [
            'years' => $d->years,
            'months' => $d->months,
            'weeks' => $d->weeks,
            'days' => $d->days,
            'hours' => $d->hours,
            'minutes' => $d->minutes,
            'seconds' => $d->seconds,
            'milliseconds' => $d->milliseconds,
            'microseconds' => $d->microseconds,
            'nanoseconds' => $d->nanoseconds,
        ];
    }
}
