<?php

declare(strict_types=1);

namespace SuperKernel\ScanIsolation\Execution;

use Closure;
use ReflectionException;
use ReflectionFunction;
use ReflectionNamedType;
use SuperKernel\ScanIsolation\Contract\ScannedInterface;
use SuperKernel\ScanIsolation\Exception\InvalidScanCallbackException;

use function count;

/**
 * Validates injection before process creation and preserves the original callable's reentry identity.
 *
 * @internal
 */
final readonly class Callback
{
    /** Original callback accepting no parameters or one ScannedInterface parameter. */
    public Closure $closure;

    private bool $acceptsScanned;

    /**
     * @throws ReflectionException          When the callback cannot be reflected
     * @throws InvalidScanCallbackException When the callback cannot receive the scan state
     */
    public function __construct(callable $callback)
    {
        $this->closure = $callback(...);
        $parameters = new ReflectionFunction($this->closure)->getParameters();
        $this->acceptsScanned = [] !== $parameters;

        if (!$this->acceptsScanned) {
            return;
        }

        $parameter = $parameters[0];
        $type = $parameter->getType();

        if (
            1 !== count($parameters)
            || $parameter->isPassedByReference()
            || $parameter->isVariadic()
            || !$type instanceof ReflectionNamedType
            || ScannedInterface::class !== $type->getName()
        ) {
            throw new InvalidScanCallbackException(
                'A scan callback must declare no parameters or exactly one non-reference, non-variadic '
                . ScannedInterface::class . ' parameter.',
            );
        }
    }

    public function run(bool $scanned): void
    {
        if ($this->acceptsScanned) {
            ($this->closure)(new Scanned($scanned));
        } elseif (!$scanned) {
            ($this->closure)();
        }
    }
}
