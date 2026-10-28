# SuperKernel Scan Isolation

`super-kernel/scan-isolation` executes caller-supplied work in isolation and optionally runs a completion phase in the
calling PHP process. It accepts PHP callables, including Closures, functions, invokable objects, and
`[$object, 'method']` callbacks. It owns execution and callback-parameter validation; callers own discovery, metadata,
and result storage.

## Source organization

Paths below are relative to `src/` and the package namespace.

| Directory | Responsibility |
| --- | --- |
| `Execution` | `Callback`, `CallbackRunner`, and `Scanned`: callback validation, invocation, and phase state. |
| `Process` | `EntryPoint` and `ReentryContext`: CLI command reconstruction and child acknowledgement. |
| `Processor` | PCNTL, Process, Exec, Null, and phase-delegating execution strategies. |
| `Provider / Contract` | Processor selection and the public execution/phase contracts. |

Implementation helpers retain an `@internal` boundary; a responsibility namespace does not make them extension points.

### Public namespaces

Use the following component declarations:

- `Execution\Callback`
- `Execution\CallbackRunner`
- `Execution\Scanned`
- `Process\EntryPoint`
- `Process\ReentryContext`

## Processors

The package owns `SuperKernel\ScanIsolation\Contract\ScanProcessorInterface` and provides four implementations:

- `PcntlScanProcessor` forks and invokes the supplied `Closure` directly in the child;
- `ProcessScanProcessor` starts the current PHP entry script through shell-free `proc_open()` reentry;
- `ExecScanProcessor` starts the current PHP entry script through an escaped POSIX shell command;
- `NullScanProcessor` skips isolated execution and runs only a parameterized completion callback, for restoring an
  already-created scan result. A parameterless callback is skipped entirely.

`ScanProcessorProvider` checks Null, PCNTL, Process, then Exec through the same `support()` loop and returns the first
supported processor. `NullScanProcessor::support()` owns the Phar check and returns true only inside a Phar archive.
Outside Phar, the provider selects an isolating processor or throws `ScanProcessorUnavailableException` if none is
available.

`PhaseScanProcessor` is an explicit delegate for nested work inside an `execute()` callback. Construct it with that
callback's `ScannedInterface`: false runs nested work in the existing isolated process, while true skips parameterless
scan callbacks and passes true to stateful callbacks. It never starts another child and is outside automatic strategy
selection. Its support probe accepts the supplied enclosing phase; callers establish isolation before constructing it.

The factory is also marked with
`#[SuperKernel\Attribute\FactoryProvider(SuperKernel\ScanIsolation\Contract\ScanProcessorInterface::class)]`.
The factory exposes only `__invoke()` for selection. A caller may also construct a concrete processor directly.
Construction does not reject a missing optional capability. Call `support()` and receive `true` before calling
`execute()`; processors do not repeat that capability probe.

## Callback execution

`ScanProcessorInterface::execute(callable $callback): void` replaces `scan()`:

- A callback declaring no parameters executes once in the isolated process.
- A callback declaring one `SuperKernel\ScanIsolation\Contract\ScannedInterface` parameter executes twice: first with
  `isScanned() === false` in isolation, then with `isScanned() === true` in the calling process after a successful child exit.
- `ScannedInterface` exposes only `isScanned(): bool`. Each supplied state is immutable.
- Parameter inspection happens before any execution or child launch. Untyped parameters, other types, unions,
  intersections, extra parameters, references, and variadics raise `InvalidScanCallbackException`, a
  `RuntimeException`. A nullable or optional `ScannedInterface` parameter is accepted and receives a non-null instance.
- Null skips the first phase; it runs the parameterized callback once with `isScanned() === true` in the current
  process. Callers are responsible for ensuring their persisted scan result exists.

