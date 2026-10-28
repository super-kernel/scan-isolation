<?php

declare(strict_types=1);

namespace SuperKernel\ScanIsolation\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SuperKernel\ScanIsolation\Contract\ScannedInterface;
use SuperKernel\ScanIsolation\Contract\ScanProcessorInterface;

final class ScanProcessorInterfaceTest extends TestCase
{
    public function testDeclaresTheScanExecutionBoundary(): void
    {
        $processor = new ReflectionClass(ScanProcessorInterface::class);
        $support = $processor->getMethod('support');
        $execute = $processor->getMethod('execute');
        $documentation = $execute->getDocComment();

        self::assertTrue($processor->isInterface());
        self::assertFalse($support->isStatic());
        self::assertSame(0, $support->getNumberOfParameters());
        self::assertSame('bool', (string) $support->getReturnType());
        self::assertFalse($processor->hasMethod('scan'));
        self::assertFalse($execute->isStatic());
        self::assertSame(1, $execute->getNumberOfParameters());
        self::assertSame('callable', (string) $execute->getParameters()[0]->getType());
        self::assertSame('void', (string) $execute->getReturnType());
        self::assertIsString($documentation);
        self::assertStringContainsString('support() returns true', $documentation);
    }

    public function testScanStateExposesOnlyItsBooleanQuery(): void
    {
        $state = new ReflectionClass(ScannedInterface::class);
        $method = $state->getMethod('isScanned');

        self::assertTrue($state->isInterface());
        self::assertCount(1, $state->getMethods());
        self::assertFalse($method->isStatic());
        self::assertSame(0, $method->getNumberOfParameters());
        self::assertSame('bool', (string) $method->getReturnType());
    }
}
