<?php

declare(strict_types=1);

namespace SuperKernel\ScanIsolation\Processor;

use ReflectionException;
use SuperKernel\ScanIsolation\Contract\ScannedInterface;
use SuperKernel\ScanIsolation\Contract\ScanProcessorInterface;
use SuperKernel\ScanIsolation\Execution\Callback;

/**
 * Delegates nested work to the phase supplied by an enclosing isolated execution.
 *
 * Construct inside an execute() callback: false already runs in isolation, while true restores results.
 * This processor never starts another child and is not part of automatic runtime selection.
 */
final readonly class PhaseScanProcessor implements ScanProcessorInterface
{
    public function __construct(private ScannedInterface $scanned)
    {
    }

    public function support(): bool
    {
        return true;
    }

    /** @throws ReflectionException When the callback cannot be reflected before execution */
    public function execute(callable $callback): void
    {
        new Callback($callback)->run($this->scanned->isScanned());
    }
}