```php
use SuperKernel\ScanIsolation\Contract\ScannedInterface;
use SuperKernel\ScanIsolation\Provider\ScanProcessorProvider;

$processor = new ScanProcessorProvider()();
$processor->execute(
    static function () use ($scanner): void {
        $scanner->scan();
    },
);

$processor->execute(
    static function (ScannedInterface $state) use ($scan, $restore): void {
        if (!$state->isScanned()) {
            $scan();

            return;
        }

        $restore();
    },
);

// The method must declare the same zero-parameter or single ScannedInterface signature.
$processor->execute([$worker, 'run']);
```

In the two-phase example, `$scan` and `$restore` are caller-provided callbacks that publish and restore scan results.

`execute()` waits for the isolated process and only permits completion after a successful child status. PCNTL requires
normal termination and exit code 0; Process requires `proc_close()` to return 0; Exec requires `exec()` to report 0.
Process and Exec also require their existing matching callback acknowledgement. Nonzero, signaled, or unknown status
raises `ScanProcessFailedException` in the parent before any true phase, even if an old result remains readable.
PCNTL reports signals as 128 + signal; Process/Exec retain their native positive status (signal encodings may differ).
Unknown status uses 127. `exitCode()` describes the child, not a guaranteed exit code for the entire application.

The callback runner invokes the original callback directly and exits 0 after normal return. Callback exceptions remain
unchanged in their own process; PHP and outer handlers decide termination. The parent failure is a separate process
status exception, without a fabricated previous Throwable. PCNTL inherits output; Process forwards stdout and stderr
separately; Exec forwards merged output to stderr. Already forwarded diagnostics are not repeated in the new status
exception. Output/cleanup failures cannot replace an established child failure or permit completion.

This is a limited guarantee: callback `exit(0)`, an outer handler's `exit(0)`, or a swallowed error followed by normal
return still allows completion. The acknowledgement proves the target was reached, not that its callback returned.
Null has no child status and retains restore-only behavior; Phase delegates to its enclosing phase. Current-process
callback failures still propagate unchanged. Scan Isolation owns neither cache validation nor cache invalidation.

### Completion requires successful termination

A child callback that exits with a nonzero status prevents the completion phase. For example, `exit(17)` causes
`ScanProcessFailedException` in the parent; no restoration callback runs. Callback or exception-handler `exit(0)`
still passes this check. Applications must validate published results independently.

PCNTL invokes the original Closure from the forked memory snapshot. Process and Exec cannot transfer that Closure
object. Instead, they rerun the current `$_SERVER['SCRIPT_FILENAME']` with the current argument list; the child must
naturally reach the corresponding `execute()` call and recreate its callable. The reentry envelope binds a private
result token to the original callable's source location and occurrence within that processor strategy. Earlier calls
skip their isolated phase and run only their parameterized completion phase to restore already published results. The
matching callback alone consumes the token and acknowledges reaching its isolated phase through a dedicated result
descriptor before invoking the callback. Repeated calls from the same source location are distinguished by occurrence,
so a later scan cannot be acknowledged by an earlier one.
Process preserves stdout and stderr separately; Exec forwards its merged output to stderr, including PHP diagnostics.

Isolated mutations never flow back to parent memory, so results must be published through external storage or explicit
IPC. Completion runs against the original parent-side callback and may update its objects or captured state.
Callbacks should be deterministic from entry-bootstrap dependencies and return normally on success; throwing is an
allowed termination path. Calling `exit()` inside a callback ends that isolated process according to PHP semantics.
Run isolated scans during synchronous bootstrap. PCNTL should run before starting a long-lived server or opening resources whose
inherited child-side shutdown behavior would be unsafe; reentry strategies require the entry script to reach the same
scan point with the same callback sequence and without unrelated side effects. Earlier scan consumers must obtain
their results from persistent storage or IPC when reentry skips their callbacks.

Components that consume `ScanProcessorInterface` must declare `super-kernel/scan-isolation` as a direct production
dependency. The interface exposes `support(): bool` and `execute(callable $callback): void`; the caller owns the support
probe and supplies the scan callback. The package depends on Attribute for provider metadata and does not require
Contract.

## Development

Use a PHP 8.4 Composer in this package's checkout:

```bash
composer install
composer validate --strict
composer test
composer format:check
composer format
```
