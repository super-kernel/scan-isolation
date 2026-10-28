<?php

declare(strict_types=1);

namespace SuperKernel\ScanIsolation\Exception;

use RuntimeException;

final class ScanProcessorUnavailableException extends RuntimeException implements ScanIsolationExceptionInterface
{
}
