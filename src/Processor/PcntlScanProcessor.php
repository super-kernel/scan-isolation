<?php

declare(strict_types=1);

namespace SuperKernel\ScanIsolation\Processor;

use ReflectionException;
use SuperKernel\ScanIsolation\Contract\ScanProcessorInterface;
use SuperKernel\ScanIsolation\Exception\ScanProcessFailedException;
use SuperKernel\ScanIsolation\Execution\Callback;
use SuperKernel\ScanIsolation\Execution\CallbackRunner;
use Throwable;

use function extension_loaded;
use function function_exists;
use function pcntl_fork;
use function pcntl_get_last_error;
use function pcntl_waitpid;
use function pcntl_wexitstatus;
use function pcntl_wifexited;
use function pcntl_wifsignaled;
use function pcntl_wtermsig;
use function sprintf;

use const PCNTL_EINTR;

final class PcntlScanProcessor implements ScanProcessorInterface
{
    /**
     * @throws ReflectionException        When the callback cannot be reflected
     * @throws ScanProcessFailedException When launching, waiting for, or completing the child fails
     * @throws Throwable                  When the callback throws; the original throwable propagates in its process
     */
    public function execute(callable $callback): void
    {
        $callback = new Callback($callback);
        $pid = @pcntl_fork();

        if (-1 === $pid) {
            throw new ScanProcessFailedException(127, 'Unable to fork the isolated scan process.');
        }

        if (0 === $pid) {
            new CallbackRunner($callback)->run();
        }

        $status = 0;

        do {
            $waited = @pcntl_waitpid($pid, $status);
        } while (-1 === $waited && PCNTL_EINTR === pcntl_get_last_error());

        if ($pid !== $waited) {
            throw new ScanProcessFailedException(127, 'Unable to wait for the isolated scan child process.');
        }

        if (!pcntl_wifexited($status)) {
            if (pcntl_wifsignaled($status)) {
                $signal = pcntl_wtermsig($status);
                throw new ScanProcessFailedException(
                    128 + $signal,
                    sprintf('The PCNTL child was terminated by signal %d.', $signal),
                );
            }

            throw new ScanProcessFailedException(127, 'Unable to determine the isolated child exit status.');
        }

        $exitCode = pcntl_wexitstatus($status);

        if (0 !== $exitCode) {
            throw new ScanProcessFailedException($exitCode);
        }

        $callback->run(true);
    }

    public function support(): bool
    {
        return extension_loaded('pcntl')
            && function_exists('pcntl_fork')
            && function_exists('pcntl_get_last_error')
            && function_exists('pcntl_waitpid')
            && function_exists('pcntl_wifexited')
            && function_exists('pcntl_wexitstatus')
            && function_exists('pcntl_wifsignaled')
            && function_exists('pcntl_wtermsig');
    }
}
