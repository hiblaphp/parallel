# Hibla Parallel

**The high-performance, self-healing, and cross-platform parallel processing engine for PHP.**

Hibla Parallel brings **Erlang-style reliability** and **Node.js-level cluster pool performance** to the PHP ecosystem. Orchestrate worker clusters that are fast (proven **100,000+ RPS** on http socket server benchmarks), truly non-blocking on all platforms, and capable of healing themselves through a supervised "Let it Crash" architecture.

[![Latest Release](https://img.shields.io/github/release/hiblaphp/parallel.svg?style=flat-square)](https://github.com/hiblaphp/parallel/releases)
[![MIT License](https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square)](./LICENSE)

---

## Contents

**Getting started**
- [Installation](#installation)
- [Quick Start](#quick-start)
- [Quick Reference](#quick-reference)

**Core concepts**
- [How Parallel Works](#how-parallel-works)
- [Worker Types](#worker-types)
- [Serialization: Crossing the Process Boundary](#serialization-crossing-the-process-boundary)
- [IPC: Inter-Process Communication](#ipc-inter-process-communication)

**Writing tasks**
- [Simple Primitives](#simple-primitives)
- [Rich Data & Stateful Execution](#rich-data--stateful-execution)
- [Global Helper Functions](#global-helper-functions)
  - [`parallel()`](#parallelcallable-task-int-timeout--null)
  - [`parallelFn()`](#parallelFncallable-task-int-timeout--null)
  - [`spawn()`](#spawncallable-task-int-timeout--null)
  - [`spawnFn()`](#spawnFncallable-task-int-timeout--null)
  - [Using `runFn` and `spawnFn` on fluent executors](#using-runfn-and-spawnfn-on-fluent-executors)

**Persistent pools**
- [Persistent Worker Pools](#persistent-worker-pools)
  - [Automatic garbage collection between tasks](#automatic-garbage-collection-between-tasks)
  - [Why `withMaxExecutionsPerWorker`](#why-withmaxexecutionsperworker)
  - [Lazy vs. Eager Spawning](#lazy-vs-eager-spawning)
    - [Pre-warming with `boot()`](#pre-warming-with-boot)
    - [Lazy Spawning](#lazy-spawning)
- [Self-Healing & Supervisor Pattern](#self-healing--supervisor-pattern)
  - [Complete example: chaos server](#complete-example-chaos-server)
- [Respawn Rate Limiting](#respawn-rate-limiting)        ← new
  - [Fail-fast, not slow-fail](#fail-fast-not-slow-fail) ← new
  - [Choosing the right limit](#choosing-the-right-limit) ← new
  - [Interaction with `onWorkerRespawn`](#interaction-with-onworkerrespawn) ← new
- [Pool Monitoring](#pool-monitoring)
  - [PID accuracy and `boot()`](#pid-accuracy-and-boot)

**Real-time communication**
- [Real-time Output & Messaging](#real-time-output--messaging)
  - [Console Streaming](#console-streaming)
  - [Structured Messaging with `emit()`](#structured-messaging-with-emit)
  - [Pool-Level vs. Per-Task Message Handlers](#pool-level-vs-per-task-message-handlers)
  - [Handler completion blocks task promise resolution](#handler-completion-blocks-task-promise-resolution)

**Advanced patterns**
- [Fractal Concurrency: The Async Hybrid](#fractal-concurrency-the-async-hybrid)
- [Nested Execution & Safety](#nested-execution--safety)
  - [The nested closure problem](#the-nested-closure-problem)
  - [Closure safety levels](#closure-safety-levels)
  - [Always `await()` nested calls](#always-await-nested-calls)
  - [Configuring the nesting limit](#configuring-the-nesting-limit)

**Error handling & safety**
- [Distributed Exception Teleportation](#distributed-exception-teleportation)
- [Abnormal Termination Detection](#abnormal-termination-detection)
- [Task Cancellation & Management](#task-cancellation--management)
- [Exception Reference](#exception-reference)

**Project setup**
- [Framework Bootstrapping](#framework-bootstrapping)
- [Autoloading & Code Availability](#autoloading--code-availability)
- [Global Configuration](#global-configuration)
- [Architecture & Testability](#architecture--testability)
  - [Strategy Map](#strategy-map)
  - [Using the Facade](#using-the-facade-recommended)
  - [Direct Instantiation](#direct-instantiation-best-for-di)
  - [Dependency Injection & Mocking](#dependency-injection--mocking)

**Meta**
- [Credits](#credits)
- [License](#license)

---

## Installation
```bash
composer require hiblaphp/parallel
```
**Requirements:**
- PHP 8.3+


- `hiblaphp/event-loop`
- `hiblaphp/promise`
- `hiblaphp/stream`
- `hiblaphp/async`
---

## Quick Start

The fastest way to run code in a separate process and get the result back:
```php
require __DIR__ . '/vendor/autoload.php';

use function Hibla\{parallel, await};

$result = await(parallel(function () {
    return 'Hello from a separate process!';
}));

echo $result; // Hello from a separate process!
```

That is the entire API for the common case. The rest of this document covers
everything built on top of it.

---

## Quick Reference

Choose the right tool for your use case:

| Use Case | API | Returns |
| :--- | :--- | :--- |
| Run a task and get its result | `parallel(fn() => ...)` or `Parallel::task()->run(...)` | `PromiseInterface<TResult>` |
| Run many tasks, wrapping a callable | `parallelFn(callable)` or `Parallel::task()->runFn(callable)` | `callable(mixed ...$args): PromiseInterface<TResult>` |
| Fire-and-forget background work | `spawn(fn() => ...)` or `Parallel::background()->spawn(...)` | `PromiseInterface<BackgroundProcess>` |
| Fire-and-forget, wrapping a callable | `spawnFn(callable)` or `Parallel::background()->spawnFn(callable)` | `callable(mixed ...$args): PromiseInterface<BackgroundProcess>` |
| High-throughput repeated work | `Parallel::pool(n)->run(...)` | `PromiseInterface<TResult>` |
| High-throughput, wrapping a callable | `Parallel::pool(n)->runFn(callable)` | `callable(mixed ...$args): PromiseInterface<TResult>` |

**Fluent configuration** is available on all strategies:

| Method | Effect |
| :--- | :--- |
| `->withTimeout(int $seconds)` | Reject with `TimeoutException` after N seconds, terminate the worker and its full process tree |
| `->withoutTimeout()` | Disable the timeout entirely |
| `->withMemoryLimit(string $limit)` | Set worker memory limit (e.g. `'256M'`, `'1G'`) |
| `->withUnlimitedMemory()` | Set memory limit to `-1` |
| `->withBootstrap(string $file, ?callable $cb)` | Load a framework or legacy environment in the worker |
| `->withMaxNestingLevel(int $level)` | Override the fork-bomb safety limit (1–10) |
| `->withLazySpawning()` | *(Pool only)* Spawn workers on first `run()` call instead of at construction |
| `->withMaxExecutionsPerWorker(int $n)` | *(Pool only)* Retire and replace workers after N tasks |
| `->withMaxRestartPerSecond(int $n)` | *(Pool only)* Limit respawn rate to N workers per second |
| `->onMessage(callable $handler)` | *(Task/Pool)* Register a handler for `emit()` messages from workers |
| `->onWorkerRespawn(callable $handler)` | *(Pool only)* Called whenever a worker exits and a replacement is spawned |
| `->boot()` | *(Pool only)* Pre-warm all workers immediately; returns before workers are ready |
---

## How Parallel Works

PHP is a single-threaded runtime. The event loop and fiber-based concurrency in
`hiblaphp/async` let you interleave non-blocking I/O efficiently, but they do
not escape this constraint — one thread, one CPU core. When you need to run
genuinely CPU-bound work, call a blocking library that cannot be made async, or
isolate a task so a fatal error in it cannot kill your main process, you need
true parallelism.

There are two approaches to parallelism in PHP: multi-threading and
multi-processing. Multi-threading via extensions like `ext-parallel` runs
multiple threads inside the same process and shares memory between them, but it
is CLI-only, requires complex synchronization primitives (mutexes, channels),
and is inherently prone to race conditions when shared state is not carefully
managed. Hibla Parallel does not use this model. Multi-processing spawns
independent OS processes that share nothing and communicate explicitly — simpler
to reason about, safer by default, and available in every PHP environment
without extensions. This is the model Hibla Parallel is built on, and
multi-threading support is not currently planned.

Hibla Parallel solves this by spawning real child processes via `proc_open()`
and communicating with them over OS-level I/O channels. Each worker is a fresh
PHP process — its own memory space, its own CPU scheduling, its own crash
domain. A segfault, out-of-memory kill, or unhandled fatal in a worker cannot
corrupt or terminate the parent.

When you call `parallel(fn() => heavyWork())`:

1. **Spawn.** The parent calls `proc_open()` to start a new PHP process running
   one of Hibla's worker scripts. The worker receives its I/O channels (stdin,
   stdout, stderr) at the OS level.
2. **Serialize.** The parent serializes the closure — its code, bound variables,
   and `$this` if captured — into a JSON payload and writes it to the worker's
   stdin.
3. **Execute.** The worker reads the payload, deserializes the closure, runs it
   inside its own event loop, and captures the return value.
4. **Communicate.** The worker writes structured JSON frames back to the parent
   over stdout in real time — output frames as the task runs, a terminal frame
   when it finishes.
5. **Deserialize.** The parent reads the terminal frame, deserializes the
   result, and resolves the promise.

The parent never blocks during this. Communication happens over non-blocking
streams managed by `hiblaphp/stream`, so the parent's event loop continues
running other fibers, timers, and I/O while it waits for a worker to finish. A
pool of four workers can handle four tasks simultaneously without the parent
event loop stalling at all.
```
Parent process (event loop running)
│
├── parallel(fn() => taskA())  ──spawn──▶  Worker A (own PHP process)
│       └── Promise<A>                          runs taskA(), writes frames
│
├── parallel(fn() => taskB())  ──spawn──▶  Worker B (own PHP process)
│       └── Promise<B>                          runs taskB(), writes frames
│
└── await(Promise::all([A, B]))
        │
        │  ◀── stdout frames ── Worker A resolves → promise A resolved
        │  ◀── stdout frames ── Worker B resolves → promise B resolved
        │
        └── both results available, script continues
```

---

## Worker Types

Hibla Parallel ships three worker scripts, each optimized for a different
execution model. The parent selects the correct script automatically based on
which API you use.

### Streamed worker (`worker.php`)

Used by `parallel()` and `Parallel::task()`. Spawned fresh for each task.
Maintains full bidirectional communication with the parent — OUTPUT frames
stream in real time, `emit()` sends MESSAGE frames, exceptions teleport back
as ERROR frames, and results are transmitted as COMPLETED frames. The worker
exits after handling one task.

### Background worker (`worker_background.php`)

Used by `spawn()` and `Parallel::background()`. Spawned fresh for each task.
stdout and stderr are redirected to `/dev/null` at spawn time — the parent has
no communication channel back from this worker. The worker runs the task and
exits. This means `emit()` is a silent no-op inside background workers, and
there is no result, no exception teleportation, and no real-time output. Use
this only for true fire-and-forget work where you do not need to know what
happened inside.

### Persistent worker (`worker_persistent.php`)

Used by `Parallel::pool()`. Spawned once per pool slot at pool construction
(or on first use with lazy spawning) and kept alive to handle many tasks
sequentially. Shares the same structured-frame communication protocol as the
streamed worker but with task IDs added to every frame for routing, plus the
READY/RETIRING/CRASHED lifecycle frames. Crashes trigger the pool's respawn
logic. Retirement after `withMaxExecutionsPerWorker(n)` tasks triggers a clean
RETIRING exit and an automatic replacement.

---

## Serialization: Crossing the Process Boundary

The central challenge in any multi-process system is that processes do not
share memory. Everything that moves between the parent and a worker — the task
to run, the result it produces, exceptions it throws, and messages it emits —
must be converted to bytes, transmitted, and reconstructed on the other side.

### Serializing the task

PHP closures are not natively serializable. Hibla uses
[opis/closure](https://github.com/opis/closure) to serialize the AST (Abstract
Syntax Tree) of the closure's source code along with any variables it captures
from its surrounding scope. The serialized representation is a compact string
that the worker can deserialize back into a fully functional callable —
including bound variables and the captured `$this` object if the closure was
defined inside a class method.
```php
$multiplier = 3;

$result = await(parallel(function () use ($multiplier) {
    // $multiplier = 3 is serialized with the closure and reconstructed
    // in the worker — the worker never shared memory with the parent,
    // it received a copy of the value
    return 6 * $multiplier; // 18
}));
```

The entire task payload is JSON-encoded before being written to the worker's
stdin:
```json
{
  "serialized_callback": "...",
  "autoload_path": "/var/www/vendor/autoload.php",
  "framework_bootstrap": null,
  "timeout_seconds": 60,
  "memory_limit": "512M"
}
```

### Serializing the result

Once the task finishes, the worker serializes the return value back to the
parent. Scalar values (strings, integers, floats, booleans, null) and plain
arrays are JSON-encoded directly. Objects and nested arrays containing objects
are serialized with PHP's native `serialize()` and base64-encoded before being
embedded in the JSON frame:
```json
{
  "status": "COMPLETED",
  "result": "base64encodedSerializedObject==",
  "result_serialized": true
}
```

The parent checks the `result_serialized` flag, decodes and unserializes the
value if needed, and resolves the promise with the reconstructed object. The
object arrives in the parent with its type, properties, and values intact — as
if it had never left the process.

### Serializing exceptions

When a task throws, the worker captures the exception's class name, message,
code, file, line, and full stack trace, encodes them as a structured ERROR
frame, and sends it to the parent. The parent's `ExceptionHandler`
re-instantiates the original exception class (falling back to `RuntimeException`
if the class is not available in the parent) and merges the worker's stack trace
into it, so the combined trace shows both where `parallel()` was called in the
parent and where the exception was thrown in the worker.

### What cannot be serialized

Objects that hold active OS resources — open database connections (PDO handles),
open file pointers, network sockets, cURL handles — cannot be serialized because
the resource itself lives in the OS kernel and is tied to the specific process
that opened it. Attempting to pass one will fail at serialization time with a
`TaskPayloadException`.

The pattern for these cases is to initialize the resource inside the worker,
not pass it in:
```php
// Wrong — PDO handle cannot be serialized across processes
$db = new PDO('mysql:host=localhost;dbname=app', 'user', 'pass');
$result = await(parallel(function () use ($db) {
    return $db->query('SELECT ...');  // TaskPayloadException
}));

// Correct — create the connection inside the worker
$result = await(parallel(function () {
    $db = new PDO('mysql:host=localhost;dbname=app', 'user', 'pass');
    return $db->query('SELECT ...')->fetchAll();
}));
```

---

## IPC: Inter-Process Communication

Hibla Parallel communicates with workers over OS-level I/O channels created by
`proc_open()`. Understanding this channel design explains why the library is
non-blocking even on Windows and how real-time output streaming works.

### Structured frames

All communication uses a simple line-delimited JSON protocol — similar in
spirit to [NDJSON](https://ndjson.org/) and
[JSON Lines](https://jsonlines.org/), but implemented directly over
`proc_open()` pipes rather than files or TCP sockets. The parent writes one
JSON line to the worker's stdin to deliver the task. The worker writes JSON
lines to its stdout as the task runs. Each line is a complete, self-contained
JSON object terminated by a newline — the parent reads lines one at a time on
a non-blocking stream and routes each frame by its `status` field.

| Frame type | Direction | When it is sent |
| :--- | :--- | :--- |
| `OUTPUT` | Worker → Parent | The task called `echo` or `print` |
| `MESSAGE` | Worker → Parent | The task called `emit()` |
| `COMPLETED` | Worker → Parent | The task returned a value |
| `ERROR` | Worker → Parent | The task threw an exception |
| `TIMEOUT` | Worker → Parent | The task exceeded its time limit |
| `READY` | Worker → Parent | *(Persistent workers only)* Worker booted and is ready for a task |
| `RETIRING` | Worker → Parent | *(Persistent workers only)* Worker has hit its max executions limit and is exiting cleanly |
| `CRASHED` | Worker → Parent | *(Persistent workers only)* Worker is dying due to an unrecoverable error |

### Pipes on Unix, sockets on Windows

On Linux and macOS, Hibla uses anonymous pipes (`['pipe', 'r']` and
`['pipe', 'w']` in the `proc_open()` descriptor spec). Anonymous pipes support
`stream_set_blocking(false)` correctly at the kernel level, making them fully
compatible with the non-blocking stream layer. They are also approximately 15%
faster than sockets for small messages.

On Windows, anonymous pipes do not support non-blocking mode at the kernel
level. Calling `stream_set_blocking($pipe, false)` on a Windows anonymous pipe
is silently ignored — the pipe remains blocking. A blocking read on a pipe that
has no data stalls the entire PHP thread indefinitely, which would deadlock the
event loop.

Hibla detects the platform at spawn time and switches to socket pairs
(`['socket']`) on Windows. Socket pairs support true non-blocking mode on
Windows and are the standard solution for this limitation. The rest of the code
— stream reading, frame parsing, promise resolution — is identical on both
platforms:
```php
// From ProcessSpawnHandler::spawnStreamedTask()
$descriptorSpec = PHP_OS_FAMILY === 'Windows'
    ? [0 => ['socket'], 1 => ['socket'], 2 => ['socket']]
    : [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
```

### Stdin handshake and drain-and-wait

After the worker finishes and writes its terminal frame (COMPLETED, ERROR, or
TIMEOUT), it does not exit immediately. On Windows, a process exiting instantly
destroys its socket descriptors, which can wipe out bytes that are still in the
OS transmit buffer before the parent's non-blocking stream reader has consumed
them.

To prevent this, the worker enters a drain-and-wait loop after writing the
terminal frame: it switches its own stdin to non-blocking mode and reads in a
5ms polling loop for up to 500ms. The parent signals it is done by closing its
end of the stdin pipe after receiving the terminal frame, which causes `feof()`
to return `true` in the worker's drain loop and lets the worker exit cleanly.
This handshake guarantees the terminal frame is always fully received before the
worker process disappears.

### Persistent worker IPC

Persistent pool workers use the same channel design but stay alive across
multiple tasks. Instead of a one-shot payload-and-exit model, the boot sequence
is:

1. Parent spawns the worker and writes a one-time boot payload to stdin with
   the autoload path, bootstrap configuration, memory limit, and retirement
   threshold.
2. Worker loads the autoloader, runs any framework bootstrap, and writes
   `{"status":"READY","pid":12345}` to stdout.
3. Parent dispatches queued tasks by writing JSON lines to stdin, one per task,
   each identified by a unique `task_id`.
4. Worker reads task lines in a loop, executes each one, and writes COMPLETED
   or ERROR frames tagged with the same `task_id` back to stdout.
5. All frames from all tasks share the same stdout channel — the `task_id` tag
   allows the parent to route each frame to the correct task's promise and
   `onMessage` handler.

The worker reports its own PID (`getmypid()`) in the READY frame because
`proc_get_status()['pid']` returns the shell wrapper's PID on some platforms
rather than the actual PHP process PID. The self-reported PID is used for
`SIGKILL` / `taskkill` on cancellation and is what `getWorkerPids()` returns
once the READY frame has been received — see [Pool Monitoring](#pool-monitoring)
for the full detail on PID accuracy.

---

## Simple Primitives

Hibla provides global helper functions for the most common use cases.

### One-off tasks with results
```php
use function Hibla\{parallel, await};

$result = await(parallel(function () {
    return strlen("Hello from the background!");
}));

echo $result; // 27
```

### Fire-and-forget
```php
use function Hibla\{spawn, await};

// spawn() returns a Promise that resolves to a BackgroundProcess handle.
// You must await() the spawn call itself to get that handle.
$process = await(spawn(function () {
    file_put_contents('log.txt', "Task started at " . date('Y-m-d H:i:s'));
    sleep(5);
}));

if ($process->isRunning()) {
    echo "Task is running with PID: " . $process->getPid();
}

// $process->terminate(); // kill it early if needed
```

---

## Rich Data & Stateful Execution

Hibla Parallel is not limited to scalar return values. Because it uses a full
serialization engine, you can pass objects into tasks, return objects from
tasks, and access class state from inside parallel closures — all of it crossing
the process boundary transparently.

### Returning value objects

Any serializable object returned from a worker is reconstructed in the parent
with its type, class name, and property values intact:
```php
use App\ValueObjects\TaskResult;
use function Hibla\{parallel, await};

$result = await(parallel(function () {
    return new TaskResult(status: 'success', data: [1, 2, 3]);
}));

echo get_class($result); // "App\ValueObjects\TaskResult"
echo $result->status;    // "success"
```

### Accessing class properties (`$this`)

When `parallel()` is called inside a class method, the closure can capture
`$this`. The worker receives a serialized copy of the object and can read its
properties — including private ones:
```php
namespace App\Services;

class ReportBuilder
{
    public function __construct(
        private string $title = "Q4 Report",
        private int $year = 2025
    ) {}

    public function buildParallel(): string
    {
        return await(parallel(fn() => "{$this->title} ({$this->year})"));
    }
}

$builder = new ReportBuilder();
echo $builder->buildParallel(); // "Q4 Report (2025)"
```

### Two rules for object serialization

1. **Autoloading:** The class definition must be available to the worker via the
   Composer autoloader or a bootstrap file. If the worker cannot find the class,
   deserialization will fail.
2. **No resources:** Objects holding active OS resources (PDO handles, open file
   pointers, network sockets) cannot cross the process boundary — see
   [Serialization: Crossing the Process Boundary](#serialization-crossing-the-process-boundary)
   for the correct pattern.

---

## Global Helper Functions

Hibla Parallel exposes four global helpers in the `Hibla` namespace. They are
thin wrappers around the facade and are the recommended API for most scripts.
All four accept an optional `$timeout` argument. For anything beyond a simple
timeout override — custom memory limits, bootstrap files, or message handlers —
use the fluent executors directly (see
[Using `runFn` and `spawnFn` on fluent executors](#using-runfn-and-spawnfn-on-fluent-executors)).

### `parallel(callable $task, ?int $timeout = null)`

Runs a task in a new process and returns a `Promise` resolving to the task's
return value.
```php
use function Hibla\{parallel, await};

$result = await(parallel(function () {
    return expensiveComputation();
}, timeout: 30));
```

### `parallelFn(callable $task, ?int $timeout = null)`

Wraps a callable and returns a new callable that spawns a fresh parallel process
on each invocation. Useful with higher-order functions like `Promise::all()` or
event listeners where you need to pass a callable rather than invoke one
immediately.
```php
use function Hibla\{parallelFn, await};
use Hibla\Promise\Promise;

$processItem = parallelFn(function (int $id): array {
    return fetchFromDatabase($id);
});

$results = await(Promise::all([
    $processItem(1),
    $processItem(2),
    $processItem(3),
]));
```

### `spawn(callable $task, ?int $timeout = null)`

Spawns a fire-and-forget background process. The returned `Promise` resolves
immediately with a `BackgroundProcess` handle. No result, output, or exception
is ever returned to the parent.
```php
use function Hibla\{spawn, await};

$process = await(spawn(function () {
    generateReport();
}));

echo $process->getPid();
echo $process->isRunning() ? 'still running' : 'done';

$process->terminate(); // kill it early if needed
```

### `spawnFn(callable $task, ?int $timeout = null)`

The fire-and-forget equivalent of `parallelFn`. Returns a callable that spawns
a new detached background process on each invocation.
```php
use function Hibla\spawnFn;

$sendEmail = spawnFn(function (string $to, string $subject): void {
    mailer()->send($to, $subject);
});

$sendEmail('alice@example.com', 'Welcome!');
$sendEmail('bob@example.com', 'Your invoice');
```

> **Note:** `emit()` called inside a `spawn()` or `spawnFn()` task is a silent
> no-op. Fire-and-forget workers redirect stdout to `/dev/null`, so structured
> message passing is unavailable. Use `parallel()` or a pool if you need
> `emit()`.

### Using `runFn` and `spawnFn` on fluent executors

The global helpers use default configuration. When you need custom timeouts,
memory limits, a bootstrap file, or message handlers applied consistently to
every invocation of the factory, use the `->runFn()` and `->spawnFn()` methods
on the fluent executors instead.

The returned callable behaves identically to calling `->run()` or `->spawn()`
directly — the only difference is that you get a reusable callable rather than
an immediate promise.

**`Parallel::task()->runFn()`**

Each invocation spawns a fresh worker with the configured settings:
```php
use Hibla\Parallel\Parallel;
use Hibla\Parallel\ValueObjects\WorkerMessage;
use Hibla\Promise\Promise;
use function Hibla\{await, emit};

$processItem = Parallel::task()
    ->withTimeout(30)
    ->withMemoryLimit('256M')
    ->withBootstrap('/path/to/bootstrap.php')
    ->onMessage(function (WorkerMessage $msg) {
        printf("Worker %d: %s\n", $msg->pid, $msg->data);
    })
    ->runFn(function (int $id): array {
        emit("Processing $id");
        return fetchAndTransform($id);
    });

$results = await(Promise::all([
    $processItem(1),
    $processItem(2),
    $processItem(3),
]));
```

`runFn()` accepts a per-invocation message handler as its second argument. It
fires before the executor-level `onMessage` handler, following the same ordering
rules described in
[Pool-Level vs. Per-Task Message Handlers](#pool-level-vs-per-task-message-handlers):
```php
$processItem = Parallel::task()
    ->onMessage(fn($msg) => Log::info('executor-level', [$msg->data]))
    ->runFn(
        function (int $id): array {
            return fetchAndTransform($id);
        },
        onMessage: fn($msg) => Log::debug('per-invocation', [$msg->data])
    );
```

**`Parallel::pool()->runFn()`**

Tasks are dispatched to the pool's persistent workers rather than spawning a
fresh process per invocation. Use this when you want both the reuse efficiency
of a pool and a consistent callable interface for higher-order functions:
```php
use Hibla\Parallel\Parallel;
use Hibla\Promise\Promise;
use function Hibla\await;

$pool = Parallel::pool(size: 4)
    ->withTimeout(30)
    ->withMemoryLimit('256M');

$processItem = $pool->runFn(function (int $id): array {
    return fetchAndTransform($id);
});

$results = await(Promise::all(array_map($processItem, $ids)));

$pool->shutdown();
```

**`Parallel::background()->spawnFn()`**

Each invocation spawns a detached fire-and-forget process with the configured
settings. The returned promise resolves immediately with a `BackgroundProcess`
handle, not a task result:
```php
use Hibla\Parallel\Parallel;
use function Hibla\await;

$sendEmail = Parallel::background()
    ->withTimeout(120)
    ->withMemoryLimit('128M')
    ->withBootstrap('/path/to/bootstrap.php')
    ->spawnFn(function (string $to, string $subject): void {
        Mailer::send($to, $subject);
    });

await($sendEmail('alice@example.com', 'Welcome!'));
await($sendEmail('bob@example.com', 'Invoice'));
```

> **Note:** `emit()` called inside a `->spawnFn()` task is a silent no-op.
> Fire-and-forget workers redirect stdout to `/dev/null`, so structured message
> passing is unavailable. Use `->runFn()` or a pool if you need `emit()`.

**Choosing between global helpers and fluent factories**

Use `parallelFn()` and `spawnFn()` for quick scripts where the defaults are
acceptable. Use `->runFn()` and `->spawnFn()` on the fluent executors whenever
you need consistent configuration, message handlers, or bootstrap logic applied
to every invocation — or when working with a pool and want to avoid constructing
a new executor on each call.

---

## Persistent Worker Pools

Worker pools maintain a fixed set of long-lived workers to eliminate the
overhead of repeated process spawning and framework bootstrapping. Each worker
handles multiple tasks sequentially, making pools ideal for high-throughput
workloads.
```php
use Hibla\Parallel\Parallel;
use function Hibla\await;

$pool = Parallel::pool(size: 4)
    ->withMaxExecutionsPerWorker(100)
    ->withMemoryLimit('128M');

$task = fn() => getmypid();

for ($i = 0; $i < 4; $i++) {
    $pool->run($task)->then(fn($pid) => print("Handled by worker: $pid\n"));
}

/**
 * CRITICAL: Always shut down the pool when done.
 * Persistent workers hold open IPC channels that keep the Event Loop alive.
 * Without an explicit shutdown the script will never exit.
 */

// Option A: Synchronous — blocks until all queued tasks finish and all workers exit
$pool->shutdown();

// Option B: Graceful- rejects all incoming task while still waiting for current task to finish and then shutdown
// $pool->drain();
```

### Automatic garbage collection between tasks

After every completed task, before writing the next `READY` frame, each
persistent worker automatically calls `gc_collect_cycles()` to collect any
reference cycles left behind by the task. This runs unconditionally regardless
of any other configuration and provides a baseline level of memory hygiene at
no cost to the caller.

For most workloads this is sufficient. If a task leaves behind significant heap
allocations, large static caches, or framework state that the garbage collector
cannot reclaim — which `gc_collect_cycles()` alone cannot address — use
`withMaxExecutionsPerWorker()` to retire and replace workers at a predictable
cadence.

### Why `withMaxExecutionsPerWorker`?

Long-running pools can accumulate memory over time even with cycle collection
in place. PHP's OPcache grows, framework static caches fill up, and residual
heap allocations may persist across tasks. By retiring a worker after N tasks
and replacing it with a fresh process, you get a fully clean memory slate at a
predictable cadence — important for pools that run for hours or days.

### Lazy vs. Eager Spawning

By default, pools use **eager spawning**—all workers are spawned together when the pool manager is initialized. However, the pool manager itself is initialized lazily on the first `run()` call. This means even with eager spawning (the default), the first task submitted to the pool will incur the full cost of booting every worker process unless `boot()` is called explicitly beforehand.

#### Pre-warming with `boot()`

The `boot()` method pre-warms the pool and guarantees that every worker is genuinely ready to accept tasks before it returns. It is **context-aware**: if called inside an async fiber, it suspends the fiber without blocking the event loop; if called in a synchronous script, it blocks execution and pumps the event loop until the pool is ready.

Once `boot()` returns:
- **Zero Spawn Latency:** Every worker has completed its bootstrap phase (autoloader, framework initialization, etc.). The first `run()` call will dispatch to an idle worker instantly.
- **Accurate PIDs:** `getWorkerPids()` is guaranteed to return the real PHP process PIDs (from `getmypid()` inside the worker), not temporary shell wrapper PIDs.
- **Idempotency:** Calling `boot()` multiple times is a safe no-op.

**Synchronous Usage:**
```php
$pool = Parallel::pool(size: 4)->boot(); 
// Blocks until all 4 workers send their READY frame.

$result = await($pool->run(fn() => heavyWork()));
```

**Asynchronous Usage:**
```php
async(function() use ($pool) {
    $pool->boot(); // Suspends this fiber non-blocking
    $result = await($pool->run(fn() => heavyWork()));
});
```

#### Lazy Spawning

Use **lazy spawning** when the pool is conditional or short-lived and workers may never be needed. Workers are spawned one-by-one only as tasks are submitted.

```php
$pool = Parallel::pool(size: 4)->withLazySpawning();
```

Calling `boot()` on a lazy pool forces the manager to initialize, but workers still spawn individually on each `run()` call. For lazy pools, `boot()` returns immediately since there is no pre-boot phase.

**The trade-off:** The first batch of tasks will incur worker boot latency (~50–100ms per worker), and bootstrap errors (like syntax errors in your bootstrap file) will surface during task submission rather than during pool setup.

---

## Self-Healing & Supervisor Pattern

Build Erlang-style supervised clusters. If a worker crashes for any reason —
segfault, out-of-memory kill, or an explicit `exit()` — Hibla detects the
death, immediately spawns a replacement worker to maintain pool capacity, and
fires the `onWorkerRespawn` hook so you can re-submit whatever that worker was
doing.

**`onWorkerRespawn` fires on both crashes and planned retirements.**

When a worker reaches its `withMaxExecutionsPerWorker()` limit it exits cleanly
with a RETIRING frame rather than a crash signal, but the pool treats both
events identically from the caller's perspective: a replacement worker is
spawned and `onWorkerRespawn` is called. This means any long-running task that
was running on a retiring worker needs to be re-submitted in the hook just as
it would after a crash. If you use `withMaxExecutionsPerWorker()` without an
`onWorkerRespawn` handler, long-running tasks submitted to that worker will not
be re-submitted on retirement.

The pattern is three lines:
```php
use Hibla\Parallel\Parallel;
use Hibla\Parallel\Interfaces\ProcessPoolInterface;

$pool = Parallel::pool(size: 4)
    ->withoutTimeout() // long-running workers must not have a timeout
    ->onWorkerRespawn(function (ProcessPoolInterface $pool) use ($serverTask) {
        // Fired every time a worker exits — whether from a crash or a planned
        // retirement via withMaxExecutionsPerWorker(). Re-submit your
        // long-running task to the replacement worker here.
        $pool->run($serverTask);
    });
```

### Complete example: chaos server

The following example makes the supervisor behavior directly observable. Each
worker binds to port 8080 via `SO_REUSEPORT` and serves HTTP requests. The
`/crash` route lets you trigger a controlled worker crash from the browser and
watch the master detect and recover from it in the terminal in real time.
```php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Hibla\Parallel\Interfaces\ProcessPoolInterface;
use Hibla\Parallel\Parallel;
use Hibla\Socket\SocketServer;

// Define the router as an anonymous class so it can be captured by the
// closure and serialized to each worker via opis/closure. Each worker
// receives a full copy of the class definition and instantiates it locally.
// In a real application this would be a named autoloadable class instead.
$routerClass = new class () {
    private array $static = [];

    public function get(string $path, callable $handler): void
    {
        $this->static['GET'][$path] = $handler;
    }

    public function dispatch(string $method, string $uri): string
    {
        return isset($this->static[$method][$uri])
            ? ($this->static[$method][$uri])()
            : '404 Not Found';
    }
};

// This closure is the long-running task submitted to each worker.
// It never returns — it starts a socket server and runs indefinitely.
// Hibla workers have their own event loop so this is valid:
// the worker runs its server loop cooperatively rather than blocking.
$serverTask = function () use ($routerClass) {
    $pid    = getmypid();
    $router = new $routerClass();

    $router->get('/', function () use ($pid) {
        return "[Worker $pid] Hello! I am healthy and serving requests.";
    });

    // Visit http://127.0.0.1:8080/crash in a browser to trigger a crash.
    // The delay(0.1) lets the HTTP response flush before exit() fires —
    // without it the connection would close mid-response and the browser
    // would show a connection reset error instead of the acknowledgment.
    $router->get('/crash', function () use ($pid) {
        echo "[Worker $pid] Received suicide command! Crashing now...\n";
        Hibla\delay(0.1)->then(fn () => exit(1));

        return "[Worker $pid] Acknowledged. I am dying. Goodbye world.";
    });

    // SO_REUSEPORT allows multiple processes to bind to the same port.
    // The kernel load-balances incoming connections across all bound
    // workers at the TCP accept level — zero application coordination.
    $server = new SocketServer('127.0.0.1:8080', [
        'tcp' => ['so_reuseport' => true, 'backlog' => 65535],
    ]);

    $server->on('connection', function ($connection) use ($router) {
        $connection->on('data', function (string $rawRequest) use ($connection, $router) {
            $firstLine = substr($rawRequest, 0, strpos($rawRequest, "\r\n"));
            $parts     = explode(' ', $firstLine);
            $method    = $parts[0] ?? 'GET';
            $uri       = explode('?', $parts[1] ?? '/')[0];

            $content  = $router->dispatch($method, $uri);
            $response = "HTTP/1.1 200 OK\r\n"
                . "Content-Type: text/plain\r\n"
                . "Content-Length: " . strlen($content) . "\r\n"
                . "\r\n"
                . $content;

            $connection->write($response);
        });
    });

    echo "[Worker $pid] Started listening on 8080\n";
};

$poolSize = 4;

$pool = Parallel::pool(size: $poolSize)
    ->withoutTimeout() // server workers run indefinitely — no timeout
    ->onWorkerRespawn(function (ProcessPoolInterface $pool) use ($serverTask) {
        // A worker crashed or retired. The pool has already spawned a
        // replacement — re-submit the server task to put it to work.
        echo "\n[Master] ALERT: Worker process exited! Triggering onWorkerRespawn hook...\n";
        echo "[Master] Re-submitting Socket Server Task to the replacement worker.\n\n";

        $pool->run($serverTask);
    });

echo "--- Hibla Parallel: Chaos Web Server Test ---\n";
echo "[Master PID: " . getmypid() . "] Supervising $poolSize workers...\n\n";

for ($i = 0; $i < $poolSize; $i++) {
    // The catch() silences ProcessCrashedException on the initial submissions.
    // When a worker crashes its in-flight promise rejects with that exception —
    // it is expected and already handled by onWorkerRespawn above, so there
    // is nothing to act on here at the call site.
    $pool->run($serverTask)->catch(fn (ProcessCrashedException $e) => null);
}
```

Run it with `php chaos_server.php` and visit `http://127.0.0.1:8080/crash` in
a browser to trigger a crash. The terminal output shows the full lifecycle:
```
--- Hibla Parallel: Chaos Web Server Test ---
[Master PID: 6309] Supervising 4 workers...

[Worker 6311] Started listening on 8080
[Worker 6318] Started listening on 8080
[Worker 6317] Started listening on 8080
[Worker 6315] Started listening on 8080

[Worker 6315] Received suicide command! Crashing now...

[Master] ALERT: Worker process exited! Triggering onWorkerRespawn hook...
[Master] Re-submitting Socket Server Task to the replacement worker.

[Worker 6406] Started listening on 8080
```

Three things to observe in this output:

The master PID never changes — `6309` supervises the entire session. It is not
affected by any worker crash or retirement.

Every exit is followed immediately by a new worker PID coming online. The pool
never drops below 4 listening workers. From the perspective of any HTTP client
hitting port 8080, the cluster is healthy throughout.

You can hit `/crash` as many times as you like. The cluster recovers every time
with no manual intervention and no restart of the master process.

---

## Respawn Rate Limiting

By default, the pool will respawn a crashed worker immediately and without
limit. This is the right behavior for isolated, one-off crashes — a bad task
payload, a transient fault, or a single worker OOM kill. The replacement is up
and running before the next task even notices.

However, if the root cause is not transient — a broken bootstrap file, a fatal
error in every task, or an unserializable payload submitted in a loop — every
replacement worker will crash for the exact same reason. Without a circuit
breaker, the pool enters an uncontrolled crash loop: spawning, crashing, and
re-spawning at full speed until either the OS runs out of resources or the
process table fills up.

`withMaxRestartPerSecond()` is that circuit breaker. It counts worker respawns
in a one-second sliding window and shuts the pool down with a
`RespawnRateLimitException` the moment the threshold is crossed:
```php
use Hibla\Parallel\Parallel;
use Hibla\Parallel\Exceptions\RespawnRateLimitException;
use function Hibla\await;

$pool = Parallel::pool(size: 4)
    ->withMaxRestartPerSecond(5);

try {
    await($pool->run(fn() => riskyWork()));
} catch (RespawnRateLimitException $e) {
    // Pool detected a crash loop and shut itself down.
    // All pending and queued tasks have been rejected.
    echo $e->getMessage();
}
```

### Fail-fast, not slow-fail

When the limit is exceeded, the pool does not pause and retry — it shuts down
immediately and rejects every pending promise. This is intentional.

A rapid crash loop is almost never a transient problem. The 5th respawn will
fail for the exact same reason as the 1st. Slowing down the respawn rate with
backoff just delays the inevitable while leaving the task queue growing with
unprocessed work and giving callers false hope that the pool is still
functional. Failing fast means:

- Every caller is notified immediately via a rejected promise
- No resources are wasted on doomed respawns
- The root cause surfaces sooner rather than being obscured by retry noise

### Choosing the right limit

The library does not set a default. Your workload determines what "too many
restarts" looks like, and a wrong default is worse than no default — too tight
and you kill a healthy pool, too loose and the protection is meaningless.

Use these factors to guide your choice:

| Factor | Consideration |
| :--- | :--- |
| **Pool size** | A pool of 8 workers retiring via `withMaxExecutionsPerWorker` will naturally respawn more often than a pool of 2. Account for planned retirements. |
| **Task duration** | Short tasks cycle workers faster. A limit that works for 10-second tasks may be too tight for 50ms tasks with high throughput. |
| **Expected crash rate** | If occasional worker crashes are normal for your workload, set the limit above your expected noise floor. |
| **Environment stability** | Development environments with frequent code changes crash more often than production. Consider separate configs. |

A reasonable starting point for most production pools is `3`–`5`. This
comfortably absorbs occasional one-off crashes while still catching a true
crash loop within a second.
```php
// Conservative — any sustained crash loop trips immediately
$pool = Parallel::pool(size: 4)
    ->withMaxRestartPerSecond(3);

// More tolerant — useful for larger pools or higher-churn workloads  
$pool = Parallel::pool(size: 16)
    ->withMaxRestartPerSecond(10);
```

### Interaction with `onWorkerRespawn`

`onWorkerRespawn` fires on every successful respawn — including the ones counted
toward the rate limit. Once the limit is exceeded and the pool shuts down, the
hook will no longer fire. Design your respawn handler accordingly: do not assume
it will always be called, and do not use it as the sole mechanism for detecting
a crash loop.
```php
$pool = Parallel::pool(size: 4)
    ->withMaxRestartPerSecond(5)
    ->onWorkerRespawn(function (ProcessPoolInterface $pool) use ($task) {
        // This fires for each individual respawn within the limit.
        // It will NOT fire once RespawnRateLimitException shuts the pool down.
        $pool->run($task);
    });
```

> **Note:** `withMaxRestartPerSecond` is opt-in and `null` by default. If you
> do not set it, the pool will respawn crashed workers indefinitely with no
> rate check. For any long-running pool in production, setting an explicit limit
> is strongly recommended.

---

### Pool Monitoring

Inspect a live pool to understand its current state. Useful for health checks, dashboards, and debugging.
```php
use Hibla\Parallel\Parallel;
use function Hibla\await;

// Number of worker processes currently alive
$pool = Parallel::pool(size: 4);
echo $pool->getWorkerCount(); // 4
```

#### PID accuracy and `boot()`

`getWorkerPids()` returns the real PHP process PIDs as self-reported by each worker in its `READY` frame. These match exactly what `getmypid()` returns inside a running task on every platform, including Windows.

Because workers go through a "Shell Wrapper" phase before the PHP binary fully initializes, calling `getWorkerPids()` immediately after constructing a pool may return temporary PIDs. 

To guarantee PID accuracy for monitoring or health checks, **always call `boot()` first**. Because `boot()` now waits for every worker to signal readiness, the PIDs are guaranteed to be authoritative the moment the method returns:

```php
// Workers are guaranteed to have sent READY frames — PIDs are authoritative
$pool = Parallel::pool(size: 4)->boot();

// These PIDs are accurate and match getmypid() inside tasks
$pids = $pool->getWorkerPids(); // [12345, 12346, 12347, 12348]

$pool->shutdown();
```

---

## Real-time Output & Messaging

### Console Streaming

Everything printed inside a worker using `echo`, `print`, or any function that
writes through PHP's output buffer is captured and streamed to the parent
console in real time via non-blocking frames:
```php
use function Hibla\parallel;

parallel(function () {
    echo "Starting process...\n";
    sleep(1);
    echo "50% complete...\n";
    sleep(1);
    echo "Finished!\n";
});
// Parent sees each line as it is printed
```

**Direct writes to `STDOUT` are not captured.**

Hibla intercepts output by installing an output buffer handler (`ob_start`)
inside the worker. That handler only captures what flows through PHP's output
buffering layer — `echo`, `print`, `printf`, `var_dump`, `print_r`, and similar
functions all go through it. Calls that bypass the buffer and write directly to
the file descriptor — `fwrite(STDOUT, ...)`, `fputs(STDOUT, ...)` — are not
intercepted and will not reach the parent.
```php
use function Hibla\parallel;

parallel(function () {
    // Will not reach the parent — bypasses the output buffer entirely
    fwrite(STDOUT, "progress update\n");

    // Will reach the parent — goes through the output buffer
    echo "progress update\n";
});
```

If you need to send structured data back to the parent rather than raw console
output, use `emit()` with an `onMessage` handler instead:
```php
use Hibla\Parallel\Parallel;
use function Hibla\emit;

Parallel::task()
    ->onMessage(function ($msg) {
        echo "Worker says: {$msg->data}\n";
    })
    ->run(function () {
        emit("progress update");
    });
```

See [Structured Messaging with `emit()`](#structured-messaging-with-emit) for
the full messaging API.

### Structured Messaging with `emit()`

Use `emit()` to send structured data back to the parent without finishing the
task. The parent receives a `WorkerMessage` object with `$msg->data` containing
the emitted value and `$msg->pid` containing the worker's process ID.

`emit()` supports any serializable PHP value — scalars, arrays, and objects all
cross the process boundary transparently. This means you can emit typed value
objects and use `instanceof` checks in your `onMessage` handler to branch on
message type, giving you a clean and expressive messaging protocol without
string-based type flags:
```php
use Hibla\Parallel\Parallel;
use App\Messages\ProgressUpdate;
use App\Messages\ValidationError;
use App\Messages\StageComplete;
use function Hibla\emit;

Parallel::task()
    ->onMessage(function ($msg) {
        if ($msg->data instanceof ProgressUpdate) {
            printf("Worker %d: %d%% complete\n", $msg->pid, $msg->data->percent);
        } elseif ($msg->data instanceof ValidationError) {
            printf("Validation failed on row %d: %s\n", $msg->data->row, $msg->data->reason);
        } elseif ($msg->data instanceof StageComplete) {
            printf("Stage '%s' finished in %.2fs\n", $msg->data->name, $msg->data->duration);
        }
    })
    ->run(function () {
        emit(new ProgressUpdate(percent: 25));

        foreach (loadRows() as $i => $row) {
            if (! validate($row)) {
                emit(new ValidationError(row: $i, reason: 'missing required field'));
                continue;
            }
            process($row);
        }

        emit(new StageComplete(name: 'processing', duration: 1.42));
        emit(new ProgressUpdate(percent: 100));
    });
```

For this to work, the message classes must be autoloaded in both the worker and
the parent — define them in your application's namespace and let Composer handle
the rest.

> **Note:** Calling `emit()` inside a fire-and-forget worker spawned via
> `spawn()` or `spawnFn()` is a silent no-op. Those workers redirect stdout to
> `/dev/null`, so message passing is unavailable. Use `parallel()` or a pool if
> you need `emit()`.

### Pool-Level vs. Per-Task Message Handlers

You can register message handlers at two levels. They compose without conflict
— the per-task handler fires first, followed by the pool-level handler, and
both run concurrently as async fibers:
```php
$pool = Parallel::pool(size: 4)
    ->onMessage(function ($msg) {
        // Pool-level handler — fires for every task's messages.
        // Use this for cross-cutting concerns: logging, metrics, dashboards.
        Logger::info("Worker {$msg->pid} emitted", ['data' => $msg->data]);
    });

$pool->run(
    function () {
        Hibla\emit(new ProgressUpdate(percent: 50));
        Hibla\emit(new ProgressUpdate(percent: 100));
    },
    onMessage: function ($msg) {
        // Per-task handler — fires only for this task's messages, before pool-level.
        if ($msg->data instanceof ProgressUpdate) {
            echo "This task is {$msg->data->percent}% done\n";
        }
    }
);

$pool->shutdown();
```

The same two-level composition is available on `Parallel::task()` using
`->onMessage()` for the executor level and the second argument to `->run()`
for the per-task level.

### Handler completion blocks task promise resolution

The parent does not resolve the task promise — and therefore does not return
from `await(parallel(...))` — until every registered `onMessage` handler has
finished executing. All handlers start concurrently as async fibers, so the
total wall-clock cost equals the slowest handler, not the sum of all handlers.
But if any single handler does slow I/O, a blocking database write, or a
long-running computation, it delays the parent receiving the task result even
if the worker itself finished long ago.
```php
// This handler delays every task result by at least 2 seconds,
// regardless of how fast the worker finished.
$pool = Parallel::pool(size: 4)
    ->onMessage(function ($msg) {
        sleep(2); // blocks the fiber — parent waits here before resolving
    });
```

Keep `onMessage` handlers fast. If you need to do slow work in response to a
message — writing to a database, calling an external API — dispatch it as a
background task from inside the handler rather than doing it inline:
```php
$pool = Parallel::pool(size: 4)
    ->onMessage(function ($msg) {
        // Dispatches the slow work and returns immediately.
        // The handler fiber completes without waiting for the write.
        spawn(function () use ($msg) {
            Database::insertMetric($msg->data);
        });
    });
```

---

## Fractal Concurrency: The Async Hybrid

Hibla Parallel provides a unified concurrency model. While `async/await` handles
non-blocking I/O, `parallel()` offloads actual blocking PHP functions (like
`sleep()`, legacy database drivers, or CPU-heavy work) to separate processes.

This is not limited to the `parallel()` helper. Every execution strategy —
`Parallel::task()`, `Parallel::pool()`, `Parallel::background()`, and their
`runFn`/`spawnFn` factory variants — participates in the same model. You can
freely mix pool tasks, one-off tasks, background spawns, and fiber-based async
work in a single `Promise::all()` and they all run concurrently, each
contributing its result when ready.

Because every worker is a smart worker with its own event loop, you can also
mix these models recursively until the maximum process nesting limit — a worker
can itself spawn further parallel tasks or run async fibers internally. The
following block demonstrates this and finishes in exactly 1 second:
```php
use Hibla\Parallel\Parallel;
use Hibla\Promise\Promise;
use function Hibla\{parallel, async, await, delay};

$pool = Parallel::pool(size: 2);

$start = microtime(true);

Promise::all([
    // One-off task via global helper
    parallel(fn() => sleep(1)),

    // One-off task via fluent executor
    Parallel::task()->run(fn() => sleep(1)),

    // Pool task — dispatched to a persistent worker
    $pool->run(fn() => sleep(1)),

    // Worker that internally mixes parallel tasks with async fibers
    parallel(function () {
        await(Promise::all([
            async(fn() => await(delay(1))),
            async(fn() => await(delay(1))),
        ]));
        return "Hybrid Done";
    }),

    // Pure fiber-based async — no process spawn at all
    async(fn() => await(delay(1))),
])->wait();

$pool->shutdown();

$duration = microtime(true) - $start;
echo "Executed ~5 seconds of work in: {$duration} seconds!";
// Output: Executed ~5 seconds of work in: 1.04 seconds!
```

The event loop does not care whether a given unit of work is a process-backed
promise from a pool worker or a fiber-backed promise from `async()` — it drives
all of them forward concurrently on the same tick cycle. The result is a single
composable concurrency model rather than two separate systems you have to
coordinate manually.

---

## Nested Execution & Safety

Workers are real OS processes and each one can itself call `parallel()`,
`spawn()`, or any `Parallel::` executor to spawn further child processes. Hibla
enforces a configurable nesting limit (default 5) to prevent runaway recursive
spawning — a fork bomb — from exhausting system resources. Cancellation
propagates through the full tree — cancelling a parent worker also terminates
all nested workers it spawned, at any depth, on both Linux and Windows.

### The nested closure problem

Hibla serializes closures using
[opis/closure](https://github.com/opis/closure), which locates a closure by
its **line number** in the source file. When two `parallel()` calls share the
same source line, the serializer extracts the outer closure instead of the inner
one. The child worker deserializes and runs the outer call — spawning another
outer process — which does the same thing again indefinitely. This is a fork
bomb.

Beyond the fork bomb risk, short closures (`fn() => ...`) implicitly capture
the **entire parent scope by value**. In a large application this can silently
serialize megabytes of unintended data into the task payload.

Hibla automatically detects the most dangerous patterns and throws a
`NestingLimitException` before any process is spawned. The examples below show
what to avoid and what to use instead, ordered from most dangerous to safest.

### Closure safety levels

**DANGEROUS — single-line nested short closures**

The serializer sees one line, extracts the outer closure, and the worker
re-spawns the outer call infinitely:
```php
parallel(fn() => await(parallel(fn() => doWork())));
spawn(fn() => await(spawn(fn() => doWork())));
```

**RISKY — multi-line nested short closures**

Splitting across lines avoids AST corruption, but `fn()` still captures the
entire parent scope. Auto-formatters (Pint, PHP-CS-Fixer) may also collapse
multi-line short closures back to one line on the next run:
```php
parallel(
    fn() => await(
        parallel(
            fn() => doWork()
        )
    )
);
```

**SAFER — regular closures with explicit `use`**

Only the variables you name are serialized. Nothing implicit, nothing surprising:
```php
parallel(function () {
    return await(parallel(function () {
        return doWork();
    }));
});
```

**SAFEST — non-closure callables (recommended for all nested tasks)**

Nothing to serialize beyond the class or function name. No AST parsing, no
scope capture. Classes and functions must be available via Composer autoloading:
```php
parallel([MyTask::class, 'run']);           // static method
parallel([new MyTask($id), 'execute']);     // instance method
parallel(new MyInvokableTask($id));         // __invoke class
parallel('App\Tasks\processItem');          // namespaced function
```

### Always `await()` nested calls

Always `await()` the result of a nested `parallel()` or `spawn()` call inside
a worker. An un-awaited nested task may be killed by the OS if the parent
worker exits first, and nesting limit enforcement depends on the promise being
tracked.

### Configuring the nesting limit

The default of 5 is sufficient for virtually all real workloads. If you
genuinely need deeper nesting, raise it per-executor or globally in config.
The hard ceiling is 10:
```php
// Per-executor
Parallel::task()
    ->withMaxNestingLevel(8)
    ->run(fn() => deeplyNestedWork());

// Global — hibla_parallel.php
'max_nesting_level' => 8,
```

---

## Distributed Exception Teleportation

Hibla Parallel teleports exceptions from workers back to the parent. It
re-instantiates the original exception type and merges stack traces so you see
exactly where the error originated:
```php
use function Hibla\{parallel, await};

try {
    await(parallel(function () {
        throw new \BadFunctionCallException("Database connection failed!");
    }));
} catch (\BadFunctionCallException $e) {
    echo $e->getMessage(); // "Database connection failed!"
    echo $e->getTraceAsString();
    /*
       #0 ParentCode.php: Line where parallel() was called
       #1 --- WORKER STACK TRACE ---
       #2 worker.php: Line where exception was thrown
    */
}
```

---

## Abnormal Termination Detection

If a worker hits a segmentation fault, runs out of memory, or calls `exit()`,
Hibla Parallel detects the silent death and rejects the promise with a
`ProcessCrashedException`:
```php
use Hibla\Parallel\Exceptions\ProcessCrashedException;
use function Hibla\{parallel, await};

try {
    await(parallel(fn() => exit(1)));
} catch (ProcessCrashedException $e) {
    echo "Alert: Worker crashed unexpectedly!";
}
```

---

## Task Cancellation & Management

Cancelling a task promise forcefully kills the underlying OS process **and its
entire descendant tree** — including any nested `parallel()` workers spawned
inside the cancelled worker. For pools, a fresh replacement worker is
immediately respawned to maintain capacity.
```php
use Hibla\Parallel\Parallel;

$pool = Parallel::pool(size: 4);
$promise = $pool->run(fn() => sleep(100));

$promise->cancel();
// 1. Hibla kills the OS process and all its descendants via full tree kill.
// 2. The pool respawns a fresh replacement worker.
// 3. The promise is settled as cancelled.

$pool->shutdown();
```

### Full process tree cancellation

Cancellation is not limited to the direct worker. If a worker itself spawned
nested `parallel()` calls — which is fully supported up to `max_nesting_level`
— all of those child and grandchild processes are terminated together with
their parent. No orphaned workers survive a cancel.
```php
$promise = parallel(function () {
    // These nested workers are also killed if the parent is cancelled
    await(Promise::all([
        parallel(fn() => writeToDatabase()),
        parallel(fn() => writeToDatabase()),
    ]));
});

$promise->cancel();
// The parent worker AND both nested workers are all terminated.
// Neither database write completes — cancellation is a hard guarantee.
```

This matters because a cancellation API that does not propagate to descendants
is not a real cancellation — the work continues in orphaned background processes
with no owner, no handle, and no way to stop them. Hibla treats full tree kill
as non-negotiable.

### Cancellation in a pool kills the entire worker, not just the task

When you cancel a task promise, Hibla Parallel terminates the entire worker process for
that slot — not just the in-flight task. The cancelled task is killed immediately
and the worker process is torn down along with any nested children it spawned.
A fresh replacement worker is spawned to restore pool capacity, and any tasks
still waiting in the pool's global queue are dispatched to workers as normal —
they are not affected by the cancellation.

The distinction matters for one specific case: if the cancelled task was
mid-execution and the worker held any state that other code depended on, that
state is gone with the process. The cancellation blast radius is one worker slot,
its single in-flight task, and any nested workers that task had spawned —
nothing wider.

For one-off tasks spawned via `Parallel::task()`, cancellation only kills that
single worker tree and has no wider effect.

---

## Exception Reference

All exceptions extend `Hibla\Parallel\Exceptions\ParallelException`, which
extends `\RuntimeException`. Catch the base class to handle any Hibla-specific
error generically, or catch specific types for granular handling.

| Exception Class | When it is thrown |
| :--- | :--- |
| `ProcessCrashedException` | A worker exits unexpectedly (segfault, `exit()`, OOM kill, stream EOF) |
| `TimeoutException` | A task exceeds its configured timeout |
| `NestingLimitException` | A `parallel()` call would exceed `max_nesting_level` |
| `RateLimitException` | More than `spawn_limit_per_second` background tasks are spawned in one second |
| `PoolShutdownException` | A task is submitted to (or was queued in) a pool that has been shut down |
| `TaskPayloadException` | The task callback cannot be serialized, or the JSON payload encoding fails |
| `ProcessSpawnException` | The OS fails to spawn a new process, or required functions are disabled in `php.ini` |
| `RateLimitException` | More than `max_restart_per_second` workers are respawned in one second |
```php
use Hibla\Parallel\Exceptions\{
    ParallelException,
    ProcessCrashedException,
    TimeoutException,
};
use function Hibla\{parallel, await};

try {
    await(parallel(fn() => riskyWork()));
} catch (TimeoutException $e) {
    // Task ran too long
} catch (ProcessCrashedException $e) {
    // Worker died mid-task
} catch (ParallelException $e) {
    // Any other Hibla error
}
```

---

## Framework Bootstrapping

Load Laravel, Symfony, or any custom environment inside your workers:
```php
use Hibla\Parallel\Parallel;

// Laravel example
$pool = Parallel::pool(8)
    ->withBootstrap(__DIR__ . '/bootstrap/app.php', function (string $file) {
        $app = require $file;
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    });

$pool->run(fn() => config('app.name'));
```
```php
// Symfony example
$pool = Parallel::pool(8)
    ->withBootstrap(__DIR__ . '/config/bootstrap.php', function (string $file) {
        require $file;
    });
```

You can also set a system-wide default bootstrap so every task uses it
automatically — see [Global Configuration](#global-configuration).

---

## Autoloading & Code Availability

Hibla workers run in isolated PHP processes with a clean slate. Any code you
want to execute in a worker must be available to that process.

### Use namespaces and autoloading

The most reliable approach is to manage all classes and functions through
Composer's autoloader:
```php
use App\Tasks\HeavyTask;

parallel([HeavyTask::class, 'run']);
```

### Named functions vs. closures

Named functions (string callables like `'App\Tasks\myFunction'`) must exist in
the worker via the autoloader or a bootstrap file. Closures are serialized by
Hibla and transmitted to the worker — no autoloading is needed for the closure
code itself, but any classes or functions called inside it must still be
available:
```php
// Works without autoloader — the closure body is serialized and sent to the worker
parallel(function () {
    return "I am self-contained logic";
});
```

### Manual bootstrapping for legacy code

For legacy functions or global state that lives outside Composer's autoloader:
```php
use Hibla\Parallel\Parallel;

$pool = Parallel::pool(4)
    ->withBootstrap(__DIR__ . '/includes/legacy_functions.php');

$pool->run(fn() => legacy_calculate());
$pool->shutdown();
```

---

## Global Configuration

Create a `hibla_parallel.php` in your project root to set system-wide defaults.
Every value can be overridden per-task or per-pool using the fluent API.

The quickest way to get started is to publish the pre-commented config file
directly from the package:
```bash
cp vendor/hiblaphp/parallel/hibla_parallel.php hibla_parallel.php
```

Then open the file and adjust the values for your environment. Alternatively,
create it from scratch:
```php
// hibla_parallel.php
use function Rcalicdan\env;

return [
    /*
    |--------------------------------------------------------------------------
    | Maximum Nesting Level
    |--------------------------------------------------------------------------
    | The maximum number of parallel() calls that can be nested inside each
    | other. Acts as a fork-bomb safety valve.
    |
    | .env variable: HIBLA_PARALLEL_MAX_NESTING_LEVEL
    */
    'max_nesting_level' => env('HIBLA_PARALLEL_MAX_NESTING_LEVEL', 5),

    /*
    |--------------------------------------------------------------------------
    | Standard Process Settings (parallel() / Parallel::task() / Parallel::pool())
    |--------------------------------------------------------------------------
    | .env variables:
    |   HIBLA_PARALLEL_PROCESS_MEMORY_LIMIT
    |   HIBLA_PARALLEL_PROCESS_TIMEOUT
    */
    'process' => [
        'memory_limit' => env('HIBLA_PARALLEL_PROCESS_MEMORY_LIMIT', '512M'),
        'timeout'      => env('HIBLA_PARALLEL_PROCESS_TIMEOUT', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Background Process Settings (spawn() / Parallel::background())
    |--------------------------------------------------------------------------
    | 'spawn_limit_per_second': Prevents fork bombs by capping how many
    |   background tasks can be spawned per second. Default is 50.
    |
    | .env variables:
    |   HIBLA_PARALLEL_BACKGROUND_PROCESS_MEMORY_LIMIT
    |   HIBLA_PARALLEL_BACKGROUND_PROCESS_TIMEOUT
    |   HIBLA_PARALLEL_BACKGROUND_SPAWN_LIMIT
    */
    'background_process' => [
        'memory_limit'           => env('HIBLA_PARALLEL_BACKGROUND_PROCESS_MEMORY_LIMIT', '512M'),
        'timeout'                => env('HIBLA_PARALLEL_BACKGROUND_PROCESS_TIMEOUT', 600),
        'spawn_limit_per_second' => env('HIBLA_PARALLEL_BACKGROUND_SPAWN_LIMIT', 50, true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Framework Bootstrap Configuration
    |--------------------------------------------------------------------------
    | Set a default bootstrap so every worker automatically loads your
    | framework without calling withBootstrap() on every executor.
    |
    | Laravel example:
    | 'bootstrap' => [
    |     'file'     => __DIR__ . '/bootstrap/app.php',
    |     'callback' => function(string $bootstrapFile) {
    |         $app = require $bootstrapFile;
    |         $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    |     },
    | ],
    |
    | Symfony example:
    | 'bootstrap' => [
    |     'file'     => __DIR__ . '/config/bootstrap.php',
    |     'callback' => function(string $bootstrapFile) {
    |         require $bootstrapFile;
    |     },
    | ],
    */
    'bootstrap' => null,
];
```

---

## Architecture & Testability

Hibla Parallel is designed with testability in mind. The `Parallel` facade
provides a clean entry point to three independent strategies, each backed by a
dedicated interface and a concrete implementation.

### Strategy Map

| Facade Method | Concrete Class | Interface | Description |
| :--- | :--- | :--- | :--- |
| `Parallel::task()` | `ParallelExecutor` | `ParallelExecutorInterface` | One-off tasks that return a result. A fresh worker is spawned per `run()` call. |
| `Parallel::pool(n)` | `ProcessPool` | `ProcessPoolInterface` | A managed cluster of reusable persistent workers. |
| `Parallel::background()` | `BackgroundExecutor` | `BackgroundExecutorInterface` | Detached fire-and-forget processes. No result is returned. |

### Using the Facade (Recommended)
```php
use Hibla\Parallel\Parallel;
use function Hibla\await;

$result = await(Parallel::task()->run(fn() => "Facade result"));
```

### Direct Instantiation (Best for DI)
```php
use Hibla\Parallel\ProcessPool;

$pool = new ProcessPool(size: 8);
$pool->run(fn() => "Direct instantiation result");
$pool->shutdown();
```

### Dependency Injection & Mocking
```php
use Hibla\Parallel\Interfaces\ParallelExecutorInterface;

class ReportGenerator
{
    public function __construct(
        private ParallelExecutorInterface $executor
    ) {}

    public function generate(array $data): mixed
    {
        return $this->executor->run(fn() => $this->heavyLogic($data));
    }
}

// In production
$generator = new ReportGenerator(Parallel::pool(4));

// In tests
$mock = Mockery::mock(ParallelExecutorInterface::class);
$mock->shouldReceive('run')->andReturn(Promise::resolved('mock_data'));
$generator = new ReportGenerator($mock);
```

---

## Credits

- **Serialization:** Built on the high-performance [rcalicdan/serializer](https://github.com/rcalicdan/serializer) and [opis/closure](https://github.com/opis/closure).
- **Philosophy:** Inspired by the Erlang/OTP fault-tolerance model.

---

## License

MIT License. See [LICENSE](./LICENSE) for more information.