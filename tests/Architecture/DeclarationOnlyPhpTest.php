<?php

declare(strict_types=1);

namespace SuperKernel\ScanIsolation\Tests\Architecture;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

use function dirname;
use function file_get_contents;
use function in_array;
use function is_array;
use function sprintf;
use function token_get_all;

use const T_CLASS;
use const T_DO;
use const T_ECHO;
use const T_ENUM;
use const T_EVAL;
use const T_EXIT;
use const T_FOR;
use const T_FOREACH;
use const T_FUNCTION;
use const T_GOTO;
use const T_IF;
use const T_INCLUDE;
use const T_INCLUDE_ONCE;
use const T_INTERFACE;
use const T_NEW;
use const T_PRINT;
use const T_REQUIRE;
use const T_REQUIRE_ONCE;
use const T_RETURN;
use const T_SWITCH;
use const T_THROW;
use const T_TRAIT;
use const T_TRY;
use const T_UNSET;
use const T_USE;
use const T_VARIABLE;
use const T_WHILE;

final class DeclarationOnlyPhpTest extends TestCase
{
    public function testSourceAndTestsContainOnlyClassLikeDeclarationsAtFileScope(): void
    {
        $componentRoot = dirname(__DIR__, 2);

        foreach ([
            'src',
            'tests',
        ] as $directory) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(
                    $componentRoot . '/' . $directory,
                    FilesystemIterator::SKIP_DOTS,
                ),
            );

            foreach ($iterator as $file) {
                if (!$file->isFile() || 'php' !== $file->getExtension()) {
                    continue;
                }

                $this->assertDeclarationOnly($file->getPathname());
            }
        }
    }

    private function assertDeclarationOnly(string $path): void
    {
        $contents = file_get_contents($path);
        $this->assertNotFalse($contents, sprintf('Unable to read %s.', $path));

        $classLikeDeclarationFound = false;
        $fileScopeBehavior = [];
        $braceDepth = 0;
        $inUseStatement = false;
        $forbiddenTokens = [
            T_DO,
            T_ECHO,
            T_EVAL,
            T_EXIT,
            T_FOR,
            T_FOREACH,
            T_FUNCTION,
            T_GOTO,
            T_IF,
            T_INCLUDE,
            T_INCLUDE_ONCE,
            T_NEW,
            T_PRINT,
            T_REQUIRE,
            T_REQUIRE_ONCE,
            T_RETURN,
            T_SWITCH,
            T_THROW,
            T_TRY,
            T_UNSET,
            T_VARIABLE,
            T_WHILE,
        ];

        foreach (token_get_all($contents) as $token) {
            if ('{' === $token) {
                ++$braceDepth;

                continue;
            }

            if ('}' === $token) {
                --$braceDepth;

                continue;
            }

            if (0 === $braceDepth && ';' === $token) {
                $inUseStatement = false;

                continue;
            }

            if (0 !== $braceDepth || !is_array($token)) {
                continue;
            }

            [$id, $text, $line] = $token;

            if (T_USE === $id) {
                $inUseStatement = true;

                continue;
            }

            if ($inUseStatement) {
                continue;
            }

            if (in_array($id, [
                T_CLASS,
                T_ENUM,
                T_INTERFACE,
                T_TRAIT,
            ], true)) {
                $classLikeDeclarationFound = true;
            }

            if (in_array($id, $forbiddenTokens, true)) {
                $fileScopeBehavior[] = sprintf('line %d: %s', $line, $text);
            }
        }

        $this->assertTrue(
            $classLikeDeclarationFound,
            sprintf('%s must declare a class, interface, trait, or enum.', $path),
        );
        $this->assertSame(
            [],
            $fileScopeBehavior,
            sprintf('%s contains executable or function-style behavior at file scope.', $path),
        );
    }
}
