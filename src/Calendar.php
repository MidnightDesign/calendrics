<?php

declare(strict_types=1);

namespace Calendrics;

use Calendrics\Exception\InvalidArgument;

/**
 * ECMA-402 calendar systems supported by the TC39 Temporal proposal.
 *
 * Each case's string value is the canonical IANA/CLDR calendar identifier
 * used by the spec layer.
 */
enum Calendar: string
{
    case Iso8601 = 'iso8601';
    case Buddhist = 'buddhist';
    case Chinese = 'chinese';
    case Coptic = 'coptic';
    case Dangi = 'dangi';
    case EthiopicAmeteAlem = 'ethioaa';
    case Ethiopic = 'ethiopic';
    case Gregory = 'gregory';
    case Hebrew = 'hebrew';
    case Indian = 'indian';
    case IslamicCivil = 'islamic-civil';
    case IslamicTabular = 'islamic-tbla';
    case IslamicUmalqura = 'islamic-umalqura';
    case Japanese = 'japanese';
    case Persian = 'persian';
    case Roc = 'roc';

    /**
     * Deprecated/alternate calendar ID aliases mapped to their canonical case.
     *
     * @var array<string, string>
     */
    private const ALIASES = [
        'islamicc' => 'islamic-civil',
        'ethiopic-amete-alem' => 'ethioaa',
    ];

    /**
     * Resolves a calendar identifier string to a Calendar case.
     *
     * Handles case-insensitivity and known aliases (e.g. 'islamicc' resolves
     * to IslamicCivil, 'HEBREW' resolves to Hebrew).
     *
     * @throws InvalidArgument if the identifier is not a known calendar.
     */
    public static function fromId(string $id): self
    {
        $lower = strtolower($id);

        if (array_key_exists($lower, self::ALIASES)) {
            $lower = self::ALIASES[$lower];
        }

        $result = self::tryFrom($lower);

        if ($result === null) {
            throw new InvalidArgument("Unknown calendar \"{$id}\".");
        }

        return $result;
    }
}
