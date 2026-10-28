<?php

declare(strict_types=1);

namespace SuperKernel\ScanIsolation\Contract;

/**
 * Executes isolated work and an optional completion callback in the calling process.
 *
 * Implementations remain constructible before runtime support is inspected.
 */
interface ScanProcessorInterface
{
    /**
     * Reports whether this processor supports the current runtime.
     */
    public function support(): bool;

    /**
     * Runs a parameterless callback once in isolation. A callback declaring one ScannedInterface
     * parameter runs with isScanned() false in isolation, then true in the calling process after successful
     * child termination.
     * Null processors skip the isolated phase and run only the parameterized completion callback.
     * Phase processors delegate nested work to the enclosing callback's supplied phase in the same process.
     * Implementations validate callback signatures before execution. Nullable and optional ScannedInterface
     * parameters are accepted; other types, extra parameters, references, and variadic parameters are rejected.
     * Process implementations report setup, waiting, failed or unknown child status, and reentry failures.
     * Callback errors and exceptions propagate unchanged to PHP and outer handlers in their own process.
     * Only a successful child exit permits the calling phase; PCNTL also requires normal termination.
     * Callback or handler exit(0) still permits completion without proving a normal callback return.
     * The supplied state identifies the execution phase. Callers remain responsible for validating scan results.
     *
     * Callers must invoke this method only after support() returns true.
     *
     * @param callable $callback Callback accepting no parameters or one ScannedInterface parameter
     */
    public function execute(callable $callback): void;
}
