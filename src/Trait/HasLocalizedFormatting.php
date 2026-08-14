<?php

declare(strict_types=1);

namespace Temporal\Trait;

/**
 * Shared plumbing for the porcelain `toLocaleString()` methods.
 *
 * Each porcelain class declares its own `toLocaleString()` signature exposing
 * only the options that are meaningful for that type — a `PlainDate` has no
 * `timeStyle`, a `PlainYearMonth` has no `day`. The signatures therefore differ,
 * but they all end up building the same string-keyed bag that the spec layer
 * consumes, which is what this trait factors out.
 */
trait HasLocalizedFormatting
{
    /**
     * Drops unset options and unwraps enum cases into their spec-layer strings.
     *
     * @param  array<string, \BackedEnum|int|string|null> $options
     * @return array<string, int|string>
     */
    private static function localeOptions(array $options): array
    {
        $result = [];

        foreach ($options as $key => $value) {
            if ($value === null) {
                continue;
            }

            $result[$key] = $value instanceof \BackedEnum ? $value->value : $value;
        }

        return $result;
    }
}
