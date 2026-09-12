<?php

declare(strict_types=1);

namespace Calendrics\Trait;

use Calendrics\Spec\PlainDateTime as SpecPlainDateTime;
use Calendrics\Spec\PlainTime as SpecPlainTime;
use Calendrics\Spec\ZonedDateTime as SpecZonedDateTime;

/**
 * Marker interface declaring that a class exposes a `$spec` property whose
 * type has hour/minute/second/ms/μs/ns fields.
 *
 * Used only as a `@phpstan-require-implements` target by
 * {@see HasTimeOfDayProperties} so static analyzers can resolve
 * `$this->spec->hour` etc. inside the trait.
 *
 * @internal
 * @property-read SpecPlainTime|SpecPlainDateTime|SpecZonedDateTime $spec
 */
interface HasTimeOfDaySpec {}
