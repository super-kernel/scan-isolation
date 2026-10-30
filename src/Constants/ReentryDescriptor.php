<?php

declare(strict_types=1);

namespace SuperKernel\ScanIsolation\Constants;

/**
 * Numbers the file descriptors that Process and Exec reentry open between the parent and its reentered child.
 *
 * @internal
 */
enum ReentryDescriptor: int
{
    /** Carries the matching callback's result-token acknowledgement from the child to the parent. */
    case Result = 6;
}
