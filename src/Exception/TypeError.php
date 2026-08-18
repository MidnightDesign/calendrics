<?php

declare(strict_types=1);

namespace Calendrics\Exception;

/**
 * Thrown when a Temporal API receives an argument of the wrong type or a
 * value that cannot be coerced to the expected type — TC39 maps this case
 * to a JavaScript `TypeError`.
 *
 * Extends `\TypeError` so native catches and test262's `TypeError` mapping
 * keep working; implements {@see CalendricsException} so callers can catch
 * every Calendrics-origin throwable through a single marker.
 */
final class TypeError extends \TypeError implements CalendricsException {}
