<?php

declare(strict_types=1);

namespace SuperKernel\ScanIsolation\Processor;

use Random\RandomException;
use ReflectionException;
use SuperKernel\ScanIsolation\Constants\ReentryDescriptor;
use SuperKernel\ScanIsolation\Contract\ScanProcessorInterface;
use SuperKernel\ScanIsolation\Exception\ScanProcessFailedException;
use SuperKernel\ScanIsolation\Execution\Callback;
use SuperKernel\ScanIsolation\Process\EntryPoint;
use SuperKernel\ScanIsolation\Process\ReentryContext;
use Throwable;

use function array_map;
use function escapeshellarg;
use function exec;
use function file_get_contents;
use function function_exists;
use function fwrite;
use function implode;
use function sys_get_temp_dir;
use function tempnam;
use function trim;
use function unlink;

use const DIRECTORY_SEPARATOR;
use const PHP_EOL;
use const STDERR;

final class ExecScanProcessor implements ScanProcessorInterface
{
    private const string REENTRY_VARIABLE = 'SUPER_KERNEL_SCAN_ISOLATION_EXEC';

    public function support(): bool
    {
        return '/' === DIRECTORY_SEPARATOR
            && EntryPoint::isAvailable()
            && function_exists('exec')
            && function_exists('getenv')
            && function_exists('putenv');
    }

    /**
     * @throws RandomException            When generating the reentry token fails
     * @throws ReflectionException        When the callback cannot be reflected
     * @throws ScanProcessFailedException When process setup, child status, or reentry validation fails
     * @throws Throwable                  When the callback throws or acknowledging the reentry result fails
     */
    public function execute(callable $callback): void
    {
        $callback = new Callback($callback);
        $callbackId = ReentryContext::callbackId(self::REENTRY_VARIABLE, $callback->closure);
        $context = ReentryContext::fromEnvironment(self::REENTRY_VARIABLE, $callbackId);

        if ($context instanceof ReentryContext) {
            $context->run($callback);
        }

        if (false === $context) {
            $callback->run(true);

            return;
        }

        $resultPath = tempnam(sys_get_temp_dir(), 'super-kernel-scan-exec-result-');

        if (false === $resultPath) {
            throw new ScanProcessFailedException(127, 'Unable to allocate the exec callback result file.');
        }

        $failure = null;

        try {
            $token = ReentryContext::token();
            $tokens = array_map(escapeshellarg(...), EntryPoint::current()->command());
            $command = self::REENTRY_VARIABLE . '=' . escapeshellarg($token . ':' . $callbackId)
                . ' ' . implode(' ', $tokens)
                . ' ' . ReentryDescriptor::Result->value . '>' . escapeshellarg($resultPath)
                . ' 2>&1';
            $output = [];
            $exitCode = -1;
            @exec($command, $output, $exitCode);

            if (0 !== $exitCode) {
                $failure = new ScanProcessFailedException(
                    0 < $exitCode ? $exitCode : 127,
                    0 < $exitCode ? '' : 'Unable to determine the exec callback exit status.',
                );
            }

            $processOutput = implode(PHP_EOL, $output);

            if (null === $failure && $token !== trim((string) file_get_contents($resultPath))) {
                throw new ScanProcessFailedException(
                    127,
                    trim(implode("\n", [
                        'The reentered application did not execute the callback.',
                        $processOutput,
                    ])),
                );
            }

            if ('' !== $processOutput) {
                fwrite(STDERR, $processOutput . PHP_EOL);
            }

            if (null !== $failure) {
                throw $failure;
            }
        } catch (Throwable $exception) {
            $failure ??= $exception;

            throw $failure;
        } finally {
            try {
                @unlink($resultPath);
            } catch (Throwable $cleanupFailure) {
                if (null === $failure) {
                    throw $cleanupFailure;
                }
            }
        }

        $callback->run(true);
    }
}
