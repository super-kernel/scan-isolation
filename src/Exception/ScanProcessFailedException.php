<?php

declare(strict_types=1);

namespace SuperKernel\ScanIsolation\Exception;

use RuntimeException;

use function sprintf;
use function trim;

final class ScanProcessFailedException extends RuntimeException implements ScanIsolationExceptionInterface
{
    public function __construct(private readonly int $exitCode, private readonly string $processOutput = '')
    {
        $message = sprintf('The isolated scan callback failed with exit code %d.', $exitCode);
        $output = trim($processOutput);

        if ('' !== $output) {
            $message .= sprintf(' Output: %s', $output);
        }

        parent::__construct($message, $exitCode);
    }

    public function exitCode(): int
    {
        return $this->exitCode;
    }

    public function processOutput(): string
    {
        return $this->processOutput;
    }
}
