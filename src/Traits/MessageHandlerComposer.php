<?php

declare(strict_types=1);

namespace Hibla\Parallel\Traits;

use Hibla\Parallel\ValueObjects\WorkerMessage;
use Hibla\Promise\Promise;

use function Hibla\async;
use function Hibla\await;

trait MessageHandlerComposer
{
    /**
     * Composes an array of pool/executor-level handlers and an optional per-task
     * handler into a single callable.
     *
     * Pool/executor-level handlers fire in registration order first (outer middleware
     * layer), followed by the per-task handler last (inner application layer). All
     * handlers start concurrently via async() and are awaited together via Promise::all()
     * so the composed handler's fiber does not return until every sub-handler has
     * completed. Total wall-clock time equals the slowest handler, not the sum.
     *
     * The read loop tracks the composed handler promise and awaits it before resolving
     * the task promise — ensuring callers are never released before handlers finish.
     *
     * Returns null if no handlers are registered so the caller skips invocation entirely.
     *
     * @param array<int, callable(WorkerMessage): void> $outerHandlers Handlers registered
     *                                                                 via onMessage() in registration order. Fire first as the middleware layer.
     * @param callable(WorkerMessage): void|null $perTask Optional per-task handler passed
     *                                                    to run(). Fires last as the inner application layer.
     *
     * @return callable(WorkerMessage): void|null
     */
    private function composeMessageHandlers(array $outerHandlers, ?callable $perTask): ?callable
    {
        $hasOuter = \count($outerHandlers) > 0;

        if (! $hasOuter && $perTask === null) {
            return null;
        }

        if (! $hasOuter) {
            return $perTask;
        }

        if (\count($outerHandlers) === 1 && $perTask === null) {
            return $outerHandlers[0];
        }

        return static function (WorkerMessage $message) use ($outerHandlers, $perTask): void {
            $promises = [];

            foreach ($outerHandlers as $handler) {
                $promises[] = async(fn () => $handler($message));
            }

            if ($perTask !== null) {
                $promises[] = async(fn () => $perTask($message));
            }

            await(Promise::all($promises));
        };
    }
}
