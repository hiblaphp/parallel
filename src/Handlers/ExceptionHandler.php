<?php

declare(strict_types=1);

namespace Hibla\Parallel\Handlers;

/**
 * @internal 
 * 
 * Handles reconstruction and formatting of exceptions from worker processes
 */
final class ExceptionHandler
{
    /**
     * Reconstructs an exception from worker error data
     *
     * @param array<string, mixed> $errorData Error data from worker
     * @param string $sourceLocation Parent source location (file:line)
     * @return \Throwable Reconstructed exception with merged stack traces
     */
    public static function createFromWorkerError(array $errorData, string $sourceLocation): \Throwable
    {
        $className = $errorData['class'] ?? \RuntimeException::class;
        $message = $errorData['message'] ?? 'Unknown error';
        $codeValue = $errorData['code'] ?? 0;
        $workerTrace = $errorData['stack_trace'] ?? '';

        assert(\is_string($message));
        assert(\is_string($workerTrace));

        if (! \is_string($className)) {
            $className = \RuntimeException::class;
        }

        $code = self::normalizeExceptionCode($codeValue);
        $exception = self::instantiateException($className, $message, $code);

        self::setExceptionLocation($exception, $sourceLocation);

        if ($workerTrace !== '') {
            self::appendWorkerStackTrace($exception, $workerTrace);
        }

        return $exception;
    }

    /**
     * Normalize exception code to integer
     *
     * @param mixed $codeValue
     * @return int
     */
    private static function normalizeExceptionCode(mixed $codeValue): int
    {
        if (\is_int($codeValue)) {
            return $codeValue;
        }

        if (is_numeric($codeValue)) {
            return (int)$codeValue;
        }

        return 0;
    }

    /**
     * Instantiate the original exception type
     *
     * @param string $className
     * @param string $message
     * @param int $code
     * @return \Throwable
     */
    private static function instantiateException(string $className, string $message, int $code): \Throwable
    {
        if (! class_exists($className)) {
            return new \RuntimeException($message, $code);
        }

        try {
            $instance = new $className($message, $code);

            if ($instance instanceof \Throwable) {
                return $instance;
            }

            return new \RuntimeException($message, $code);
        } catch (\ArgumentCountError) {
            try {
                $instance = new $className($message);

                if ($instance instanceof \Throwable) {
                    return $instance;
                }

                return new \RuntimeException($message, $code);
            } catch (\Throwable) {
                return new \RuntimeException($message, $code);
            }
        } catch (\Throwable) {
            return new \RuntimeException($message, $code);
        }
    }

    /**
     * Set exception file and line from source location
     *
     * @param \Throwable $exception
     * @param string $sourceLocation Format: "file:line"
     * @return void
     */
    private static function setExceptionLocation(\Throwable $exception, string $sourceLocation): void
    {
        if ($sourceLocation === 'unknown' || ! str_contains($sourceLocation, ':')) {
            return;
        }

        try {
            [$file, $line] = self::parseSourceLocation($sourceLocation);

            $reflection = new \ReflectionObject($exception);
            $reflection = self::findReflectionWithProperty($reflection, 'file');

            if ($reflection === null) {
                return;
            }

            $fileProp = $reflection->getProperty('file');
            $fileProp->setAccessible(true);
            $fileProp->setValue($exception, $file);

            $lineProp = $reflection->getProperty('line');
            $lineProp->setAccessible(true);
            $lineProp->setValue($exception, (int)$line);
        } catch (\Throwable) {
            // Ignore reflection errors
        }
    }

    /**
     * Parse source location string into file and line
     *
     * @param string $sourceLocation
     * @return array{0: string, 1: string}
     */
    private static function parseSourceLocation(string $sourceLocation): array
    {
        $lastColonPos = strrpos($sourceLocation, ':');

        if ($lastColonPos !== false) {
            $file = substr($sourceLocation, 0, $lastColonPos);
            $line = substr($sourceLocation, $lastColonPos + 1);
        } else {
            $file = $sourceLocation;
            $line = '0';
        }

        return [$file, $line];
    }

    /**
     * Append worker stack trace to exception's trace array
     *
     * @param \Throwable $exception
     * @param string $workerTrace
     * @return void
     */
    private static function appendWorkerStackTrace(\Throwable $exception, string $workerTrace): void
    {
        try {
            $reflection = new \ReflectionObject($exception);
            $reflection = self::findReflectionWithProperty($reflection, 'trace');

            if ($reflection === null) {
                return;
            }

            $traceProp = $reflection->getProperty('trace');
            $traceProp->setAccessible(true);

            $currentTrace = $traceProp->getValue($exception);

            if (! \is_array($currentTrace)) {
                return;
            }

            $workerTraceArray = self::parseWorkerStackTrace($workerTrace);

            if (\count($workerTraceArray) === 0) {
                return;
            }

            $currentTrace[] = [
                'file' => '--- WORKER STACK TRACE ---',
                'line' => 0,
                'function' => '',
                'args' => []
            ];

            $currentTrace = array_merge($currentTrace, $workerTraceArray);
            $traceProp->setValue($exception, $currentTrace);
        } catch (\Throwable) {
            // Ignore reflection errors
        }
    }

    /**
     * Parse worker stack trace string into array format
     *
     * @param string $workerTrace
     * @return array<int, array<string, mixed>>
     */
    private static function parseWorkerStackTrace(string $workerTrace): array
    {
        $workerTraceLines = explode("\n", $workerTrace);
        $workerTraceArray = [];

        foreach ($workerTraceLines as $line) {
            $trimmedLine = trim($line);

            $matches = [];
            if (preg_match('/^#\d+\s+(.+?)(?:\((\d+)\))?: (.+)$/', $trimmedLine, $matches) === 1) {
                $workerTraceArray[] = [
                    'file' => $matches[1],
                    'line' => $matches[2] !== '' ? (int)$matches[2] : 0,
                    'function' => $matches[3],
                    'args' => []
                ];
            } elseif (preg_match('/^#\d+\s+\{main\}$/', $trimmedLine) === 1) {
                $workerTraceArray[] = [
                    'file' => '[worker main]',
                    'line' => 0,
                    'function' => '{main}',
                    'args' => []
                ];
            }
        }

        return $workerTraceArray;
    }

    /**
     * Find reflection class that has the specified property
     *
     * @param \ReflectionClass<object> $reflection
     * @param string $propertyName
     * @return \ReflectionClass<object>|null
     */
    private static function findReflectionWithProperty(\ReflectionClass $reflection, string $propertyName): ?\ReflectionClass
    {
        while ($reflection instanceof \ReflectionClass && ! $reflection->hasProperty($propertyName)) {
            $parentReflection = $reflection->getParentClass();
            $reflection = $parentReflection !== false ? $parentReflection : null;
        }

        return $reflection instanceof \ReflectionClass ? $reflection : null;
    }
}
