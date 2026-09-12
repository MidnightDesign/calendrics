<?php

declare(strict_types=1);

namespace Calendrics\Trait;

use Calendrics\Spec\PlainDate as SpecPlainDate;
use Calendrics\Spec\PlainDateTime as SpecPlainDateTime;
use Calendrics\Spec\PlainYearMonth as SpecPlainYearMonth;
use Calendrics\Spec\ZonedDateTime as SpecZonedDateTime;

/**
 * Marker interface declaring that a class exposes a `$spec` property whose
 * type has year/month/calendar identity fields.
 *
 * Used only as a `@phpstan-require-implements` target by
 * {@see HasYearMonthProperties} so static analyzers can resolve
 * `$this->spec->year` etc. inside the trait.
 *
 * @internal
 * @property-read SpecPlainYearMonth|SpecPlainDate|SpecPlainDateTime|SpecZonedDateTime $spec
 */
interface HasYearMonthSpec {}
