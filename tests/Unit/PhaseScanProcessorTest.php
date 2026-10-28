<?php

declare(strict_types=1);

namespace SuperKernel\ScanIsolation\Tests\Unit;

use DomainException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SuperKernel\ScanIsolation\Contract\ScannedInterface;
use SuperKernel\ScanIsolation\Exception\InvalidScanCallbackException;
use SuperKernel\ScanIsolation\Execution\Scanned;
use SuperKernel\ScanIsolation\Processor\PhaseScanProcessor;
use Throwable;

use function getmypid;

final class PhaseScanProcessorTest extends TestCase
{
    /** @throws Throwable When fixture setup or the operation under test throws */
    #[DataProvider('phases')]
    public function testRunsOnlyTheEnclosingPhaseInTheSameProcess(bool $scanned): void
    {
        $processor = new PhaseScanProcessor(new Scanned($scanned));
        $calls = 0;
        $states = [];
        self::assertTrue($processor->support());
        $processor->execute(static function () use (&$calls): void {
            ++$calls;
        });
        $processor->execute(static function (ScannedInterface $state) use (&$states): void {
            $states[] = [
                $state->isScanned(),
                getmypid(),
            ];
        });

        self::assertSame($scanned ? 0 : 1, $calls);
        self::assertSame([
            [
                $scanned,
                getmypid(),
            ],
        ], $states);
    }

    /**
     * The callback retains the signature required by its registration or reflection contract.
     *
     * @throws Throwable When fixture setup or the operation under test throws
     *
     * @noinspection PhpUnusedParameterInspection
     */
    #[DataProvider('phases')]
    public function testPreservesTheOriginalCallbackException(bool $scanned): void
    {
        $original = new DomainException('phase failure');

        try {
            new PhaseScanProcessor(new Scanned($scanned))->execute(
                static function (ScannedInterface $state) use ($original): void {
                    throw $original;
                },
            );
            self::fail('The original exception must propagate.');
        } catch (DomainException $failure) {
            self::assertSame($original, $failure);
        }
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    #[DataProvider('phases')]
    public function testValidatesCallbackTypesBeforeEitherPhase(bool $scanned): void
    {
        $this->expectException(InvalidScanCallbackException::class);

        new PhaseScanProcessor(new Scanned($scanned))->execute(static function (string $invalid): void {
        });
    }

    /** @return iterable<string, array{bool}> */
    public static function phases(): iterable
    {
        yield 'isolated' => [
            false,
        ];
        yield 'current' => [
            true,
        ];
    }
}
