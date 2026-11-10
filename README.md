<p align="right">
  <strong>English</strong> | <a href="README.zh-CN.md">中文文档</a>
</p>

# SuperKernel Scan Isolation

> [!WARNING]
> This package is deprecated and no longer maintained. Since SuperKernel 1.0.0-alpha.2, SKERNEL collects an application's classes and attributes in a process of its own when it builds the project, and an application no longer scans at start, so applications no longer install this package.

`super-kernel/scan-isolation` executes caller-supplied work in isolation and optionally runs a completion phase in the calling PHP process. It accepts PHP callables, including Closures, functions, invokable objects, and `[$object, 'method']` callbacks. It owns execution and callback-parameter validation; callers own discovery, metadata, and result storage.

## Processors

`SuperKernel\ScanIsolation\Contract\ScanProcessorInterface` exposes `support(): bool` and `execute(callable $callback): void`. The package provides four implementations:

- `PcntlScanProcessor` forks and invokes the supplied callback directly in the child;
- `ProcessScanProcessor` starts the current PHP entry script through shell-free `proc_open()` reentry;
- `ExecScanProcessor` starts the current PHP entry script through an escaped POSIX shell command;
- `NullScanProcessor` skips isolated execution and runs only a parameterized completion callback, for restoring an already-created scan result. A parameterless callback is skipped entirely.

`ScanProcessorProvider` supplies `ScanProcessorInterface` to the container and can also be invoked directly. It checks Null, PCNTL, Process, then Exec and returns the first supported processor. Null is supported only inside a Phar archive. Outside Phar, the provider selects an isolating processor or throws `ScanProcessorUnavailableException` if none is available.

A caller may also construct a concrete processor directly. Construction does not reject a missing optional capability: call `support()` and receive `true` before calling `execute()`.

`PhaseScanProcessor` is an explicit delegate for nested work inside an `execute()` callback. Construct it with that callback's `ScannedInterface`: false runs nested work in the existing isolated process, while true skips parameterless scan callbacks and passes true to stateful callbacks. It never starts another child and is not part of automatic selection. Its support probe accepts the supplied enclosing phase; callers establish isolation before constructing it.

A component that consumes `ScanProcessorInterface` declares `super-kernel/scan-isolation` as a direct production dependency.

## Callback execution

- A callback declaring no parameters executes once in the isolated process.
- A callback declaring one `SuperKernel\ScanIsolation\Contract\ScannedInterface` parameter executes twice: first with `isScanned() === false` in isolation, then with `isScanned() === true` in the calling process after a successful child exit.
- `ScannedInterface` exposes only `isScanned(): bool`. Each supplied state is immutable.
- Parameter inspection happens before any execution or child launch. Untyped parameters, other types, unions, intersections, extra parameters, references, and variadics raise `InvalidScanCallbackException`, a `RuntimeException`. A nullable or optional `ScannedInterface` parameter is accepted and receives a non-null instance.
- Null skips the first phase; it runs the parameterized callback once with `isScanned() === true` in the current process. Callers are responsible for ensuring their persisted scan result exists.

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

### Completion requires successful termination

`execute()` waits for the isolated process and only permits completion after a successful child status. PCNTL requires normal termination and exit code 0; Process requires `proc_close()` to return 0; Exec requires `exec()` to report 0. Process and Exec also require the child to acknowledge that it reached the matching callback. Nonzero, signaled, or unknown status raises `ScanProcessFailedException` in the parent before any true phase, even if an old result remains readable. PCNTL reports signals as 128 + signal; Process and Exec retain their native positive status, whose signal encodings may differ. Unknown status uses 127. `exitCode()` describes the child, not a guaranteed exit code for the entire application.

For example, a child callback that calls `exit(17)` causes `ScanProcessFailedException` in the parent, and no restoration callback runs.

The isolated process invokes the callback and exits 0 after it returns normally. Callback exceptions remain unchanged in their own process; PHP and outer handlers decide termination. The parent failure is a separate process status exception, without a fabricated previous Throwable. PCNTL inherits output; Process forwards stdout and stderr separately; Exec forwards merged output to stderr, including PHP diagnostics. Already forwarded diagnostics are not repeated in the status exception. Output or cleanup failures cannot replace an established child failure or permit completion.

This is a limited guarantee: callback `exit(0)`, an outer handler's `exit(0)`, or a swallowed error followed by normal return still allows completion. The acknowledgement proves the callback was reached, not that it returned. Applications must validate published results independently. Null has no child status and keeps its restore-only behavior; Phase delegates to its enclosing phase. Current-process callback failures propagate unchanged. Scan Isolation validates and invalidates no caches.

## Reentry and process boundaries

PCNTL invokes the original callback from the forked memory snapshot. Process and Exec cannot transfer a callback object. Instead, they rerun the current `$_SERVER['SCRIPT_FILENAME']` with the current argument list; the child must naturally reach the corresponding `execute()` call and recreate its callable. The child matches that call by the callback's source location and its occurrence within the processor strategy, so repeated calls from the same location stay distinct. Earlier `execute()` calls on the child's way there skip their isolated phase and run only their parameterized completion phase to restore already published results.

Isolated mutations never flow back to parent memory, so results must be published through external storage or explicit IPC. Completion runs against the original parent-side callback and may update its objects or captured state. Callbacks should be deterministic from entry-bootstrap dependencies and return normally on success; throwing is an allowed termination path. Calling `exit()` inside a callback ends that isolated process according to PHP semantics.

Run isolated scans during synchronous bootstrap. Run PCNTL before starting a long-lived server or opening resources whose inherited child-side shutdown behavior would be unsafe. Reentry strategies require the entry script to reach the same scan point with the same callback sequence and without unrelated side effects. Earlier scan consumers must obtain their results from persistent storage or IPC when reentry skips their callbacks.
