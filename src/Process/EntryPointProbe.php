<?php

declare(strict_types=1);

namespace SuperKernel\ScanIsolation\Process;

use function array_all;
use function array_is_list;
use function is_array;
use function is_file;
use function is_readable;
use function is_string;

use const PHP_SAPI;

/**
 * Checks whether the current process exposes a readable CLI entry script and string argv values for reentry.
 *
 * @internal
 */
final class EntryPointProbe
{
    public static function isAvailable(): bool
    {
        if ('cli' !== PHP_SAPI) {
            return false;
        }

        $script = $_SERVER['SCRIPT_FILENAME'] ?? null;

        if (!is_string($script) || '' === $script || !is_file($script) || !is_readable($script)) {
            return false;
        }

        $arguments = $_SERVER['argv'] ?? [];

        if (!is_array($arguments) || !array_is_list($arguments)) {
            return false;
        }

        return array_all($arguments, fn($argument) => is_string($argument));
    }
}
