<?php

declare(strict_types=1);

namespace SuperKernel\ScanIsolation\Processor;

use Phar;
use ReflectionException;
use SuperKernel\ScanIsolation\Contract\ScanProcessorInterface;
use SuperKernel\ScanIsolation\Execution\Callback;

use function class_exists;

/**
 * Skips isolated execution and lets parameterized callbacks restore an existing scan result.
 */
final class NullScanProcessor implements ScanProcessorInterface
{
    public function support(): bool
    {
        return class_exists(Phar::class, false) && '' !== Phar::running();
    }

    /** @throws ReflectionException When the callback cannot be reflected before execution */
    public function execute(callable $callback): void
    {
        new Callback($callback)->run(true);
    }
}
