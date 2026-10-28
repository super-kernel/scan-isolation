<?php

declare(strict_types=1);

namespace SuperKernel\ScanIsolation\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SuperKernel\ScanIsolation\Contract\ScanProcessorInterface;
use SuperKernel\ScanIsolation\Processor\ExecScanProcessor;
use SuperKernel\ScanIsolation\Processor\PcntlScanProcessor;
use SuperKernel\ScanIsolation\Processor\ProcessScanProcessor;
use Throwable;

use function dirname;
use function fclose;
use function file_get_contents;
use function file_put_contents;
use function function_exists;
use function glob;
use function json_decode;
use function proc_close;
use function proc_open;
use function stream_get_contents;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;
use function var_export;

use const JSON_THROW_ON_ERROR;
use const PHP_BINARY;

final class ExitStatusTest extends TestCase
{
    /**
     * @param class-string<ScanProcessorInterface> $processorClass
     *
     * @throws Throwable When running the isolated fixture fails
     */
    #[DataProvider('cases')]
    public function testOnlySuccessfulStatusesPermitCompletion(string $processorClass, string $mode): void
    {
        if (!new $processorClass()->support() || ('signal' === $mode && !function_exists('posix_kill'))) {
            self::markTestSkipped('The selected native process capability is unavailable.');
        }
        $result = $this->runFixture($processorClass, $mode);
        $successful = 'return' === $mode || 'exit0' === $mode || 'handler0' === $mode;
        self::assertSame($processorClass, $result['processor']);
        self::assertNotSame($result['pid'], $result['childPid']);
        self::assertSame($successful ? 1 : 0, $result['completions']);
        self::assertNull($result['previous']);
        if ($successful) {
            self::assertNull($result['failure']);
        } else {
            self::assertGreaterThan(0, $result['failure']);
            if ('exit17' === $mode || 'handler17' === $mode) {
                self::assertSame(17, $result['failure']);
            }
            if ('signal' === $mode && PcntlScanProcessor::class === $processorClass) {
                self::assertSame(143, $result['failure']);
                self::assertStringContainsString('signal 15', $result['diagnostic']);
            }
        }
        self::assertSame(ExecScanProcessor::class === $processorClass ? '' : "child stdout\n", $result['stdout']);
        self::assertStringContainsString('child stderr', $result['stderr']);
        if (ExecScanProcessor::class === $processorClass) {
            self::assertStringContainsString('child stdout', $result['stderr']);
        }
        if ('throw' === $mode) {
            self::assertStringContainsString('Uncaught DomainException: child failure', $result['stderr']);
            self::assertStringNotContainsString('child failure', $result['diagnostic']);
        }
    }

    /** @throws Throwable When the interrupted wait probe fails */
    public function testPcntlRetriesAnInterruptedWait(): void
    {
        if (!new PcntlScanProcessor()->support() || !function_exists('posix_kill')) {
            self::markTestSkipped('PCNTL signal delivery is unavailable.');
        }
        $result = $this->runFixture(PcntlScanProcessor::class, 'interrupt');
        self::assertSame(1, $result['interruptions']);
        self::assertSame(1, $result['completions']);
        self::assertNull($result['failure']);
    }

    /** @throws Throwable When the acknowledgement probe fails */
    public function testZeroExitWithWrongAcknowledgementIsRejected(): void
    {
        foreach ([
            ProcessScanProcessor::class,
            ExecScanProcessor::class,
        ] as $processor) {
            if (!new $processor()->support()) {
                continue;
            }
            $result = $this->runFixture($processor, 'wrong-ack');
            self::assertSame(127, $result['failure']);
            self::assertSame(0, $result['completions']);
            self::assertStringContainsString('did not execute the callback', $result['diagnostic']);
        }
    }

    /** @throws Throwable When forwarding into a closed parent stream fails */
    public function testOutputFailureDoesNotReplaceKnownChildStatus(): void
    {
        foreach ([
            ProcessScanProcessor::class,
            ExecScanProcessor::class,
        ] as $processor) {
            if (!new $processor()->support()) {
                continue;
            }
            $result = $this->runFixture($processor, 'closed-parent-stream');
            self::assertSame(17, $result['failure']);
            self::assertSame(0, $result['completions']);
        }
    }

    /** @return iterable<string, array{class-string<ScanProcessorInterface>, string}> */
    public static function cases(): iterable
    {
        foreach ([
            PcntlScanProcessor::class,
            ProcessScanProcessor::class,
            ExecScanProcessor::class,
        ] as $processor) {
            foreach ([
                'return',
                'exit0',
                'exit17',
                'throw',
                'handler0',
                'handler17',
                'signal',
            ] as $mode) {
                yield $processor . '-' . $mode => [
                    $processor,
                    $mode,
                ];
            }
        }
    }

    /** @throws Throwable When running the native status probe fails */
    public function testReapedChildrenCannotBeMistakenForSuccess(): void
    {
        if (!function_exists('pcntl_signal')) {
            self::markTestSkipped('SIGCHLD control is unavailable.');
        }
        foreach ([
            ProcessScanProcessor::class,
            ExecScanProcessor::class,
        ] as $processor) {
            if (!new $processor()->support()) {
                continue;
            }
            $result = $this->runFixture($processor, 'unknown');
            self::assertSame(127, $result['failure']);
            self::assertSame(0, $result['completions']);
            self::assertStringContainsString('Unable to determine', $result['diagnostic']);
        }
    }

