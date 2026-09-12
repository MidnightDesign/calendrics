<?php

declare(strict_types=1);

namespace Calendrics\Tests\Porcelain\Constraint;

use Calendrics\PlainTime;
use PHPUnit\Framework\Constraint\Constraint;

final class PlainTimeEquals extends Constraint
{
    private const array FIELDS = ['hour', 'minute', 'second', 'millisecond', 'microsecond', 'nanosecond'];

    public function __construct(
        private readonly int $hour,
        private readonly int $minute,
        private readonly int $second,
        private readonly int $millisecond,
        private readonly int $microsecond,
        private readonly int $nanosecond,
    ) {}

    #[\Override]
    public function toString(): string
    {
        return sprintf(
            'is PlainTime %02d:%02d:%02d.%03d%03d%03d',
            $this->hour,
            $this->minute,
            $this->second,
            $this->millisecond,
            $this->microsecond,
            $this->nanosecond,
        );
    }

    #[\Override]
    protected function matches(mixed $other): bool
    {
        if (!$other instanceof PlainTime) {
            return false;
        }

        return (
            $other->hour === $this->hour
            && $other->minute === $this->minute
            && $other->second === $this->second
            && $other->millisecond === $this->millisecond
            && $other->microsecond === $this->microsecond
            && $other->nanosecond === $this->nanosecond
        );
    }

    #[\Override]
    protected function failureDescription(mixed $other): string
    {
        if (!$other instanceof PlainTime) {
            return $this->valueToTypeStringFragment($other) . $this->toString();
        }

        return sprintf('%s %s', $this->describeTime($other), $this->toString());
    }

    #[\Override]
    protected function additionalFailureDescription(mixed $other): string
    {
        if (!$other instanceof PlainTime) {
            return '';
        }

        $expected = $this->expectedArray();
        $actual = self::timeToArray($other);
        $lines = [];

        foreach (self::FIELDS as $field) {
            if ($expected[$field] === $actual[$field]) {
                continue;
            }

            $lines[] = sprintf('  %s: expected %d, actual %d', $field, $expected[$field], $actual[$field]);
        }

        return implode("\n", $lines);
    }

    private function describeTime(PlainTime $time): string
    {
        return sprintf(
            'PlainTime %02d:%02d:%02d.%03d%03d%03d',
            $time->hour,
            $time->minute,
            $time->second,
            $time->millisecond,
            $time->microsecond,
            $time->nanosecond,
        );
    }

    /** @return array<string, int> */
    private function expectedArray(): array
    {
        return [
            'hour' => $this->hour,
            'minute' => $this->minute,
            'second' => $this->second,
            'millisecond' => $this->millisecond,
            'microsecond' => $this->microsecond,
            'nanosecond' => $this->nanosecond,
        ];
    }

    /** @return array<string, int> */
    private static function timeToArray(PlainTime $time): array
    {
        return [
            'hour' => $time->hour,
            'minute' => $time->minute,
            'second' => $time->second,
            'millisecond' => $time->millisecond,
            'microsecond' => $time->microsecond,
            'nanosecond' => $time->nanosecond,
        ];
    }
}
