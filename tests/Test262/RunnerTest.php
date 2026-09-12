<?php

declare(strict_types=1);

namespace Calendrics\Tests\Test262;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Discovers and runs all generated PHP test scripts under tests/Test262/scripts/.
 *
 * Each script file becomes one data-provider test case. Scripts use the
 * Assert helper class to bridge TC39-style assertions to PHPUnit.
 */
final class RunnerTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function scripts(): iterable
    {
        $dir = sprintf('%s/scripts', __DIR__);
        if (!is_dir($dir)) {
            return;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
            $dir,
            RecursiveDirectoryIterator::SKIP_DOTS,
        ));
        /** @var \SplFileInfo $file */
        foreach ($it as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $name = ltrim(str_replace(search: $dir, replace: '', subject: $file->getPathname()), characters: '/');
            yield $name => [$file->getPathname()];
        }
    }

    #[DataProvider('scripts')]
    public function testScript(string $path): void
    {
        self::maybeSkipForIcuVersion($path);
        try {
            /** @psalm-suppress UnresolvableInclude */
            require $path;
        } catch (\Throwable $e) {
            self::handleScriptThrowable($e);
        }
    }

    /**
     * Skips fixtures whose expected values depend on ICU/CLDR data that was updated
     * after the host's ICU version. The Chinese calendar's leap-year placement is the
     * known case: TC39's `daysInYear/basic-chinese.{js,php}` was authored against ICU
     * 76+ (October 2024) data, and Ubuntu 24.04's bundled libicu74 produces a
     * different sequence of leap years for some years in the 1969–2048 sample range.
     *
     * Production users on libicu74 will see the same divergence — see README's
     * "ICU compatibility" note.
     */
    private static function maybeSkipForIcuVersion(string $path): void
    {
        if (!str_contains($path, 'basic-chinese') || !str_contains($path, '/daysInYear/')) {
            return;
        }
        // Read INTL_ICU_VERSION via constant() so PHPStan doesn't const-fold it to the
        // analyzer's host value and prove the version_compare branch unreachable. Static
        // analysis sees this as a `mixed` lookup; production sees the real linked-libicu
        // version.
        /** @var string $icu */
        $icu = constant('INTL_ICU_VERSION');
        if (version_compare($icu, version2: '76', operator: '<')) {
            static::markTestIncomplete(sprintf(
                'Chinese-calendar daysInYear fixtures require ICU ≥ 76 (host has %s); leap-year placement diverges below that.',
                $icu,
            ));
        }
    }

    /**
     * Dispatches throwables from generated test262 scripts:
     *
     *   - `\ParseError`         → fail loudly (transpiler bug, not a legitimate incomplete test).
     *   - `\Error` from `src/Spec/` → fail loudly. The fixture didn't wrap this call in
     *     `Assert::throws(...)`, which means it expected the call to succeed. A throw escaping
     *     our spec layer is a spec deviation and must surface as a real failure, not silently
     *     downgrade to "incomplete." This catches the case where a positive test262 fixture
     *     pinpoints a bug (we throw where the spec mandates success) — the fixture exists but
     *     would otherwise stay hidden in the incomplete bucket.
     *   - Other `\Error` (e.g. `\UnhandledMatchError` from a transpiler-emitted match,
     *     `\AssertionError`, `\Error: Call to undefined method`): these indicate the generated
     *     PHP cannot run because the transpiler couldn't model the JS fixture 1:1. Mark
     *     incomplete.
     *   - `\Exception` subclasses: re-throw so PHPUnit records the real assertion / runtime error.
     */
    private static function handleScriptThrowable(\Throwable $e): void
    {
        if ($e instanceof \ParseError) {
            static::fail(sprintf('Syntax error in generated script: %s', $e->getMessage()));
        }
        if ($e instanceof \Error) {
            if (self::originatesInSpecLayer($e)) {
                throw $e;
            }
            static::markTestIncomplete(sprintf('PHP error (transpiler artifact): %s', $e->getMessage()));
        }
        throw $e;
    }

    /**
     * Returns true when the error came from our spec implementation, distinguishing
     * real spec deviations from transpiler artifacts (PHP-native argument-type
     * checks that fire before the function body runs because PHP's typed
     * parameters reject inputs that JS would coerce).
     */
    private static function originatesInSpecLayer(\Throwable $e): bool
    {
        if (!str_contains($e->getFile(), '/src/Spec/')) {
            return false;
        }

        // PHP-native argument-type-mismatch errors are thrown before the function
        // body runs, with a message of the form:
        //   "Class::method(): Argument #N ($name) must be of type X, Y given, called in /path/file.php on line M"
        // The throw site looks like it's in src/Spec/ (it points at the function
        // definition), but the function body never executed. If the calling site
        // is a transpiled test262 script, it's a transpiler artifact: PHP's typed
        // parameters reject inputs that JS would coerce. If the calling site is
        // also in src/Spec/, it's a genuine impl mismatch (one Spec method
        // passing the wrong type to another). Distinguish by extracting the
        // "called in" path.
        $m = null;
        if (preg_match('/, called in (.+) on line \d+$/', $e->getMessage(), $m) === 1) {
            return str_contains($m[1], '/src/Spec/');
        }

        // Anything else — explicit `throw new ...`, return-value-type mismatch
        // (function ran but returned the wrong type), `\UnhandledMatchError` from
        // an exhaustive match, etc. — is a real impl issue that should fail.
        return true;
    }
}
