<?php

declare(strict_types=1);

namespace Calendrics\Tools\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Flags concatenation expressions that contain string literals.
 *
 * When any direct operand in a `.` expression is a string literal,
 * the expression should use sprintf() instead for readability and
 * to keep format strings in one piece.
 *
 * @implements Rule<Concat>
 */
final class PreferSprintfOverConcatRule implements Rule
{
    public function getNodeType(): string
    {
        return Concat::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        // Only flag when a direct operand is a string literal.
        // Reports once per literal in a concat chain.
        if (!($node->left instanceof String_) && !($node->right instanceof String_)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(
                'Use sprintf() instead of string literal concatenation.',
            )->identifier('temporal.preferSprintf')->build(),
        ];
    }
}