    /** @throws Throwable When probing disabled status functions fails */
    public function testMissingStatusFunctionsDisablePcntlSelection(): void
    {
        foreach ([
            'pcntl_wifexited',
            'pcntl_wexitstatus',
            'pcntl_wifsignaled',
            'pcntl_wtermsig',
        ] as $function) {
            $result = $this->runFixture(PcntlScanProcessor::class, 'probe', $function);
            self::assertFalse($result['supported']);
            self::assertNotSame(PcntlScanProcessor::class, $result['selected']);
        }
    }

    /**
     * @param class-string<ScanProcessorInterface> $processor
     *
     * @return array<string, mixed>
     *
     * @throws Throwable When fixture creation or execution fails
     */
    private function runFixture(string $processor, string $mode, string $disabled = ''): array
    {
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        $script = tempnam(sys_get_temp_dir(), 'scan-status-');
        self::assertIsString($script);
        try {
            file_put_contents($script, '<?php require ' . var_export($autoload, true) . ';' . <<<'SCRIPT'
                ini_set('display_errors', '0');
                ini_set('log_errors', '1');
                $processor = new $argv[1]();
                $mode = $argv[2];
                $path = __FILE__ . '.result';
                if ('probe' === $mode) {
                    file_put_contents($path, json_encode([
                        'supported' => $processor->support(),
                        'selected' => new SuperKernel\ScanIsolation\Provider\ScanProcessorProvider()()::class,
                    ]));
                    return;
                }
                if ('unknown' === $mode) { pcntl_signal(SIGCHLD, SIG_IGN); }
                $interruptions = 0;
                $parentPid = getmypid();
                if ('interrupt' === $mode) {
                    pcntl_async_signals(true);
                    pcntl_signal(SIGUSR1, static function () use (&$interruptions): void { ++$interruptions; }, false);
                }
                if ('closed-parent-stream' === $mode
                    && false === getenv('SUPER_KERNEL_SCAN_ISOLATION_PROCESS')
                    && false === getenv('SUPER_KERNEL_SCAN_ISOLATION_EXEC')) {
                    fclose($processor instanceof SuperKernel\ScanIsolation\Processor\ExecScanProcessor ? STDERR : STDOUT);
                }
                $completed = 0;
                $failureCode = null;
                $diagnostic = null;
                $previous = null;
                try {
                    $processor->execute(static function (SuperKernel\ScanIsolation\Contract\ScannedInterface $state) use ($mode, $parentPid, &$completed): void {
                        if ($state->isScanned()) { ++$completed; return; }
                        file_put_contents(__FILE__ . '.child', (string) getmypid());
                        fwrite(STDOUT, "child stdout\n");
                        fwrite(STDERR, "child stderr\n");
                        if ('wrong-ack' === $mode) {
                            $ack = fopen('php://fd/' . SuperKernel\ScanIsolation\Process\ReentryContext::RESULT_DESCRIPTOR, 'wb');
                            ftruncate($ack, 0);
                            fwrite($ack, 'wrong-token');
                            fclose($ack);
                        }
                        if ('closed-parent-stream' === $mode) { exit(17); }
                        if ('interrupt' === $mode) { usleep(20000); posix_kill($parentPid, SIGUSR1); }
                        if ('exit0' === $mode) { exit(0); }
                        if ('exit17' === $mode) { exit(17); }
                        if ('signal' === $mode) { posix_kill(getmypid(), SIGTERM); }
                        if ('handler0' === $mode || 'handler17' === $mode) {
                            set_exception_handler(static function (Throwable $failure) use ($mode): void {
                                exit('handler0' === $mode ? 0 : 17);
                            });
                            throw new DomainException('handled child failure');
                        }
                        if ('throw' === $mode) { throw new DomainException('child failure'); }
                    });
                } catch (SuperKernel\ScanIsolation\Exception\ScanProcessFailedException $failure) {
                    $failureCode = $failure->exitCode();
                    $diagnostic = $failure->processOutput();
                    $previous = $failure->getPrevious();
                }
                file_put_contents($path, json_encode([
                    'processor' => $processor::class, 'pid' => getmypid(),
                    'childPid' => (int) file_get_contents(__FILE__ . '.child'),
                    'completions' => $completed, 'failure' => $failureCode, 'interruptions' => $interruptions,
                    'diagnostic' => $diagnostic, 'previous' => $previous,
                ]));
                SCRIPT);
            $process = proc_open([
                PHP_BINARY,
                '-d',
                'disable_functions=' . $disabled,
                $script,
                $processor,
                $mode,
            ], [
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
                ], $pipes);
            self::assertIsResource($process);
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $stderr . $stdout);
            return json_decode(file_get_contents($script . '.result'), true, flags: JSON_THROW_ON_ERROR) + [
                'stdout' => $stdout,
                'stderr' => $stderr,
                ];
        } finally {
            foreach (glob($script . '*') ?: [] as $file) {
                unlink($file);
            }
        }
    }
}
