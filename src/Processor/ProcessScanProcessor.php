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

use function fclose;
use function function_exists;
use function fwrite;
use function getenv;
use function implode;
use function is_array;
use function is_resource;
use function proc_close;
use function proc_open;
use function rewind;
use function stream_get_contents;
use function tmpfile;
use function trim;

use const DIRECTORY_SEPARATOR;
use const STDERR;
use const STDOUT;

final class ProcessScanProcessor implements ScanProcessorInterface
{
    private const string REENTRY_VARIABLE = 'SUPER_KERNEL_SCAN_ISOLATION_PROCESS';

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

        $entryPoint = EntryPoint::current();
        $token = ReentryContext::token();
        $environment = getenv();

        if (!is_array($environment)) {
            throw new ScanProcessFailedException(127, 'Unable to read the callback process environment.');
        }

        $environment[self::REENTRY_VARIABLE] = $token . ':' . $callbackId;
        $standardOutput = tmpfile();
        $standardError = tmpfile();
        $result = tmpfile();

        if (
            !is_resource($standardOutput)
            || !is_resource($standardError)
            || !is_resource($result)
        ) {
            foreach ([
                $standardOutput,
                $standardError,
                $result,
            ] as $stream) {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            throw new ScanProcessFailedException(127, 'Unable to allocate callback process streams.');
        }

        $process = null;
        $pipes = [];
        $failure = null;

        try {
            $process = @proc_open(
                $entryPoint->command(),
                [
                    0 => [
                        'pipe',
                        'r',
                    ],
                    1                                => $standardOutput,
                    2                                => $standardError,
                    ReentryDescriptor::Result->value => $result,
                ],
                $pipes,
                env_vars: $environment,
                options: [
                    'bypass_shell' => true,
                ],
            );

            if (!is_resource($process)) {
                throw new ScanProcessFailedException(127, 'Unable to start the callback process.');
            }

            if (isset($pipes[0]) && is_resource($pipes[0])) {
                fclose($pipes[0]);
                unset($pipes[0]);
            }

            $exitCode = proc_close($process);
            $process = null;

            if (0 !== $exitCode) {
                $failure = new ScanProcessFailedException(
                    0 < $exitCode ? $exitCode : 127,
                    0 < $exitCode ? '' : 'Unable to determine the callback process exit status.',
                );
            }

            rewind($standardOutput);
            $output = (string) stream_get_contents($standardOutput);
            rewind($standardError);
            $error = (string) stream_get_contents($standardError);
            rewind($result);
            $resultToken = trim((string) stream_get_contents($result));

            if (null === $failure && $token !== $resultToken) {
                throw new ScanProcessFailedException(
                    127,
                    trim(implode("\n", [
                        'The reentered application did not execute the callback.',
                        $output,
                        $error,
                    ])),
                );
            }

            if ('' !== $output) {
                fwrite(STDOUT, $output);
            }

            if ('' !== $error) {
                fwrite(STDERR, $error);
            }

            if (null !== $failure) {
                throw $failure;
            }
        } catch (Throwable $exception) {
            $failure ??= $exception;

            throw $failure;
        } finally {
            try {
                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }

                if (is_resource($process)) {
                    proc_close($process);
                }

                foreach ([
                    $standardOutput,
                    $standardError,
                    $result,
                ] as $stream) {
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                }
            } catch (Throwable $cleanupFailure) {
                if (null === $failure) {
                    throw $cleanupFailure;
                }
            }
        }

        $callback->run(true);
    }

    public function support(): bool
    {
        return '/' === DIRECTORY_SEPARATOR
            && EntryPoint::isAvailable()
            && function_exists('getenv')
            && function_exists('proc_close')
            && function_exists('proc_open')
            && function_exists('putenv');
    }
}
