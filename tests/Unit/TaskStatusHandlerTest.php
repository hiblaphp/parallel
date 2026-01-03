<?php

declare(strict_types=1);

use Hibla\Parallel\Handlers\TaskStatusHandler;

describe('TaskStatusHandler', function () {

    $getTempPath = fn () => sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hibla_status_test_' . uniqid();

    $cleanupDir = function (string $dir) {
        if (! is_dir($dir)) {
            return;
        }
        $files = scandir($dir);
        foreach ($files as $file) {
            if ($file !== '.' && $file !== '..') {
                unlink($dir . DIRECTORY_SEPARATOR . $file);
            }
        }
        rmdir($dir);
    };

    it('does nothing when logging is disabled', function () use ($getTempPath) {
        $tempPath = $getTempPath();

        $handler = new TaskStatusHandler($tempPath, loggingEnabled: false);

        $handler->createInitialStatus('task_disabled', fn () => true);

        expect(is_dir($tempPath))->toBeFalse();
    });

    it('automatically creates the log directory if missing', function () use ($getTempPath, $cleanupDir) {
        $tempPath = $getTempPath();

        $handler = new TaskStatusHandler($tempPath, loggingEnabled: true);
        $handler->createInitialStatus('task_dir_check', fn () => true);

        expect(is_dir($tempPath))->toBeTrue();

        $cleanupDir($tempPath);
    });

    it('writes the correct initial JSON structure', function () use ($getTempPath, $cleanupDir) {
        $tempPath = $getTempPath();
        $taskId = 'task_structure_test';

        $handler = new TaskStatusHandler($tempPath, loggingEnabled: true);
        $handler->createInitialStatus($taskId, fn () => 'test');

        $filePath = $tempPath . DIRECTORY_SEPARATOR . $taskId . '.json';

        expect(file_exists($filePath))->toBeTrue();

        $content = json_decode(file_get_contents($filePath), true);

        expect($content)
            ->toBeArray()
            ->toHaveKeys(['task_id', 'status', 'callback_type', 'created_at', 'timestamp'])
            ->and($content['task_id'])->toBe($taskId)
            ->and($content['status'])->toBe('PENDING')
            ->and($content['message'])->toBe('Task created and queued for execution')
            ->and($content['pid'])->toBeNull()
        ;

        $cleanupDir($tempPath);
    });

    describe('Callback Type Detection', function () use ($getTempPath, $cleanupDir) {

        it('identifies Closures', function () use ($getTempPath, $cleanupDir) {
            $tempPath = $getTempPath();
            $taskId = 'type_closure';

            $handler = new TaskStatusHandler($tempPath, true);
            $handler->createInitialStatus($taskId, function () {});

            $content = json_decode(file_get_contents($tempPath . DIRECTORY_SEPARATOR . $taskId . '.json'), true);
            expect($content['callback_type'])->toBe('closure');

            $cleanupDir($tempPath);
        });

        it('identifies Function Strings', function () use ($getTempPath, $cleanupDir) {
            $tempPath = $getTempPath();
            $taskId = 'type_function';

            $handler = new TaskStatusHandler($tempPath, true);
            $handler->createInitialStatus($taskId, 'strtoupper');

            $content = json_decode(file_get_contents($tempPath . DIRECTORY_SEPARATOR . $taskId . '.json'), true);
            expect($content['callback_type'])->toBe('function');

            $cleanupDir($tempPath);
        });

        it('identifies Class Methods (Arrays)', function () use ($getTempPath, $cleanupDir) {
            $tempPath = $getTempPath();
            $taskId = 'type_method';

            $handler = new TaskStatusHandler($tempPath, true);
            $handler->createInitialStatus($taskId, [new DateTime(), 'format']);

            $content = json_decode(file_get_contents($tempPath . DIRECTORY_SEPARATOR . $taskId . '.json'), true);
            expect($content['callback_type'])->toBe('method');

            $cleanupDir($tempPath);
        });

        it('identifies Invokable Objects', function () use ($getTempPath, $cleanupDir) {
            $tempPath = $getTempPath();
            $taskId = 'type_object';

            $invokable = new class () {
                public function __invoke()
                {
                    return true;
                }
            };

            $handler = new TaskStatusHandler($tempPath, true);
            $handler->createInitialStatus($taskId, $invokable);

            $content = json_decode(file_get_contents($tempPath . DIRECTORY_SEPARATOR . $taskId . '.json'), true);
            expect($content['callback_type'])->toBe('callable_object');

            $cleanupDir($tempPath);
        });
    });
});
