<?php

declare(strict_types=1);

namespace SuperKernel\ScanIsolation\Process;

use Closure;
use Random\RandomException;
use ReflectionException;
use ReflectionFunction;
use RuntimeException;
use SuperKernel\ScanIsolation\Exception\ScanProcessFailedException;
use SuperKernel\ScanIsolation\Execution\Callback;
use SuperKernel\ScanIsolation\Execution\CallbackRunner;
use Throwable;

use function bin2hex;
use function fflush;
use function fopen;
use function fwrite;
use function getenv;
use function hash;
use function is_resource;
use function is_string;
use function preg_match;
use function putenv;
use function random_bytes;
use function serialize;
use function strlen;
use function substr;

/**
 * @internal
 */
final readonly class ReentryContext
{
    public const int RESULT_DESCRIPTOR = 6;

    /** @param resource $resultStream */
    private function __construct(private string $token, private mixed $resultStream)
    {
    }

    /**
     * @return non-empty-string
     *
     * @throws RandomException
     */
    public static function token(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Identifies the callback's source location and occurrence within this processor strategy.
     *
     * @return non-empty-string
     *
     * @throws ReflectionException When the callback cannot be reflected
     */
    public static function callbackId(string $variable, Closure $callback): string
    {
        /** @var array<string, int> $occurrences */
        static $occurrences = [];
        $reflection = new ReflectionFunction($callback);
        $location = serialize([
            $variable,
            $reflection->getFileName(),
            $reflection->getStartLine(),
            $reflection->getEndLine(),
        ]);
        $occurrences[$location] = ($occurrences[$location] ?? 0) + 1;

        return hash('sha256', $location . ':' . $occurrences[$location]);
    }

    /**
     * @return self|false|null Matching child context, false for an earlier callback, or null in the parent
     *
     * @throws ScanProcessFailedException When the reentry envelope or result descriptor is invalid
     */
    public static function fromEnvironment(string $variable, string $callbackId): self|false|null
    {
        $token = getenv($variable);

        if (false === $token) {
            return null;
        }

        if (!is_string($token) || 1 !== preg_match('/\A([a-f0-9]{32}):([a-f0-9]{64})\z/', $token, $matches)) {
            throw new ScanProcessFailedException(127, 'The callback reentry token is invalid.');
        }

        if ($callbackId !== $matches[2]) {
            return false;
        }

        if (!putenv($variable)) {
            throw new ScanProcessFailedException(127, 'Unable to clear the callback reentry token.');
        }

        unset($_ENV[$variable], $_SERVER[$variable]);

        $resultStream = @fopen('php://fd/' . self::RESULT_DESCRIPTOR, 'wb');
        if (!is_resource($resultStream)) {
            throw new ScanProcessFailedException(127, 'The callback reentry result descriptor is unavailable.');
        }

        return new self($matches[1], $resultStream);
    }

    /**
     * @throws RuntimeException When writing or flushing the reentry result fails
     * @throws Throwable        When the callback throws; the original throwable propagates
     */
    public function run(Callback $callback): never
    {
        self::write($this->resultStream, $this->token . "\n");
        new CallbackRunner($callback)->run();
    }

    /** @param resource $stream */
    private static function write(mixed $stream, string $contents): void
    {
        $length = strlen($contents);
        $offset = 0;

        while ($offset < $length) {
            $written = @fwrite($stream, substr($contents, $offset));

            if (false === $written || 0 === $written) {
                throw new RuntimeException('Unable to write the callback reentry result.');
            }

            $offset += $written;
        }

        if (!@fflush($stream)) {
            throw new RuntimeException('Unable to flush the callback reentry result.');
        }
    }
}
