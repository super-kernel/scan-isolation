<p align="right">
  <a href="README.md">English</a> | <strong>中文文档</strong>
</p>

# SuperKernel Scan Isolation

> [!WARNING]
> 此包已废弃，不再维护。自 SuperKernel 1.0.0-alpha.2 起，SKERNEL 在构建项目时用独立的进程收集应用的类和注解，应用启动时不再扫描，因此不再安装此包。

`super-kernel/scan-isolation` 在隔离环境中执行调用方提供的工作，并可选地在调用方 PHP 进程中运行一个完成阶段。它接受 PHP 可调用值，包括 Closure、函数、可调用对象和 `[$object, 'method']` 回调。它负责执行和回调参数校验；发现、元数据和结果存储由调用方负责。

## 处理器

`SuperKernel\ScanIsolation\Contract\ScanProcessorInterface` 暴露 `support(): bool` 和 `execute(callable $callback): void`。本包提供四种实现：

- `PcntlScanProcessor` fork 进程，并在子进程中直接调用传入的回调；
- `ProcessScanProcessor` 通过不经过 shell 的 `proc_open()` 重入，启动当前的 PHP 入口脚本；
- `ExecScanProcessor` 通过转义后的 POSIX shell 命令启动当前的 PHP 入口脚本；
- `NullScanProcessor` 跳过隔离执行，只运行带参数的完成回调，用于恢复已经生成的扫描结果。不带参数的回调会被完全跳过。

`ScanProcessorProvider` 向容器提供 `ScanProcessorInterface`，也可以直接调用。它依次检查 Null、PCNTL、Process 和 Exec，返回第一个受支持的处理器。Null 只在 Phar 归档内受支持。在 Phar 之外，提供者会选择一个隔离型处理器；一个都不可用时抛出 `ScanProcessorUnavailableException`。

调用方也可以直接构造具体的处理器。构造时不会因为缺少可选能力而拒绝：调用 `execute()` 之前，请先调用 `support()` 并确认返回 `true`。

`PhaseScanProcessor` 是在 `execute()` 回调内部执行嵌套工作的显式委托。用该回调的 `ScannedInterface` 构造它：为 false 时，嵌套工作在已有的隔离进程中运行；为 true 时，跳过不带参数的扫描回调，并把 true 传给有状态的回调。它从不启动新的子进程，也不参与自动选择。它的支持探测接受传入的外层阶段；调用方需要在构造它之前确立隔离。

使用 `ScanProcessorInterface` 的组件，应把 `super-kernel/scan-isolation` 声明为直接的生产依赖。

## 回调执行

- 不声明参数的回调在隔离进程中执行一次。
- 声明一个 `SuperKernel\ScanIsolation\Contract\ScannedInterface` 参数的回调执行两次：第一次在隔离环境中，`isScanned() === false`；子进程成功退出后，第二次在调用方进程中，`isScanned() === true`。
- `ScannedInterface` 只暴露 `isScanned(): bool`。每次传入的状态都是不可变的。
- 参数检查在任何执行或子进程启动之前进行。无类型参数、其他类型、联合类型、交叉类型、多余参数、引用参数和可变参数都会抛出 `InvalidScanCallbackException`，它是一个 `RuntimeException`。可空或可选的 `ScannedInterface` 参数会被接受，并收到一个非 null 的实例。
- Null 跳过第一个阶段；它在当前进程中以 `isScanned() === true` 运行带参数的回调一次。调用方负责确保其持久化的扫描结果存在。

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

在两阶段示例中，`$scan` 和 `$restore` 是调用方提供的、用于发布和恢复扫描结果的回调。

### 完成阶段要求成功终止

`execute()` 会等待隔离进程结束，只有在子进程状态成功时才允许进入完成阶段。PCNTL 要求正常终止且退出码为 0；Process 要求 `proc_close()` 返回 0；Exec 要求 `exec()` 报告 0。Process 和 Exec 还要求子进程确认它已到达匹配的回调。退出码非零、被信号终止或状态未知时，会在任何 true 阶段之前在父进程中抛出 `ScanProcessFailedException`，即使旧的结果仍然可读。PCNTL 把信号报告为 128 + 信号值；Process 和 Exec 保留其原生的正数状态，信号的编码方式可能不同。状态未知时使用 127。`exitCode()` 描述的是子进程，而不是整个应用一定会使用的退出码。

例如，子进程回调调用 `exit(17)` 会在父进程中引发 `ScanProcessFailedException`，恢复回调不会运行。

隔离进程调用回调，并在回调正常返回后以 0 退出。回调抛出的异常在其所在进程中保持不变；由 PHP 和外层处理器决定如何终止。父进程中的失败是一个独立的进程状态异常，不会伪造前序 Throwable。PCNTL 继承输出；Process 分别转发 stdout 和 stderr；Exec 把合并后的输出（包括 PHP 诊断信息）转发到 stderr。已经转发过的诊断信息不会在状态异常中重复。输出或清理失败不能取代已经确定的子进程失败，也不能让完成阶段得以进行。

这是一个有限的保证：回调中的 `exit(0)`、外层处理器的 `exit(0)`，或吞掉错误后正常返回，仍会允许进入完成阶段。确认信号只能证明到达了回调，不能证明回调已经返回。应用必须自行校验已发布的结果。Null 没有子进程状态，保持其仅恢复的行为；Phase 委托给其外层阶段。当前进程中的回调失败会原样传播。Scan Isolation 不校验、也不失效任何缓存。

## 重入与进程边界

PCNTL 从 fork 出的内存快照中调用原始回调。Process 和 Exec 无法传递回调对象，而是用当前的参数列表重新运行当前的 `$_SERVER['SCRIPT_FILENAME']`；子进程必须自然地执行到对应的 `execute()` 调用，并重新创建其可调用值。子进程按回调的源码位置及其在该处理器策略中的出现次序来匹配这次调用，因此来自同一位置的多次调用不会混淆。在到达目标之前经过的 `execute()` 调用会跳过其隔离阶段，只运行带参数的完成阶段，以恢复已经发布的结果。

隔离环境中的修改永远不会回流到父进程内存，因此结果必须通过外部存储或显式 IPC 发布。完成阶段针对父进程中的原始回调运行，可以更新其对象或捕获的状态。回调的行为应当只由入口启动时的依赖决定，并在成功时正常返回；抛出异常是允许的终止方式。在回调中调用 `exit()` 会按 PHP 语义结束该隔离进程。

请在同步的启动阶段运行隔离扫描。PCNTL 应在启动长期运行的服务器之前、在打开那些被子进程继承后关闭行为不安全的资源之前运行。重入策略要求入口脚本以相同的回调顺序到达同一个扫描点，并且没有无关的副作用。当重入跳过了更早的扫描使用方的回调时，这些使用方必须从持久化存储或 IPC 中取得结果。
