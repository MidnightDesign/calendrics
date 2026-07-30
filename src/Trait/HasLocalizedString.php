<?php

declare(strict_types=1);

namespace Temporal\Trait;

use Temporal\DateStyle;
use Temporal\TimeStyle;

/**
 * Shared option-bag construction for the porcelain `toLocaleString()` methods.
 *
 * ECMA-402 rejects `dateStyle` on a time-only value and `timeStyle` on a
 * date-only value with a `TypeError`. The porcelain layer makes those calls
 * unrepresentable instead: each class declares only the style parameters that
 * apply to it, so the compiler catches what the spec layer catches at runtime.
 * All that is left to share is turning the accepted enums into the option array
 * the spec layer expects.
 *
 * @internal
 */
trait HasLocalizedString
{
    /**
     * Builds the spec-layer option array for the given style selection.
     *
     * Absent styles are omitted rather than defaulted, which lets the spec
     * layer apply ECMA-402's own per-type defaults.
     *
     * @return array<string, string>
     */
    private static function localeStyleOptions(?DateStyle $dateStyle = null, ?TimeStyle $timeStyle = null): array
    {
        $options = [];

        if ($dateStyle !== null) {
            $options['dateStyle'] = $dateStyle->value;
        }
        if ($timeStyle !== null) {
            $options['timeStyle'] = $timeStyle->value;
        }

        return $options;
    }
}
