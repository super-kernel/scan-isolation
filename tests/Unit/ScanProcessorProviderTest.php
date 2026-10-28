<?php

declare(strict_types=1);

namespace SuperKernel\ScanIsolation\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use SuperKernel\Attribute\FactoryProvider;
use SuperKernel\ScanIsolation\Contract\ScanProcessorInterface;
use SuperKernel\ScanIsolation\Exception\ScanProcessorUnavailableException;
use SuperKernel\ScanIsolation\Processor\ExecScanProcessor;
use SuperKernel\ScanIsolation\Processor\NullScanProcessor;
use SuperKernel\ScanIsolation\Processor\PcntlScanProcessor;
use SuperKernel\ScanIsolation\Processor\ProcessScanProcessor;
use SuperKernel\ScanIsolation\Provider\ScanProcessorProvider;
use Throwable;

use function array_slice;
use function enum_exists;
use function file;
use function implode;

final class ScanProcessorProviderTest extends TestCase
{
    public function testDeclaresTheDefaultScanProcessorProvider(): void
    {
        $reflection = new ReflectionClass(ScanProcessorProvider::class);
        $attributes = $reflection->getAttributes(FactoryProvider::class);

        self::assertTrue($reflection->isFinal());
        self::assertTrue($reflection->isReadOnly());
        self::assertNull($reflection->getConstructor());
        self::assertCount(1, $attributes);
        self::assertSame(ScanProcessorInterface::class, $attributes[0]->newInstance()->className);
        self::assertFalse($reflection->hasMethod('create'));
        self::assertFalse(enum_exists('SuperKernel\\ScanIsolation\\Enum\\ScanProcessorType'));
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testConcreteProcessorsCanBeConstructedDirectly(): void
    {
        $processors = [
            new PcntlScanProcessor(),
            new ProcessScanProcessor(),
            new ExecScanProcessor(),
            new NullScanProcessor(),
        ];

        foreach ($processors as $processor) {
            self::assertNull(new ReflectionClass($processor)->getConstructor());
            self::assertInstanceOf(ScanProcessorInterface::class, $processor);
            self::assertIsBool($processor->support());
        }

        self::assertFalse($processors[3]->support());
    }

    public function testUnavailableReentryProcessorsRemainConstructibleForCallerProbes(): void
    {
        $originalScript = $_SERVER['SCRIPT_FILENAME'] ?? null;
        $missingScript = __DIR__ . '/missing-reentry.php';
        self::assertFileDoesNotExist($missingScript);
        $_SERVER['SCRIPT_FILENAME'] = $missingScript;

        try {
            foreach ([
                new ProcessScanProcessor(),
                new ExecScanProcessor(),
            ] as $processor) {
                self::assertFalse($processor->support());
            }
        } finally {
            if (null === $originalScript) {
                unset($_SERVER['SCRIPT_FILENAME']);
            } else {
                $_SERVER['SCRIPT_FILENAME'] = $originalScript;
            }
        }
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testConcreteExecutionMethodsDoNotRepeatTheSupportProbe(): void
    {
        foreach ([
            PcntlScanProcessor::class,
            ProcessScanProcessor::class,
            ExecScanProcessor::class,
            NullScanProcessor::class,
        ] as $processorClass) {
            $scan = new ReflectionMethod($processorClass, 'execute');
            $fileName = $scan->getFileName();
            $startLine = $scan->getStartLine();
            $endLine = $scan->getEndLine();

            self::assertIsString($fileName);
            self::assertIsInt($startLine);
            self::assertIsInt($endLine);
            $source = file($fileName);
            self::assertIsArray($source);

            self::assertStringNotContainsString(
                '$this->support(',
                implode('', array_slice($source, $startLine - 1, $endLine - $startLine + 1)),
                $processorClass,
            );
        }
    }

    public function testAutomaticSelectionUsesThePreferredAvailableProcessor(): void
    {
        $provider = new ScanProcessorProvider();
        $expected = null;

        foreach ([
            new NullScanProcessor(),
            new PcntlScanProcessor(),
            new ProcessScanProcessor(),
            new ExecScanProcessor(),
        ] as $processor) {
            if ($processor->support()) {
                $expected = $processor;

                break;
            }
        }

        if (null === $expected) {
            $this->expectException(ScanProcessorUnavailableException::class);

            $provider();

            return;
        }

        $actual = $provider();

        self::assertSame($expected::class, $actual::class);
        self::assertTrue($actual->support());
    }
}
