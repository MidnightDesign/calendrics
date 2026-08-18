<?php

declare(strict_types=1);

namespace Calendrics\Exception;

/**
 * Marker interface implemented by every exception thrown from the Calendrics\ namespace.
 *
 * Catch this to handle any Calendrics-origin failure regardless of its SPL parent:
 *
 * ```php
 * try {
 *     Calendrics\PlainDate::from($input);
 * } catch (CalendricsException $e) {
 *     // ...
 * }
 * ```
 *
 * Concrete classes also extend the corresponding SPL parent
 * (`\InvalidArgumentException`, `\RangeException`, …), so existing
 * SPL-shaped catches keep working.
 */
interface CalendricsException extends \Throwable {}
