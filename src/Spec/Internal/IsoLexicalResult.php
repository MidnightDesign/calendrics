<?php

declare(strict_types=1);

namespace Calendrics\Spec\Internal;

/** @internal */
final readonly class IsoLexicalResult
{
    public function __construct(
        public string $year,
        public string $dateRest,
        public string $hour,
        public string $minute,
        public string $second,
        public string $fraction,
        public string $offset,
        public string $annotations,
    ) {}

    public function hasUtcDesignator(): bool
    {
        return $this->offset === 'Z' || $this->offset === 'z';
    }
}
