<?php

declare(strict_types=1);

namespace SuperKernel\ScanIsolation\Tests\Integration;

use DomainException;
use Phar;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SuperKernel\ScanIsolation\Contract\ScanProcessorInterface;
use SuperKernel\ScanIsolation\Exception\ScanProcessFailedException;
use SuperKernel\ScanIsolation\Processor\ExecScanProcessor;
use SuperKernel\ScanIsolation\Processor\NullScanProcessor;
use SuperKernel\ScanIsolation\Processor\PcntlScanProcessor;
use SuperKernel\ScanIsolation\Processor\ProcessScanProcessor;
use Throwable;

use function class_exists;
use function dirname;
use function fclose;
use function file_put_contents;
use function glob;
use function json_decode;
use function proc_close;
use function proc_open;
use function sprintf;
use function stream_get_contents;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;
use function var_export;

use const E_USER_WARNING;
use const JSON_THROW_ON_ERROR;
use const PHP_BINARY;

final class TwoPhaseExecutionTest extends TestCase
{
    /**
     * @param class-string<ScanProcessorInterface> $processorClass
     *
     * @throws Throwable When fixture setup or the operation under test throws
     */
    #[DataProvider('callbacks')]
    public function testInjectsFalseInTheChildThenTrueInTheCallingProcess(string $processorClass, string $form): void
    {
        $this->requireProcessor($processorClass);
        $result = $this->runScript(sprintf(
            <<<'PHP'
                $processor = new %s();
                $worker = new class(__FILE__ . '.events', %s) {
                    public int $calls = 0;
                    public function __construct(private string $path, private string $fixture) {}
                    public function run(SuperKernel\ScanIsolation\Contract\ScannedInterface $state): void {
                        ++$this->calls;
                        if (!$state->isScanned()) {
                            require $this->fixture;
                        }
                        file_put_contents($this->path, json_encode([
                            'scanned' => $state->isScanned(),
                            'pid' => getmypid(),
                            'calls' => $this->calls,
                        ], JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);
                    }
                    public function __invoke(SuperKernel\ScanIsolation\Contract\ScannedInterface $state): void {
                        $this->run($state);
                    }
                };
                function recordScan(SuperKernel\ScanIsolation\Contract\ScannedInterface $state): void {
                    global $worker;
                    $worker->run($state);
                }
                $callback = match (%s) {
                    'method' => [$worker, 'run'],
                    'function' => 'recordScan',
                    'invokable' => $worker,
                    'nullable' => static function (
                        ?SuperKernel\ScanIsolation\Contract\ScannedInterface $state
                    ) use ($worker): void { $worker->run($state); },
                    'optional' => static function (
                        ?SuperKernel\ScanIsolation\Contract\ScannedInterface $state = null
                    ) use ($worker): void { $worker->run($state); },
                    default => static function (
                        SuperKernel\ScanIsolation\Contract\ScannedInterface $state
                    ) use ($worker): void { $worker->run($state); },
                };
                $processor->execute($callback);
                echo json_encode([
                    'events' => array_map(
                        static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
                        file(__FILE__ . '.events', FILE_IGNORE_NEW_LINES),
                    ),
                    'pid' => getmypid(),
                    'calls' => $worker->calls,
                    'declaredInParent' => class_exists(
                        SuperKernel\ScanIsolation\Tests\Fixture\IsolatedClass::class,
                        false,
                    ),
                ], JSON_THROW_ON_ERROR);
                PHP,
            $processorClass,
            var_export(dirname(__DIR__) . '/Fixture/IsolatedClass.php', true),
            var_export($form, true),
        ));

        self::assertCount(2, $result['events']);
        self::assertFalse($result['events'][0]['scanned']);
        self::assertTrue($result['events'][1]['scanned']);
        self::assertNotSame($result['pid'], $result['events'][0]['pid']);
        self::assertSame($result['pid'], $result['events'][1]['pid']);
        self::assertSame(1, $result['calls']);
        self::assertSame(1, $result['events'][0]['calls']);
        self::assertSame(1, $result['events'][1]['calls']);
        self::assertFalse($result['declaredInParent']);
    }

    /**
     * @param class-string<ScanProcessorInterface> $processorClass
     *
     * @throws Throwable When fixture setup or the operation under test throws
     */
    #[DataProvider('processors')]
    public function testParameterlessObjectMethodExecutesOnlyInIsolation(string $processorClass): void
    {
        $this->requireProcessor($processorClass);
        $result = $this->runScript(sprintf(
            <<<'PHP'
                $worker = new class(__FILE__ . '.events') {
                    public int $calls = 0;
                    public function __construct(private string $path) {}
                    public function run(): void {
                        ++$this->calls;
                        file_put_contents($this->path, getmypid() . "\n", FILE_APPEND | LOCK_EX);
                    }
                };
                new %s()->execute([$worker, 'run']);
                echo json_encode([
                    'calls' => $worker->calls,
                    'events' => file(__FILE__ . '.events', FILE_IGNORE_NEW_LINES),
                    'pid' => (string) getmypid(),
                ], JSON_THROW_ON_ERROR);
                PHP,
            $processorClass,
        ));

        self::assertSame(0, $result['calls']);
        self::assertCount(1, $result['events']);
        self::assertNotSame($result['pid'], $result['events'][0]);
    }

    /**
     * @param class-string<ScanProcessorInterface> $processorClass
     *
     * @throws Throwable When fixture setup or the operation under test throws
     */
    #[DataProvider('failures')]
    public function testExceptionsReachTheOuterCatchInTheirOwnPhase(
        string $processorClass,
        bool   $failAfterScan,
    ): void
    {
        $this->requireProcessor($processorClass);
        $result = $this->runScript(sprintf(
            <<<'PHP'
                $failAfterScan = %s;
                $exception = null;
                $message = null;
                try {
                    new %s()->execute(static function (
                        SuperKernel\ScanIsolation\Contract\ScannedInterface $state
                    ) use ($failAfterScan): void {
                        file_put_contents(__FILE__ . '.events', (int) $state->isScanned() . "\n", FILE_APPEND);
                        if ($state->isScanned() === $failAfterScan) {
                            throw new DomainException('phase failure');
                        }
                    });
                } catch (Throwable $failure) {
                    if (!$failAfterScan && $failure instanceof DomainException) {
                        file_put_contents(__FILE__ . '.caught', $failure::class . ': ' . $failure->getMessage());
                        exit(17);
                    }
                    $exception = $failure::class;
                    $message = $failure->getMessage();
                }
                echo json_encode([
                    'exception' => $exception,
                    'message' => $message,
                    'events' => file(__FILE__ . '.events', FILE_IGNORE_NEW_LINES),
                    'outerFailure' => $failAfterScan ? null : file_get_contents(__FILE__ . '.caught'),
                ], JSON_THROW_ON_ERROR);
                PHP,
            var_export($failAfterScan, true),
            $processorClass,
        ));

        self::assertSame(
            $failAfterScan ? DomainException::class : ScanProcessFailedException::class,
            $result['exception'],
        );
        self::assertSame(
            $failAfterScan ? 'phase failure' : 'The isolated scan callback failed with exit code 17.',
            $result['message'],
        );
        self::assertSame($failAfterScan ? null : DomainException::class . ': phase failure', $result['outerFailure']);
        self::assertSame($failAfterScan ? [
            '0',
            '1',
        ] : [
            '0',
        ], $result['events']);
    }

    /**
     * @param class-string<ScanProcessorInterface> $processorClass
     *
     * @throws Throwable When fixture setup or the operation under test throws
     */
    #[DataProvider('processors')]
    public function testOriginalExceptionReachesOuterCatchAndParentCompletionContinues(
        string $processorClass,
    ): void
    {
        $this->requireProcessor($processorClass);
        $result = $this->runScript(sprintf(
            <<<'PHP'
                $original = new DomainException('allowed termination', 41, new RuntimeException('original cause'));
                try {
                    new %s()->execute(static function (
                        SuperKernel\ScanIsolation\Contract\ScannedInterface $state
                    ) use ($original): void {
                        if (!$state->isScanned()) {
                            throw $original;
                        }
                        file_put_contents(__FILE__ . '.completed', 'completed');
                    });
                } catch (DomainException $failure) {
                    file_put_contents(__FILE__ . '.caught', json_encode([
                        'sameObject' => $original === $failure,
                        'class' => $failure::class,
                        'message' => $failure->getMessage(),
                        'code' => $failure->getCode(),
                        'samePrevious' => $original->getPrevious() === $failure->getPrevious(),
                    ], JSON_THROW_ON_ERROR));
                    exit(0);
                }
                echo json_encode([
                    'completed' => is_file(__FILE__ . '.completed'),
                    'outer' => json_decode(file_get_contents(__FILE__ . '.caught'), true, 512, JSON_THROW_ON_ERROR),
                ], JSON_THROW_ON_ERROR);
                PHP,
            $processorClass,
        ));

        self::assertTrue($result['completed']);
        self::assertTrue($result['outer']['sameObject']);
        self::assertSame(DomainException::class, $result['outer']['class']);
        self::assertSame('allowed termination', $result['outer']['message']);
        self::assertSame(41, $result['outer']['code']);
        self::assertTrue($result['outer']['samePrevious']);
    }

    /**
     * @param class-string<ScanProcessorInterface> $processorClass
     *
     * @throws Throwable When fixture setup or the operation under test throws
     */
    #[DataProvider('processors')]
    public function testIsolatedZeroExitStillPermitsParentCompletion(string $processorClass): void
    {
        $this->requireProcessor($processorClass);
        $result = $this->runScript(sprintf(
            <<<'PHP'
                new %s()->execute(static function (
                    SuperKernel\ScanIsolation\Contract\ScannedInterface $state
                ): void {
                    if (!$state->isScanned()) {
                        exit(0);
                    }
                    file_put_contents(__FILE__ . '.completed', 'completed');
                });
                echo json_encode([
                    'completed' => is_file(__FILE__ . '.completed'),
                ], JSON_THROW_ON_ERROR);
                PHP,
            $processorClass,
        ));

        self::assertTrue($result['completed']);
    }

    /**
     * @param class-string<ScanProcessorInterface> $processorClass
     *
     * @throws Throwable When fixture setup or the operation under test throws
     */
    #[DataProvider('processors')]
    public function testPhpWarningReachesTheOuterHandlerAndBothPhasesContinue(string $processorClass): void
    {
        $this->requireProcessor($processorClass);
        $result = $this->runScript(sprintf(
            <<<'PHP'
                ini_set('display_errors', '0');
                ini_set('log_errors', '1');
                set_error_handler(static function (int $severity, string $message): bool {
                    file_put_contents(__FILE__ . '.warning', json_encode([
                        'severity' => $severity,
                        'message' => $message,
                    ], JSON_THROW_ON_ERROR));
                    return false;
                });
                new %s()->execute(static function (
                    SuperKernel\ScanIsolation\Contract\ScannedInterface $state
                ): void {
                    if (!$state->isScanned()) {
                        trigger_error('isolated warning', E_USER_WARNING);
                    }
                    file_put_contents(__FILE__ . '.events', (int) $state->isScanned() . "\n", FILE_APPEND);
                });
                echo json_encode([
                    'events' => file(__FILE__ . '.events', FILE_IGNORE_NEW_LINES),
                    'warning' => json_decode(file_get_contents(__FILE__ . '.warning'), true, 512, JSON_THROW_ON_ERROR),
                ], JSON_THROW_ON_ERROR);
                PHP,
            $processorClass,
        ), expectedError: 'isolated warning');

        self::assertSame([
            '0',
            '1',
        ], $result['events']);
        self::assertSame(E_USER_WARNING, $result['warning']['severity']);
        self::assertSame('isolated warning', $result['warning']['message']);
    }

    /**
     * @param class-string<ScanProcessorInterface> $processorClass
     *
     * @throws Throwable When fixture setup or the operation under test throws
     */
    #[DataProvider('processors')]
    public function testUncaughtIsolatedExceptionKeepsPhpDiagnosticsAndRejectsCompletion(
        string $processorClass,
    ): void
    {
        $this->requireProcessor($processorClass);
        $result = $this->runScript(sprintf(
            <<<'PHP'
                ini_set('display_errors', '0');
                ini_set('log_errors', '1');
                $completed = false;
                $failureCode = null;
                try {
                    new %s()->execute(static function (
                        SuperKernel\ScanIsolation\Contract\ScannedInterface $state
                    ) use (&$completed): void {
                        if (!$state->isScanned()) {
                            throw new DomainException('unhandled isolated failure');
                        }
                        $completed = true;
                    });
                } catch (SuperKernel\ScanIsolation\Exception\ScanProcessFailedException $failure) {
                    $failureCode = $failure->exitCode();
                }
                echo json_encode([
                    'failureCode' => $failureCode,
                    'completed' => $completed,
                ], JSON_THROW_ON_ERROR);
                PHP,
            $processorClass,
        ), expectedError: 'Uncaught DomainException: unhandled isolated failure');

        self::assertFalse($result['completed']);
        self::assertGreaterThan(0, $result['failureCode']);
    }

    /**
     * @param class-string<ScanProcessorInterface> $processorClass
     *
     * @throws Throwable When fixture setup or the operation under test throws
     */
    #[DataProvider('reentryProcessors')]
    public function testReentryMustReachTheRequestedCallback(string $processorClass, string $variable): void
    {
        $this->requireProcessor($processorClass);
        $result = $this->runScript(sprintf(
            <<<'PHP'
                if (false !== getenv(%s)) {
                    exit(0);
                }
                $completed = false;
                try {
                    new %s()->execute(static function (
                        SuperKernel\ScanIsolation\Contract\ScannedInterface $state
                    ) use (&$completed): void {
                        $completed = $state->isScanned();
                    });
                } catch (SuperKernel\ScanIsolation\Exception\ScanProcessFailedException $failure) {
                    echo json_encode([
                        'exception' => $failure::class,
                        'diagnostic' => $failure->processOutput(),
                        'completed' => $completed,
                    ], JSON_THROW_ON_ERROR);
                }
                PHP,
            var_export($variable, true),
            $processorClass,
        ));

        self::assertSame(ScanProcessFailedException::class, $result['exception']);
        self::assertStringContainsString('did not execute the callback', $result['diagnostic']);
        self::assertFalse($result['completed']);
    }

    /**
     * @param class-string<ScanProcessorInterface> $processorClass
     *
     * @throws Throwable When fixture setup or the operation under test throws
     */
    #[DataProvider('processors')]
    public function testRepeatedCallbacksRestoreEarlierStateBeforeTheTargetScan(string $processorClass): void
    {
        $this->requireProcessor($processorClass);
        $result = $this->runScript(sprintf(
            <<<'PHP'
                $processor = new %s();
                $restored = [];
                for ($index = 0; $index < 2; ++$index) {
                    $processor->execute(static function (
                        SuperKernel\ScanIsolation\Contract\ScannedInterface $state
                    ) use ($index, &$restored): void {
                        if ($state->isScanned()) {
                            $restored[$index] = true;
                            return;
                        }
                        if ($index === 1 && !isset($restored[0])) {
                            throw new RuntimeException('Earlier completion was not restored.');
                        }
                        file_put_contents(__FILE__ . '.events', $index . "\n", FILE_APPEND);
                    });
                }
                echo json_encode([
                    'restored' => array_keys($restored),
                    'events' => file(__FILE__ . '.events', FILE_IGNORE_NEW_LINES),
                ], JSON_THROW_ON_ERROR);
                PHP,
            $processorClass,
        ));

        self::assertSame([
            0,
            1,
        ], $result['restored']);
        self::assertSame([
            '0',
            '1',
        ], $result['events']);
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testPharAutomaticallyUsesOnlyTheNullCompletionPhase(): void
    {
        if (!class_exists(Phar::class, false)) {
            self::markTestSkipped('The Phar extension is unavailable.');
        }

        $result = $this->runScript(sprintf(<<<'PHP'
            $path = __FILE__ . '.phar';
            $archive = new Phar($path);
            $archive->buildFromDirectory(%s, '/\.php$/');
            $archive['probe.php'] = <<<'PROBE'
                <?php
                spl_autoload_register(static function (string $class): void {
                    $prefix = 'SuperKernel\\ScanIsolation\\';
                    if (str_starts_with($class, $prefix)) {
                        require __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                    }
                }, true, true);
                $processor = new SuperKernel\ScanIsolation\Provider\ScanProcessorProvider()();
                $events = [];
                $processor->execute(static function (
                    SuperKernel\ScanIsolation\Contract\ScannedInterface $state
                ) use (&$events): void {
                    $events[] = ['scanned' => $state->isScanned(), 'pid' => getmypid()];
                });
                $processor->execute(static function (): void {
                    throw new RuntimeException('The isolated phase must be skipped.');
                });
                echo json_encode([
                    'processor' => $processor::class,
                    'supported' => $processor->support(),
                    'events' => $events,
                    'pid' => getmypid(),
                ], JSON_THROW_ON_ERROR);
                PROBE;
            $archive->setStub(Phar::createDefaultStub('probe.php'));
            unset($archive);
            require $path;
            PHP, var_export(dirname(__DIR__, 2) . '/src', true)));

        self::assertSame(NullScanProcessor::class, $result['processor']);
        self::assertTrue($result['supported']);
        self::assertCount(1, $result['events']);
        self::assertTrue($result['events'][0]['scanned']);
        self::assertSame($result['pid'], $result['events'][0]['pid']);
    }

    /** @return iterable<string, array{class-string<ScanProcessorInterface>}> */
    public static function processors(): iterable
    {
        foreach ([
            PcntlScanProcessor::class,
            ProcessScanProcessor::class,
            ExecScanProcessor::class,
        ] as $processorClass) {
            yield $processorClass => [
                $processorClass,
            ];
        }
    }

    /** @return iterable<string, array{class-string<ScanProcessorInterface>, string}> */
    public static function callbacks(): iterable
    {
        foreach (self::processors() as [$processorClass]) {
            foreach ([
                'closure',
                'method',
                'function',
                'invokable',
                'nullable',
                'optional',
            ] as $form) {
                yield $processorClass . '-' . $form => [
                    $processorClass,
                    $form,
                ];
            }
        }
    }

    /** @return iterable<string, array{class-string<ScanProcessorInterface>, bool}> */
    public static function failures(): iterable
    {
        foreach (self::processors() as [$processorClass]) {
            yield $processorClass . '-isolated' => [
                $processorClass,
                false,
            ];
            yield $processorClass . '-completion' => [
                $processorClass,
                true,
            ];
        }
    }

    /** @return iterable<string, array{class-string<ScanProcessorInterface>, non-empty-string}> */
    public static function reentryProcessors(): iterable
    {
        yield 'process' => [
            ProcessScanProcessor::class,
            'SUPER_KERNEL_SCAN_ISOLATION_PROCESS',
        ];
        yield 'exec' => [
            ExecScanProcessor::class,
            'SUPER_KERNEL_SCAN_ISOLATION_EXEC',
        ];
    }

    /** @param class-string<ScanProcessorInterface> $processorClass */
    private function requireProcessor(string $processorClass): void
    {
        if (!new $processorClass()->support()) {
            self::markTestSkipped($processorClass . ' is unavailable.');
        }
    }

    /**
     * @return array<string, mixed>
     *
     * @throws Throwable When fixture setup or a supplied test callback fails
     */
    private function runScript(string $body, ?string $expectedError = null): array
    {
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';

        self::assertFileExists($autoload);
        $script = tempnam(sys_get_temp_dir(), 'super-kernel-execute-test-');
        self::assertIsString($script);

        try {
            self::assertNotFalse(file_put_contents(
                $script,
                "<?php\ndeclare(strict_types=1);\nrequire " . var_export($autoload, true) . ";\n" . $body,
            ));
            $process = proc_open(
                [
                    PHP_BINARY,
                    '-d',
                    'phar.readonly=0',
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

            if (null !== $expectedError) {
                self::assertStringContainsString($expectedError, $error);
            }

            return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        } finally {
            foreach (glob($script . '*') ?: [] as $path) {
                unlink($path);
            }
        }
    }
}
