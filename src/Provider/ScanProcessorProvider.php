<?php

declare(strict_types=1);

namespace SuperKernel\ScanIsolation\Provider;

use SuperKernel\Attribute\FactoryProvider;
use SuperKernel\ScanIsolation\Contract\ScanProcessorInterface;
use SuperKernel\ScanIsolation\Exception\ScanProcessorUnavailableException;
use SuperKernel\ScanIsolation\Processor\ExecScanProcessor;
use SuperKernel\ScanIsolation\Processor\NullScanProcessor;
use SuperKernel\ScanIsolation\Processor\PcntlScanProcessor;
use SuperKernel\ScanIsolation\Processor\ProcessScanProcessor;

#[FactoryProvider(ScanProcessorInterface::class)]
final readonly class ScanProcessorProvider
{
    /**
     * Uses restore-only execution in Phar archives, otherwise selects the best isolating processor.
     *
     * @throws ScanProcessorUnavailableException When no isolating processor is available
     */
    public function __invoke(): ScanProcessorInterface
    {
        $processors = [
            new NullScanProcessor(),
            new PcntlScanProcessor(),
            new ProcessScanProcessor(),
            new ExecScanProcessor(),
        ];

        foreach ($processors as $processor) {
            if ($processor->support()) {
                return $processor;
            }
        }

        throw new ScanProcessorUnavailableException(
            'No isolated callback scan processor is available; PCNTL and CLI reentry are unavailable.',
        );
    }
}
