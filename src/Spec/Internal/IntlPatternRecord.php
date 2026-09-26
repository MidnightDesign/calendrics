<?php

declare(strict_types=1);

namespace Calendrics\Spec\Internal;

/** @internal */
final readonly class IntlPatternRecord
{
    /** @var array<int, IntlPatternField> */
    public array $fields;
    public string $base;
    public string $key;

    public function __construct(
        string $pattern,
        public ?string $skeleton,
    ) {
        $this->fields = IntlPatternField::parse($skeleton ?? $pattern);
        $this->base = implode('', array_map(static fn(IntlPatternField $field): string => $field->base, $this->fields));
        $this->key = implode('', array_map(static fn(IntlPatternField $field): string => str_repeat(
            $field->symbol,
            $field->width,
        ), $this->fields));
    }
}
