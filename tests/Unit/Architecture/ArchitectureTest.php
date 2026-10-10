<?php

declare(strict_types=1);

namespace Shakil\AadhaarOffline\Tests\Unit\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Structural rules that keep the package framework-agnostic and free of debug leftovers.
 */
class ArchitectureTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function sourceFiles(): iterable
    {
        foreach (self::phpFiles(__DIR__ . '/../../../src') as $path) {
            yield self::relative($path) => [$path];
        }
    }

    #[DataProvider('sourceFiles')]
    public function test_no_framework_code_is_used(string $path): void
    {
        $this->assertDoesNotMatchRegularExpression('/\b(Illuminate|Symfony|Laravel)\\\\/', (string) file_get_contents($path));
    }

    #[DataProvider('sourceFiles')]
    public function test_every_file_declares_strict_types(string $path): void
    {
        $this->assertStringContainsString('declare(strict_types=1);', (string) file_get_contents($path));
    }

    #[DataProvider('sourceFiles')]
    public function test_no_debug_calls_are_left_behind(string $path): void
    {
        $this->assertSame([], $this->calledFunctions($path, ['dd', 'dump', 'var_dump', 'print_r', 'ray']));
    }

    private static function relative(string $path): string
    {
        return mb_substr($path, (int) mb_strrpos($path, '/src/') + 1);
    }

    /**
     * @return list<string>
     */
    private static function phpFiles(string $directory): array
    {
        $files = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
                $files[] = (string) $file->getRealPath();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * Names from $names that are called in the file; comments and strings are ignored.
     *
     * @param  list<string> $names
     * @return list<string>
     */
    private function calledFunctions(string $path, array $names): array
    {
        $tokens = array_values(array_filter(
            token_get_all((string) file_get_contents($path)),
            static fn (array|string $token): bool => ! is_array($token) || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));
        $called = [];

        foreach ($tokens as $index => $token) {
            $previous = $tokens[$index - 1] ?? null;
            $isMethodCall = is_array($previous) && in_array($previous[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true);

            if (is_array($token) && $token[0] === T_STRING && ! $isMethodCall
                && in_array(mb_strtolower($token[1]), $names, true) && ($tokens[$index + 1] ?? null) === '(') {
                $called[] = $token[1];
            }
        }

        return $called;
    }
}
