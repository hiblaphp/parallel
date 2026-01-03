<?php

declare(strict_types=1);

use Hibla\Parallel\Utilities\SystemUtilities;
use Rcalicdan\ConfigLoader\Config;

describe('SystemUtilities', function () {
    describe('generateTaskId', function () {
        it('generates a task id with the correct format', function () {
            $utils = new SystemUtilities();
            $taskId = $utils->generateTaskId();

            expect($taskId)
                ->toBeString()
                ->toMatch('/^defer_\d{8}_\d{6}_[0-9a-f.]+$/')
            ;
        });

        it('generates unique ids on subsequent calls', function () {
            $utils = new SystemUtilities();
            $id1 = $utils->generateTaskId();
            usleep(10);
            $id2 = $utils->generateTaskId();

            expect($id1)->not->toBe($id2);
        });
    });

    describe('getPhpBinary', function () {
        it('returns a valid executable path', function () {
            $utils = new SystemUtilities();
            $phpBinary = $utils->getPhpBinary();

            expect($phpBinary)->toBeString()->not->toBeEmpty();

            if (PHP_OS_FAMILY !== 'Windows') {
                expect(is_executable($phpBinary))->toBeTrue();
            }
        });

        it('matches PHP_BINARY constant if available', function () {
            if (! defined('PHP_BINARY') || ! is_executable(PHP_BINARY)) {
                $this->markTestSkipped('PHP_BINARY constant is not available');
            }

            $utils = new SystemUtilities();
            expect($utils->getPhpBinary())->toBe(PHP_BINARY);
        });
    });

    describe('findAutoloadPath', function () {
        it('locates the composer autoload file via Config', function () {
            $utils = new SystemUtilities();

            $expectedRoot = Config::getRootPath();
            $path = $utils->findAutoloadPath();

            expect($path)->toBe($expectedRoot . '/vendor/autoload.php');
        });
    });

    describe('getFrameworkBootstrap', function () {
        afterEach(function () {
            Config::reset();
        });

        it('returns default none configuration when config is empty', function () {
            $utils = new SystemUtilities();
            $result = $utils->getFrameworkBootstrap();

            expect($result)->toBe([
                'name' => 'none',
                'bootstrap_file' => null,
                'bootstrap_callback' => null,
            ]);
        });

        it('throws exception if configured bootstrap file does not exist', function () {
            Config::setFromRoot(
                filename: 'hibla_parallel',
                key: 'bootstrap.file',
                value: '/path/does/not/exist.php'
            );

            $utils = new SystemUtilities();

            expect(fn () => $utils->getFrameworkBootstrap())
                ->toThrow(RuntimeException::class, 'Bootstrap file not found')
            ;
        });

        it('throws exception if configured callback is not callable', function () {
            Config::setFromRoot(
                filename: 'hibla_parallel',
                key: 'bootstrap.callback',
                value: 'not_a_function'
            );

            $utils = new SystemUtilities();

            expect(fn () => $utils->getFrameworkBootstrap())
                ->toThrow(RuntimeException::class, 'Bootstrap callback must be callable')
            ;
        });
    });
});
