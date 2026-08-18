<?php

declare(strict_types=1);

namespace Calendrics\Trait;

use Calendrics\Spec\Instant as SpecInstant;
use Calendrics\Spec\ZonedDateTime as SpecZonedDateTime;

/**
 * Marker interface declaring that a class exposes a `$spec` property whose
 * type has `$epochNanoseconds` and `$epochMilliseconds` fields.
 *
 * Used only as a `@phpstan-require-implements` target by
 * {@see HasEpochProperties} so static analyzers can resolve
 * `$this->spec->epochNanoseconds` etc. inside the trait.
 *
 * @internal
 * @property-read SpecInstant|SpecZonedDateTime $spec
 */
interface HasEpochSpec {}
