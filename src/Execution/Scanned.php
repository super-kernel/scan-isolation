<?php

declare(strict_types=1);

namespace SuperKernel\ScanIsolation\Execution;

use SuperKernel\ScanIsolation\Contract\ScannedInterface;

/**
 * @internal
 */
final readonly class Scanned implements ScannedInterface
{
    public function __construct(private bool $scanned)
    {
    }

    public function isScanned(): bool
    {
        return $this->scanned;
    }
}
