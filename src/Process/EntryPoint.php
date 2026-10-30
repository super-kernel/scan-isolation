<?php

declare(strict_types=1);

namespace SuperKernel\ScanIsolation\Process;

use SuperKernel\ScanIsolation\Exception\ScanProcessorUnavailableException;

use function array_slice;

use const PHP_BINARY;

/**
 * @internal
 */
final readonly class EntryPoint
{
    /** @var non-empty-string */
    private string $script;

    /** @var list<string> */
    private array $arguments;

    /**
     * Captures the current CLI entry script and its arguments.
     *
     * @throws ScanProcessorUnavailableException When the process has no readable CLI entry script or string argv values
     */
    public function __construct()
    {
        if (!EntryPointProbe::isAvailable()) {
            throw new ScanProcessorUnavailableException(
                'Callback process reentry requires a readable CLI entry script and string argv values.',
            );
        }

        /** @var non-empty-string $script */
        $script = $_SERVER['SCRIPT_FILENAME'];
        /** @var list<string> $arguments */
        $arguments = array_slice($_SERVER['argv'], 1);
        $this->script = $script;
        $this->arguments = $arguments;
    }

    /**
     * @throws ScanProcessorUnavailableException When the process has no readable CLI entry script or string argv values
     */
    public static function current(): self
    {
        return new self();
    }

    public static function isAvailable(): bool
    {
        return EntryPointProbe::isAvailable();
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
