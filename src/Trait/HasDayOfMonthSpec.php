<?php

declare(strict_types=1);

namespace Calendrics\Trait;

use Calendrics\Spec\PlainDate as SpecPlainDate;
use Calendrics\Spec\PlainDateTime as SpecPlainDateTime;
use Calendrics\Spec\ZonedDateTime as SpecZonedDateTime;

/**
 * Marker interface declaring that a class exposes a `$spec` property whose
 * type has day-of-month, day-of-week, and week-of-year fields.
 *
 * Used only as a `@phpstan-require-implements` target by
 * {@see HasDayOfMonthProperties} so static analyzers can resolve
 * `$this->spec->day` etc. inside the trait.
 *
 * @internal
 * @property-read SpecPlainDate|SpecPlainDateTime|SpecZonedDateTime $spec
 */
interface HasDayOfMonthSpec {}
