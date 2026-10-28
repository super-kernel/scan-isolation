<?php

declare(strict_types=1);

namespace SuperKernel\ScanIsolation\Contract;

interface ScannedInterface
{
    /**
     * Returns false during isolated execution and true during current-process completion.
     */
    public function isScanned(): bool;
}
