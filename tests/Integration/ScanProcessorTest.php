<?php

declare(strict_types=1);

namespace SuperKernel\ScanIsolation\Tests\Integration;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use SuperKernel\ScanIsolation\Contract\ScanProcessorInterface;
use SuperKernel\ScanIsolation\Processor\ExecScanProcessor;
use SuperKernel\ScanIsolation\Processor\NullScanProcessor;
use SuperKernel\ScanIsolation\Processor\PcntlScanProcessor;
use SuperKernel\ScanIsolation\Processor\ProcessScanProcessor;
use Throwable;

use function dirname;
use function fclose;
use function file_put_contents;
use function is_file;
use function json_decode;
use function proc_close;
use function proc_open;
use function sprintf;
use function stream_get_contents;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;
use function var_export;

use const JSON_THROW_ON_ERROR;
use const PHP_BINARY;

final class ScanProcessorTest extends TestCase
{
    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testPcntlRunsTheClosureOnlyInsideTheChildProcess(): void
    {
        $this->assertCallback(PcntlScanProcessor::class);
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testProcessRunsTheClosureOnlyInsideTheReenteredChildProcess(): void
    {
        $this->assertCallback(ProcessScanProcessor::class);
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testExecRunsTheClosureOnlyInsideTheReenteredChildProcess(): void
    {
        $this->assertCallback(ExecScanProcessor::class);
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testPcntlLeavesTheOriginalExceptionToTheChildHandler(): void
    {
        $this->assertCallback(PcntlScanProcessor::class, fail: true);
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testProcessLeavesTheOriginalExceptionToTheChildHandler(): void
    {
        $this->assertCallback(ProcessScanProcessor::class, fail: true);
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testExecLeavesTheOriginalExceptionToTheChildHandler(): void
    {
        $this->assertCallback(ExecScanProcessor::class, fail: true);
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testNullProcessorDoesNotExecuteTheCallback(): void
    {
        $executed = false;
        $processor = new NullScanProcessor();
        $processor->execute(static function () use (&$executed): void {
            $executed = true;
        });
        self::assertFalse($executed);
    }

    /**
     * @param class-string<ScanProcessorInterface> $processorClass
     *
     * @throws Throwable When fixture setup or a supplied test callback fails
     */
    private function assertCallback(string $processorClass, bool $fail = false): void
    {
        if (!new $processorClass()->support()) {
            self::markTestSkipped($processorClass . ' is unavailable.');
        }

        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';

        self::assertFileExists($autoload);
        $script = tempnam(sys_get_temp_dir(), 'super-kernel-scan-test-');
        self::assertIsString($script);
        $marker = $script . '.marker';

        try {
            $body = sprintf(
                <<<'PHP'
                    <?php
                    require %s;
                    $processor = new %s();
                    $marker = %s;
                    $fixture = %s;
                    $fail = %s;
                    $parentPid = getmypid();
                    $executed = false;
                    set_exception_handler(static function (Throwable $failure) use ($marker): void {
                        file_put_contents($marker, $failure::class . ': ' . $failure->getMessage());
                        exit(17);
                    });

                    if (!$processor->support()) {
                        throw new RuntimeException('The child processor is unsupported.');
                    }

                    $status = null;
                    try {
                        $processor->execute(static function () use ($marker, $fixture, $fail, &$executed): void {
                            $executed = true;

                            if ($fail) {
                                throw new RuntimeException('scan failure');
                            }

                            require $fixture;

                            if (!class_exists(SuperKernel\ScanIsolation\Tests\Fixture\IsolatedClass::class, false)) {
                                throw new RuntimeException('The isolated fixture was not loaded.');
                            }

                            file_put_contents($marker, (string) getmypid());
                        });
                    } catch (SuperKernel\ScanIsolation\Exception\ScanProcessFailedException $failure) {
                        $status = $failure->exitCode();
                    }
                    echo json_encode([
                        'status' => $status,
                        'executedByParent' => $executed,
                        'declaredInParent' => class_exists(
                            SuperKernel\ScanIsolation\Tests\Fixture\IsolatedClass::class,
                            false,
                        ),
                        'differentPid' => (string) $parentPid !== file_get_contents($marker),
                        'outerFailure' => $fail ? file_get_contents($marker) : null,
                    ], JSON_THROW_ON_ERROR);
                    PHP,
                var_export($autoload, true),
                $processorClass,
                var_export($marker, true),
                var_export(dirname(__DIR__) . '/Fixture/IsolatedClass.php', true),
                var_export($fail, true),
            );
            self::assertNotFalse(file_put_contents($script, $body));
            $process = proc_open(
                [
                    PHP_BINARY,
                    $script,
                ],
                [
                    0 => [
                        'pipe',
                        'r',
                    ],
                    1 => [
                        'pipe',
                        'w',
                    ],
                    2 => [
                        'pipe',
                        'w',
                    ],
                ],
                $pipes,
            );
            self::assertIsResource($process);
            fclose($pipes[0]);
            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $error . $output);
            $result = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

            if ($fail) {
                self::assertSame(17, $result['status']);
                self::assertSame(RuntimeException::class . ': scan failure', $result['outerFailure']);
                self::assertFalse($result['executedByParent']);
            } else {
                self::assertNull($result['status']);
                self::assertFalse($result['executedByParent']);
                self::assertFalse($result['declaredInParent']);
                self::assertTrue($result['differentPid']);
            }
        } finally {
            unlink($script);

            if (is_file($marker)) {
                unlink($marker);
            }
        }
    }
}
