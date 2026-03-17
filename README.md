# Hibla Parallel

**The high-performance, self-healing, and cross-platform parallel processing engine for PHP.**

Hibla Parallel brings **Erlang-style reliability** and **Node.js-level performance** to the PHP ecosystem. Orchestrate worker clusters that are fast (proven **100,000+ RPS**), truly non-blocking on all platforms, and capable of healing themselves through a supervised "Let it Crash" architecture.

[![Latest Release](https://img.shields.io/github/release/hiblaphp/parallel.svg?style=flat-square)](https://github.com/hiblaphp/parallel/releases)
[![MIT License](https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square)](./LICENSE)

---

##  Key Features

*   **Extreme Throughput:** Optimized for zero-latency task dispatching. Handles over **102,000 requests per second** in socket server benchmarks.
*   **True Non-Blocking I/O:** Fully asynchronous architecture. Parent-to-worker communication never starves the event loop, even on Windows.
*   **Cross-Platform:** Seamless support for **Linux, macOS, and Windows**. Intelligently uses socket pairs on Windows to bypass kernel pipe limitations.
*   **Self-Healing:** Using `onWorkerRespawn`, the master process ensures tasks are always running by auto-respawning crashed workers.
*   **Fractal Concurrency:** Mix Multi-process Parallelism with Fiber-based Asynchrony recursively.
*   **OS-Level Task Cancellation:** Terminate tasks instantly. Kills OS processes and automatically maintains pool capacity.

---

## Installation

```bash
composer require hiblaphp/parallel
```

---

## 1. Quick Start: Simple Primitives

Hibla provides global helper functions for the most common use cases.

### One-Off Tasks with Results
```php
use function Hibla\parallel;
use function Hibla\await;

$result = await(parallel(function() {
    // This logic runs in a separate process
    return strlen("Hello from the background!");
}));

echo $result; // 27
```

### Fire-and-Forget
```php
use function Hibla\{spawn, await};

// spawn() returns a promise resolving to a BackgroundProcess handle
$process = await(spawn(function() {
    file_put_contents('log.txt', "Task started at " . date('Y-m-d H:i:s'));
    sleep(5);
}));

if ($process->isRunning()) {
    echo "Task is running with PID: " . $process->getPid();
}
```
---

## 2. Persistent Worker Pools

Worker pools maintain a fixed set of workers to eliminate the overhead of repeated process spawning and framework bootstrapping.

```php
use Hibla\Parallel\Parallel;
use function Hibla\await;

$pool = Parallel::pool(size: 4)
    ->withMaxExecutionsPerWorker(100) // Periodic retirement to clear memory
    ->withMemoryLimit('128M');

$task = fn() => getmypid();

// Execute tasks across the pool
for ($i = 0; $i < 4; $i++) {
    $pool->run($task)->then(fn($pid) => print("Handled by worker: $pid\n"));
}

/**
 * CRITICAL: Shutdown
 * Persistent workers maintain open IPC channels that keep the Event Loop alive.
 * You MUST call shutdown or shutdownAsync to allow the script to exit.
 */

// Option A: Synchronous (blocks until all workers exit)
$pool->shutdown();

// Option B: Asynchronous (returns a Promise)
// await($pool->shutdownAsync());
```

---

---

## 3. Self-Healing & Supervisor Pattern

Build "Erlang-style" supervised clusters. If a worker crashes, Hibla triggers `onWorkerRespawn`, allowing you to re-initialize your logic (e.g., a socket server) automatically.

```php
use Hibla\Parallel\Parallel;
use Hibla\Parallel\Interfaces\ProcessPoolInterface;

$serverTask = function() {
    // Worker logic (e.g., binding to a socket)
    echo "[Worker] Listening on 8080...\n";
    // Simulate a crash after 5 seconds
    Hibla\delay(5)->then(fn() => exit(1)); 
};

$pool = Parallel::pool(size: 2)
    ->onWorkerRespawn(function (ProcessPoolInterface $pool) use ($serverTask) {
        echo "[Master] Worker died! Respawning and re-applying task...\n";
        $pool->run($serverTask); 
    });

// Initial boot
$pool->run($serverTask);
$pool->run($serverTask);
```

---

## 4. Fractal Concurrency: The Async Hybrid

Hibla Parallel provides a unified concurrency model. While `async/await` handles non-blocking I/O, `parallel()` allows you to offload **actual blocking PHP functions** (like `sleep()`, legacy database drivers, or heavy CPU tasks) to background processes.

Because every worker is a "Smart Worker" with its own Event Loop, you can mix these models recursively.

### Conquering Blocking I/O
In standard PHP, three `sleep(1)` calls take 3 seconds. With Hibla, you can run blocking work in parallel while simultaneously running non-blocking work in fibers. **The following block finishes in exactly 1 second:**

```php
use Hibla\Promise\Promise;
use function Hibla\{parallel, async, await, delay};

$start = microtime(true);

Promise::all([
    // Task 1: A worker running NATIVE BLOCKING sleep()
    parallel(fn() => sleep(1)), 

    // Task 2: Another worker running NATIVE BLOCKING sleep()
    parallel(fn() => sleep(1)),

    // Task 3: A "Smart" worker managing its own internal async fibers
    parallel(function () {
        await(Promise::all([
            async(fn() => await(delay(1))),
            async(fn() => await(delay(1))),
        ]));
        return "Hybrid Done";
    }),

    // Task 4: A non-blocking fiber in the Master process
    async(fn() => await(delay(1))),
])->wait();

$duration = microtime(true) - $start;
echo "Executed ~4 seconds of work in: {$duration} seconds!"; 
// Output: Executed ~4 seconds of work in: 1.04 seconds!
```

## 5. Distributed Exception Teleportation

Hibla "teleports" exceptions from workers back to the parent. It re-instantiates the original exception type and **merges stack traces** so you see exactly where the error originated.

```php
use function Hibla\parallel;
use function Hibla\await;

try {
    await(parallel(function () {
        // Error happens deep in a worker
        throw new \BadFunctionCallException("Database connection failed!");
    }));
} catch (\BadFunctionCallException $e) {
    echo $e->getMessage(); // "Database connection failed!"
    echo $e->getTraceAsString(); 
    /* 
       Trace will show:
       #0 ParentCode.php: Line where parallel() was called
       #1 --- WORKER STACK TRACE ---
       #2 worker.php: Line where exception was thrown
    */
}
```

---

## 6. IPC & Real-time Output

### Console Streaming
Everything printed inside a worker is streamed to the parent console instantly via non-blocking buffers.

```php
use function Hibla\parallel;

parallel(function() {
    echo "Starting process...\n";
    sleep(1);
    echo "50% complete...\n";
    sleep(1);
    echo "Finished!\n";
});
// Parent sees messages in real-time as they are printed
```

### Structured Messaging (`emit`)
Use `emit()` to send structured data back to the parent without finishing the task.

```php
use Hibla\Parallel\Parallel;

$executor = Parallel::task()
    ->onMessage(function($msg) {
        printf("Worker %d says progress is %d%%\n", $msg->pid, $msg->data['p']);
    })
    ->run(function() {
        Hibla\emit(['p' => 25]);
        sleep(1);
        Hibla\emit(['p' => 100]);
    });
```

---

## 7. Abnormal Termination Detection

If a worker hits a Segmentation Fault or calls `exit()`, Hibla detects the silent death and rejects the promise with a `ProcessCrashedException`.

```php
use Hibla\Parallel\Exceptions\ProcessCrashedException;
use function Hibla\parallel;
use function Hibla\await;

try {
    await(parallel(fn() => exit(1))); 
} catch (ProcessCrashedException $e) {
    echo "Alert: Worker crashed unexpectedly!";
}
```

---

## 8. Framework Bootstrapping

Load Laravel, Symfony, or any custom environment inside your workers.

```php
use Hibla\Parallel\Parallel;

$pool = Parallel::pool(8)
    ->withBootstrap(__DIR__ . '/bootstrap/app.php', function($file) {
        $app = require $file;
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    });

$pool->run(fn() => config('app.name')); // Access framework features
```

---

## 9. Global Configuration

Create a `hibla_parallel.php` in your root directory to set system-wide defaults.

```php
return [
    'max_nesting_level' => 5,
    'process' => [
        'memory_limit' => '512M',
        'timeout' => 60,
    ],
    'bootstrap' => [
        'file' => __DIR__ . '/vendor/autoload.php',
        'callback' => null
    ],
];
```

---

## 10. Task Cancellation & Management

If you cancel a task promise, Hibla forcefully kills the underlying OS process immediately.

```php
$promise = $pool->run(fn() => sleep(100));

// Change of plans?
$promise->cancel(); 

// 1. Hibla kills the OS process via SIGKILL/taskkill
// 2. The pool immediately respawns a fresh worker to maintain capacity.
```

---

## 11. Nested Execution & Safety

1.  **Short Closure Warning:** Do **NOT** nest `parallel()` calls using arrow functions (`fn() => ...`). This causes AST corruption and infinite **Fork Bombs**. Always use `function() {}` or **Invokable Classes** for nesting.
2.  **Must Await:** Always `await()` nested parallel calls. Un-awaited nested tasks may be killed by the OS if the parent worker exits first.

---

---

## 12. Architecture & Testability

Hibla Parallel is designed with high-level architectural patterns in mind. The `Parallel` class acts as a static facade, providing a clean entry point to the engine's core strategies. Every strategy is backed by a dedicated interface and a concrete implementation.

### The Strategy Map

| Facade Method | Concrete Class | Interface | Description |
| :--- | :--- | :--- | :--- |
| `Parallel::task()` | `ParallelExecutor` | `ParallelExecutorInterface` | One-off tasks that return a result. |
| `Parallel::pool(n)` | `ProcessPool` | `ProcessPoolInterface` | A managed cluster of reusable persistent workers. |
| `Parallel::background()` | `BackgroundExecutor` | `BackgroundExecutorInterface` | Detached fire-and-forget processes. |

### Usage Options

#### A. Using the Facade (Recommended for most cases)
The Facade is the easiest way to access Hibla's features using the global configuration.
```php
use Hibla\Parallel\Parallel;

$result = await(Parallel::task()->run(fn() => "Facade result"));
```

#### B. Direct Instantiation (Best for DI and Manual Control)
You can skip the facade and instantiate the concrete classes directly. This is ideal if you are using a Container (like Laravel's or Symfony's) to manage your services.
```php
use Hibla\Parallel\ProcessPool;

// Manual instantiation
$pool = new ProcessPool(size: 8);

$pool->run(fn() => "Direct instantiation result");
```

### Dependency Injection & Mocking

By type-hinting the interfaces, your code becomes decoupled from the library's implementation. This allows you to swap execution strategies (e.g., swapping a `ParallelExecutor` for a `ProcessPool`) or use Mocks during testing.

```php
use Hibla\Parallel\Interfaces\ParallelExecutorInterface;

class ReportGenerator
{
    public function __construct(
        private ParallelExecutorInterface $executor 
    ) {}

    public function generate(array $data)
    {
        return $this->executor->run(fn() => $this->heavyLogic($data));
    }
}

// In Production (injecting a pool for speed)
$generator = new ReportGenerator(Parallel::pool(4));

// In Testing (injecting a mock)
$mock = Mockery::mock(ParallelExecutorInterface::class);
$mock->shouldReceive('run')->andReturn(Promise::resolved('mock_data'));
$generator = new ReportGenerator($mock);
```

### Clean Resource Management
All concrete classes implement `__destruct()` logic to attempt to clean up OS resources. However, for **ProcessPool**, it is always recommended to call `shutdown()` or `shutdownAsync()` explicitly to ensure the Event Loop can exit cleanly once work is complete.

---


## Credits
*   **Serialization:** Built on the high-performance [rcalicdan/serializer](https://github.com/rcalicdan/serializer) and [opis/closure](https://github.com/opis/closure).
*   **Philosophy:** Inspired by the Erlang/OTP fault-tolerance model.

---

## License
MIT License. See [LICENSE](./LICENSE) for more information.