<?php

declare(strict_types=1);

namespace Calendrics;

/**
 * Direction for finding the next or previous time zone transition (e.g. DST change).
 */
enum TransitionDirection: string
{
    /** Find the next transition after the current instant. */
    case Next = 'next';

    /** Find the previous transition before the current instant. */
    case Previous = 'previous';
}
