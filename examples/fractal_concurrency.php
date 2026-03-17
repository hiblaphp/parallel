<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Hibla\Promise\Promise;

use function Hibla\async;
use function Hibla\await;
use function Hibla\delay;
use function Hibla\parallel;

$start = microtime(true);

Promise::all([
    parallel(function () {
        await(Promise::all([
            async(function () {
                await(delay(1));
            }),
            async(function () {
                await(delay(1));
            }),
            async(function () {
                await(delay(1));
            }),
            async(function () {
                await(parallel(function () {
                    sleep(1);
                }));
            })
        ]));
    }),
    parallel(function () {
        sleep(1);
    }),
    parallel(function () {
        sleep(1);
    }),
    async(function () {
        await(delay(1));
    }),
])->wait();

$end = microtime(true);
$duration = $end - $start;
echo "Total duration: $duration seconds\n";
