<?php

declare(strict_types=1);

namespace Calendrics\Tests\Porcelain;

use Calendrics\Now;
use PHPUnit\Framework\TestCase;

/**
 * Locks down the side-effect tags on the public porcelain API, so a new method
 * or a docblock edit cannot silently drop one. Consumers whose Mago config sets
 * `allow-side-effects-in-conditions = false` rely on these tags to call
 * porcelain methods inside conditions.
 *
 * The rule: a public method gets a tag when it writes no state a caller can
 * observe, and its result depends only on its arguments and the object it is
 * called on. This is what Psalm documents for mutation-free methods. Filling an
 * internal memo cache is not an observable write. Reading the clock, the
 * default locale or the default time zone is a hidden input, so a method that
 * does so gets no tag. Use `@pure` where PHPStan and Psalm can verify it, and
 * `@mutation-free` everywhere else.
 *
 * The `fromDateTime()` factories follow the rule even though a subclass of
 * `\DateTime` or `\DateTimeImmutable` can override the `format()`,
 * `getTimestamp()` and `getTimezone()` methods they call. Those methods only
 * read the caller's object, and an override that adds side effects breaks the
 * contract of the PHP class it extends.
 *
 * This test checks that the tags are there. No analyzer checks that a
 * `@mutation-free` method keeps its promise.
 */
final class PurityAnnotationTest extends TestCase
{
    /** Methods that get no tag on any porcelain class, and why. */
    private const array UNTAGGED_METHODS = [
        '__construct' => 'A constructor writes the properties of the object it creates.',
        'toLocaleString' => 'It reads the default locale, which the program can change at runtime.',
    ];

    /** Classes whose methods get no tag, and why. */
    private const array UNTAGGED_CLASSES = [
        Now::class => 'Its methods read the clock or the default time zone.',
    ];

    public function testEveryOtherPublicMethodIsTagged(): void
    {
        $methods = self::publicMethods();
        static::assertNotSame([], $methods);

        $untagged = [];
        foreach ($methods as $method) {
            if (self::exclusionReason($method) !== null || self::isTagged($method)) {
                continue;
            }

            $untagged[] = self::label($method);
        }

        static::assertSame([], $untagged, 'Tag these with @mutation-free or @pure, or list them as untagged.');
    }

    public function testUntaggedMethodsCarryNoTag(): void
    {
        $tagged = [];
        foreach (self::publicMethods() as $method) {
            $reason = self::exclusionReason($method);
            if ($reason === null || !self::isTagged($method)) {
                continue;
            }

            $tagged[self::label($method)] = $reason;
        }

        static::assertSame([], $tagged);
    }

    /**
     * Every public method written in a porcelain class or enum (`src/*.php`),
     * including the ones it takes from a trait.
     *
     * @return list<\ReflectionMethod>
     */
    private static function publicMethods(): array
    {
        $files = glob(sprintf('%s/src/*.php', dirname(__DIR__, levels: 2)));
        static::assertNotFalse($files);

        $methods = [];
        foreach ($files as $file) {
            $class = sprintf('Calendrics\\%s', basename($file, suffix: '.php'));
            if (!class_exists($class)) {
                static::fail(sprintf('%s does not declare %s.', $file, $class));
            }

            foreach (new \ReflectionClass($class)->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if (!$method->isUserDefined()) {
                    continue;
                }

                $methods[] = $method;
            }
        }

        return $methods;
    }

    private static function exclusionReason(\ReflectionMethod $method): ?string
    {
        return self::UNTAGGED_CLASSES[$method->class] ?? self::UNTAGGED_METHODS[$method->name] ?? null;
    }

    private static function isTagged(\ReflectionMethod $method): bool
    {
        $docComment = $method->getDocComment();

        return $docComment !== false && preg_match('/@(?:mutation-free|pure)(?![\w-])/', $docComment) === 1;
    }

    private static function label(\ReflectionMethod $method): string
    {
        return sprintf('%s::%s()', $method->class, $method->name);
    }
}
