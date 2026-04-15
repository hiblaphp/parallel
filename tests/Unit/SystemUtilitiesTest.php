<?php

declare(strict_types=1);

use Hibla\Parallel\Utilities\SystemUtilities;
use Rcalicdan\ConfigLoader\Config;

describe('SystemUtilities', function () {
    describe('getPhpBinary', function () {
        it('returns a valid executable path', function () {
            $phpBinary = SystemUtilities::getPhpBinary();

            expect($phpBinary)->toBeString()->not->toBeEmpty();

            if (PHP_OS_FAMILY !== 'Windows') {
                expect(is_executable($phpBinary))->toBeTrue();
            }
        });

        it('matches PHP_BINARY constant if available', function () {
            if (! defined('PHP_BINARY') || ! is_executable(PHP_BINARY)) {
                $this->markTestSkipped('PHP_BINARY constant is not available');
            }

            expect(SystemUtilities::getPhpBinary())->toBe(PHP_BINARY);
        });
    });

    describe('findAutoloadPath', function () {
        it('locates the composer autoload file via Config', function () {

            $expectedRoot = Config::getRootPath();
            $path = SystemUtilities::findAutoloadPath();

            expect($path)->toBe($expectedRoot . '/vendor/autoload.php');
        });
    });

    describe('getFrameworkBootstrap', function () {
        afterEach(function () {
            Config::reset();
        });

        it('returns default none configuration when config is empty', function () {
            $result = SystemUtilities::getFrameworkBootstrap();

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

            expect(fn () => SystemUtilities::getFrameworkBootstrap())
                ->toThrow(RuntimeException::class, 'Bootstrap file not found')
            ;
        });

        it('throws exception if configured callback is not callable', function () {
            Config::setFromRoot(
                filename: 'hibla_parallel',
                key: 'bootstrap.callback',
                value: 'not_a_function'
            );

            expect(fn () => SystemUtilities::getFrameworkBootstrap())
                ->toThrow(RuntimeException::class, 'Bootstrap callback must be callable')
            ;
        });
    });
});
