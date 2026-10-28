<?php

declare(strict_types=1);

namespace SuperKernel\ScanIsolation\Process;

use SuperKernel\ScanIsolation\Exception\ScanProcessorUnavailableException;

use function array_all;
use function array_is_list;
use function array_slice;
use function is_array;
use function is_file;
use function is_readable;
use function is_string;

use const PHP_BINARY;
use const PHP_SAPI;

/**
 * @internal
 */
final readonly class EntryPoint
{
    /**
     * @param list<string> $arguments
     */
    private function __construct(private string $script, private array $arguments)
    {
    }

    public static function current(): self
    {
        if (!self::isAvailable()) {
            throw new ScanProcessorUnavailableException(
                'Callback process reentry requires a readable CLI entry script and string argv values.',
            );
        }

        /** @var non-empty-string $script */
        $script = $_SERVER['SCRIPT_FILENAME'];
        /** @var list<string> $arguments */
        $arguments = array_slice($_SERVER['argv'], 1);

        return new self($script, $arguments);
    }

    public static function isAvailable(): bool
    {
        if ('cli' !== PHP_SAPI) {
            return false;
        }

        $script = $_SERVER['SCRIPT_FILENAME'] ?? null;

        if (!is_string($script) || '' === $script || !is_file($script) || !is_readable($script)) {
            return false;
        }

        $arguments = $_SERVER['argv'] ?? [];

        if (!is_array($arguments) || !array_is_list($arguments)) {
            return false;
        }

        return array_all($arguments, fn($argument) => is_string($argument));
    }

    /**
     * @return list<string>
     */
    public function command(): array
    {
        return [
            PHP_BINARY,
            $this->script,
            ...$this->arguments,
        ];
    }
}
