<?php

declare(strict_types=1);

namespace Calendrics\Tools\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\BinaryOp;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\FloatType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\VerbosityLevel;

/**
 * Disallows mixing int and float operands in arithmetic expressions.
 *
 * Both operands must be the same numeric family — either both int or both float.
 * This catches missing (float)/(int) casts that Infection's CastFloat/CastInt
 * mutators exploit.
 *
 * @implements Rule<Expr>
 */
final class NoMixedIntFloatArithmeticRule implements Rule
{
    /** @var array<string, string> */
    private const BINARY_OPS = [
        BinaryOp\Plus::class => '+',
        BinaryOp\Minus::class => '-',
        BinaryOp\Mul::class => '*',
        BinaryOp\Div::class => '/',
        BinaryOp\Mod::class => '%',
        BinaryOp\Pow::class => '**',
    ];

    /** @var array<string, string> */
    private const ASSIGN_OPS = [
        AssignOp\Plus::class => '+=',
        AssignOp\Minus::class => '-=',
        AssignOp\Mul::class => '*=',
        AssignOp\Div::class => '/=',
        AssignOp\Mod::class => '%=',
        AssignOp\Pow::class => '**=',
    ];

    public function getNodeType(): string
    {
        return Expr::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $op = self::BINARY_OPS[$node::class] ?? self::ASSIGN_OPS[$node::class] ?? null;
        if ($op === null) {
            return [];
        }

        if ($node instanceof BinaryOp) {
            $left = $node->left;
            $right = $node->right;
        } elseif ($node instanceof AssignOp) {
            $left = $node->var;
            $right = $node->expr;
        } else {
            return [];
        }

        $leftType = $scope->getType($left);
        $rightType = $scope->getType($right);

        if (!$this->isMixedIntFloat($leftType, $rightType)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'Mixed int/float operands in %s: left is %s, right is %s. Cast both to the same type.',
                $op,
                $leftType->describe(VerbosityLevel::typeOnly()),
                $rightType->describe(VerbosityLevel::typeOnly()),
            ))->identifier('temporal.mixedIntFloatArithmetic')->build(),
        ];
    }

    private function isMixedIntFloat(Type $left, Type $right): bool
    {
        $leftIsInt = $this->isIntFamily($left);
        $leftIsFloat = $this->isFloatFamily($left);
        $rightIsInt = $this->isIntFamily($right);
        $rightIsFloat = $this->isFloatFamily($right);

        // Only flag when one side is purely int and the other purely float.
        return ($leftIsInt && $rightIsFloat) || ($leftIsFloat && $rightIsInt);
    }

    private function isIntFamily(Type $type): bool
    {
        $type = TypeCombinator::removeNull($type);

        return $type->isInteger()->yes();
    }

    private function isFloatFamily(Type $type): bool
    {
        $type = TypeCombinator::removeNull($type);

        return $type->isFloat()->yes();
    }
}
