<?php

declare(strict_types=1);

namespace SuperKernel\ScanIsolation\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stringable;
use SuperKernel\ScanIsolation\Contract\ScannedInterface;
use SuperKernel\ScanIsolation\Contract\ScanProcessorInterface;
use SuperKernel\ScanIsolation\Exception\InvalidScanCallbackException;
use SuperKernel\ScanIsolation\Execution\Scanned;
use SuperKernel\ScanIsolation\Processor\ExecScanProcessor;
use SuperKernel\ScanIsolation\Processor\NullScanProcessor;
use SuperKernel\ScanIsolation\Processor\PcntlScanProcessor;
use SuperKernel\ScanIsolation\Processor\ProcessScanProcessor;
use Throwable;

final class CallbackValidationTest extends TestCase
{
    /** @param class-string<ScanProcessorInterface> $processorClass */
    #[DataProvider('invalidCallbacks')]
    public function testRejectsInvalidInjectionBeforeExecutingAnyCallback(
        string   $processorClass,
        callable $callback,
    ): void
    {
        $this->expectException(InvalidScanCallbackException::class);
        $this->expectExceptionMessageIsOrContains(ScannedInterface::class);

        new $processorClass()->execute($callback);
    }

    /** @return iterable<string, array{class-string<ScanProcessorInterface>, callable}> */
    public static function invalidCallbacks(): iterable
    {
        $callbacks = [
            'untyped' => static function ($state): void {
            },
            'scalar' => static function (bool $state): void {
            },
            'object' => static function (object $state): void {
            },
            'mixed' => static function (mixed $state): void {
            },
            'concrete' => static function (Scanned $state): void {
            },
            'union' => static function (ScannedInterface|string $state): void {
            },
            'intersection' => static function (ScannedInterface&Stringable $state): void {
            },
            'reference' => static function (ScannedInterface &$state): void {
            },
            'variadic' => static function (ScannedInterface ...$state): void {
            },
            'extra' => static function (ScannedInterface $state, ?string $extra = null): void {
            },
            'optional-untyped' => static function ($state = null): void {
            },
            'object-method' => [
                new class() {
                    public function run(string $state): void
                    {
                    }
                },
                'run',
            ],
        ];

        foreach ([
            PcntlScanProcessor::class,
            ProcessScanProcessor::class,
            ExecScanProcessor::class,
            NullScanProcessor::class,
        ] as $processorClass) {
            foreach ($callbacks as $name => $callback) {
                yield $processorClass . '-' . $name => [
                    $processorClass,
                    $callback,
                ];
            }
        }
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testNullRunsOnlyTheCompletionPhase(): void
    {
        $states = [];
        $processor = new NullScanProcessor();
        $processor->execute(static function (ScannedInterface $state) use (&$states): void {
            $states[] = $state;
        });

        self::assertCount(1, $states);
        self::assertTrue($states[0]->isScanned());
    }
}
