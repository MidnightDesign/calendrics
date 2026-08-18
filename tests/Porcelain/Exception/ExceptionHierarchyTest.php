<?php

declare(strict_types=1);

namespace Calendrics\Tests\Porcelain\Exception;

use Calendrics\Calendar;
use Calendrics\Exception\CalendricsException;
use Calendrics\Exception\InvalidArgument;
use Calendrics\Exception\RangeError;
use Calendrics\RoundingMode;
use PHPUnit\Framework\TestCase;

/**
 * Locks down the public exception hierarchy so a future refactor cannot
 * silently drop a parent class or marker interface that consumers rely on
 * in `catch` clauses.
 *
 * Assertions go through reflection so they describe the class declaration
 * rather than runtime narrowing — static analyzers won't constant-fold them.
 */
final class ExceptionHierarchyTest extends TestCase
{
    public function testCalendricsExceptionIsAnInterfaceExtendingThrowable(): void
    {
        $reflection = new \ReflectionClass(CalendricsException::class);

        static::assertTrue($reflection->isInterface());
        static::assertContains(\Throwable::class, $reflection->getInterfaceNames());
    }

    public function testInvalidArgumentExtendsSplParentAndImplementsMarker(): void
    {
        static::assertContains(\InvalidArgumentException::class, self::parentNames(InvalidArgument::class));
        static::assertContains(\LogicException::class, self::parentNames(InvalidArgument::class));
        static::assertContains(CalendricsException::class, self::interfaceNames(InvalidArgument::class));

        $exception = new InvalidArgument('boom');
        static::assertSame('boom', $exception->getMessage());
    }

    public function testRangeErrorExtendsSplParentAndImplementsMarker(): void
    {
        static::assertContains(\RangeException::class, self::parentNames(RangeError::class));
        static::assertContains(\RuntimeException::class, self::parentNames(RangeError::class));
        static::assertContains(CalendricsException::class, self::interfaceNames(RangeError::class));

        $exception = new RangeError('out of range');
        static::assertSame('out of range', $exception->getMessage());
    }

    public function testCalendarFromIdThrowsCalendricsInvalidArgumentForUnknownId(): void
    {
        $caught = null;
        try {
            Calendar::fromId('bogus');
        } catch (\Throwable $e) {
            $caught = $e;
        }

        static::assertInstanceOf(InvalidArgument::class, $caught);
        static::assertStringContainsString('bogus', $caught->getMessage());
    }

    public function testRoundingModeToPhpThrowsCalendricsInvalidArgumentForHalfCeil(): void
    {
        $caught = null;
        try {
            RoundingMode::HalfCeil->toPhpRoundingMode();
        } catch (\Throwable $e) {
            $caught = $e;
        }

        static::assertInstanceOf(InvalidArgument::class, $caught);
    }

    public function testRoundingModeToPhpThrowsCalendricsInvalidArgumentForHalfFloor(): void
    {
        $caught = null;
        try {
            RoundingMode::HalfFloor->toPhpRoundingMode();
        } catch (\Throwable $e) {
            $caught = $e;
        }

        static::assertInstanceOf(InvalidArgument::class, $caught);
    }

    public function testRoundingModeFromPhpThrowsCalendricsInvalidArgumentForHalfOdd(): void
    {
        $caught = null;
        try {
            RoundingMode::fromPhpRoundingMode(\RoundingMode::HalfOdd);
        } catch (\Throwable $e) {
            $caught = $e;
        }

        static::assertInstanceOf(InvalidArgument::class, $caught);
    }

    /**
     * @param class-string $class
     * @return list<class-string>
     */
    private static function parentNames(string $class): array
    {
        $names = [];
        for ($c = new \ReflectionClass($class)->getParentClass(); $c !== false; $c = $c->getParentClass()) {
            $names[] = $c->getName();
        }

        return $names;
    }

    /**
     * @param class-string $class
     * @return list<class-string>
     */
    private static function interfaceNames(string $class): array
    {
        return new \ReflectionClass($class)->getInterfaceNames();
    }
}
