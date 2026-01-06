<?php

use Hibla\Parallel\Handlers\ExceptionHandler;

describe('ExceptionHandler', function () {
    
    describe('createFromWorkerError', function () {
        
        it('creates a RuntimeException with basic error data', function () {
            $errorData = [
                'class' => \RuntimeException::class,
                'message' => 'Test error message',
                'code' => 123,
                'stack_trace' => ''
            ];

            $exception = ExceptionHandler::createFromWorkerError($errorData, 'unknown');

            expect($exception)
                ->toBeInstanceOf(\RuntimeException::class)
                ->and($exception->getMessage())->toBe('Test error message')
                ->and($exception->getCode())->toBe(123);
        });

        it('creates a custom exception type when class exists', function () {
            $errorData = [
                'class' => \InvalidArgumentException::class,
                'message' => 'Invalid argument provided',
                'code' => 456,
                'stack_trace' => ''
            ];

            $exception = ExceptionHandler::createFromWorkerError($errorData, 'unknown');

            expect($exception)
                ->toBeInstanceOf(\InvalidArgumentException::class)
                ->and($exception->getMessage())->toBe('Invalid argument provided')
                ->and($exception->getCode())->toBe(456);
        });

        it('falls back to RuntimeException when class does not exist', function () {
            $errorData = [
                'class' => 'NonExistentException',
                'message' => 'Some error',
                'code' => 0,
                'stack_trace' => ''
            ];

            $exception = ExceptionHandler::createFromWorkerError($errorData, 'unknown');

            expect($exception)
                ->toBeInstanceOf(\RuntimeException::class)
                ->and($exception->getMessage())->toBe('Some error');
        });

        it('uses default values when error data is incomplete', function () {
            $errorData = [];

            $exception = ExceptionHandler::createFromWorkerError($errorData, 'unknown');

            expect($exception)
                ->toBeInstanceOf(\RuntimeException::class)
                ->and($exception->getMessage())->toBe('Unknown error')
                ->and($exception->getCode())->toBe(0);
        });

        it('normalizes string code to integer', function () {
            $errorData = [
                'message' => 'Test',
                'code' => '789',
                'stack_trace' => ''
            ];

            $exception = ExceptionHandler::createFromWorkerError($errorData, 'unknown');

            expect($exception->getCode())->toBe(789);
        });

        it('sets exception file and line from source location', function () {
            $errorData = [
                'message' => 'Test error',
                'code' => 0,
                'stack_trace' => ''
            ];

            $sourceLocation = '/home/user/test.php:42';
            $exception = ExceptionHandler::createFromWorkerError($errorData, $sourceLocation);

            expect($exception->getFile())->toBe('/home/user/test.php')
                ->and($exception->getLine())->toBe(42);
        });

        it('handles source location without line number', function () {
            $errorData = [
                'message' => 'Test error',
                'code' => 0,
                'stack_trace' => ''
            ];

            $sourceLocation = '/home/user/test.php';
            $exception = ExceptionHandler::createFromWorkerError($errorData, $sourceLocation);

            expect($exception)->toBeInstanceOf(\Throwable::class);
        });

        it('does not modify exception when source location is unknown', function () {
            $errorData = [
                'message' => 'Test error',
                'code' => 0,
                'stack_trace' => ''
            ];

            $exception = ExceptionHandler::createFromWorkerError($errorData, 'unknown');

            expect($exception)->toBeInstanceOf(\Throwable::class);
        });

        it('appends worker stack trace to exception trace', function () {
            $errorData = [
                'message' => 'Test error',
                'code' => 0,
                'stack_trace' => '#0 /worker/path/file.php(10): testFunction()
                #1 /worker/path/another.php(20): anotherFunction()
                #2 {main}'
            ];

            $exception = ExceptionHandler::createFromWorkerError($errorData, '/parent/file.php:15');

            $trace = $exception->getTrace();

            expect($trace)->toBeArray();
            
            $hasSeparator = false;
            foreach ($trace as $frame) {
                if (isset($frame['file']) && $frame['file'] === '--- WORKER STACK TRACE ---') {
                    $hasSeparator = true;
                    break;
                }
            }
            
            expect($hasSeparator)->toBeTrue();
        });

        it('parses worker stack trace with file and line numbers', function () {
            $errorData = [
                'message' => 'Test error',
                'code' => 0,
                'stack_trace' => '#0 /worker/file.php(123): someFunction()
                #1 /worker/another.php(456): anotherFunction()'
            ];

            $exception = ExceptionHandler::createFromWorkerError($errorData, '/parent/file.php:10');

            $trace = $exception->getTrace();
            
            $foundWorkerTrace = false;
            $afterSeparator = false;
            
            foreach ($trace as $frame) {
                if (isset($frame['file']) && $frame['file'] === '--- WORKER STACK TRACE ---') {
                    $afterSeparator = true;
                    continue;
                }
                
                if ($afterSeparator && isset($frame['file']) && str_contains($frame['file'], '/worker/')) {
                    $foundWorkerTrace = true;
                    expect($frame)->toHaveKeys(['file', 'line', 'function', 'args']);
                }
            }
            
            expect($foundWorkerTrace)->toBeTrue();
        });

        it('handles worker stack trace with {main}', function () {
            $errorData = [
                'message' => 'Test error',
                'code' => 0,
                'stack_trace' => '#0 /worker/file.php(10): test()
                #1 {main}'
            ];

            $exception = ExceptionHandler::createFromWorkerError($errorData, '/parent/file.php:5');

            $trace = $exception->getTrace();
            
            $foundMain = false;
            foreach ($trace as $frame) {
                if (isset($frame['function']) && $frame['function'] === '{main}') {
                    $foundMain = true;
                    expect($frame['file'])->toBe('[worker main]');
                    break;
                }
            }
            
            expect($foundMain)->toBeTrue();
        });

        it('handles empty worker stack trace', function () {
            $errorData = [
                'message' => 'Test error',
                'code' => 0,
                'stack_trace' => ''
            ];

            $exception = ExceptionHandler::createFromWorkerError($errorData, '/parent/file.php:10');

            $trace = $exception->getTrace();
            
            foreach ($trace as $frame) {
                expect($frame['file'] ?? '')->not->toBe('--- WORKER STACK TRACE ---');
            }
        });

        it('preserves exception type for LogicException', function () {
            $errorData = [
                'class' => \LogicException::class,
                'message' => 'Logic error occurred',
                'code' => 100,
                'stack_trace' => ''
            ];

            $exception = ExceptionHandler::createFromWorkerError($errorData, 'unknown');

            expect($exception)->toBeInstanceOf(\LogicException::class);
        });

        it('handles malformed stack trace gracefully', function () {
            $errorData = [
                'message' => 'Test error',
                'code' => 0,
                'stack_trace' => 'Not a valid stack trace format
                 Random text here
                 More random stuff'
            ];

            $exception = ExceptionHandler::createFromWorkerError($errorData, '/parent/file.php:10');

            expect($exception)->toBeInstanceOf(\Throwable::class);
        });

        it('handles source location with multiple colons', function () {
            $errorData = [
                'message' => 'Test error',
                'code' => 0,
                'stack_trace' => ''
            ];

            $sourceLocation = 'C:/Users/test/file.php:25';
            $exception = ExceptionHandler::createFromWorkerError($errorData, $sourceLocation);

            expect($exception->getFile())->toBe('C:/Users/test/file.php')
                ->and($exception->getLine())->toBe(25);
        });

        it('creates exception with worker trace and parent location', function () {
            $errorData = [
                'class' => \RuntimeException::class,
                'message' => 'Worker failed',
                'code' => 500,
                'stack_trace' => '#0 /worker/process.php(50): execute()
                #1 /worker/bootstrap.php(100): run()
                #2 {main}'
            ];

            $exception = ExceptionHandler::createFromWorkerError(
                $errorData, 
                '/app/parallel.php:200'
            );

            expect($exception)
                ->toBeInstanceOf(\RuntimeException::class)
                ->and($exception->getMessage())->toBe('Worker failed')
                ->and($exception->getCode())->toBe(500)
                ->and($exception->getFile())->toBe('/app/parallel.php')
                ->and($exception->getLine())->toBe(200);

            $trace = $exception->getTrace();
            expect($trace)->toBeArray()->not->toBeEmpty();
        });
    });
});