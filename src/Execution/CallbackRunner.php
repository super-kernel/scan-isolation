<?php

declare(strict_types=1);

namespace SuperKernel\ScanIsolation\Execution;

use Throwable;

/**
 * @internal
 */
final readonly class CallbackRunner
{
    public function __construct(private Callback $callback)
    {
    }

    /**
     * @throws Throwable When the callback throws; the original throwable propagates
     */
    public function run(): never
    {
        $this->callback->run(false);
        exit(0);
    }
}
