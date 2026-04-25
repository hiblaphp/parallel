<?php

declare(strict_types=1);

use Hibla\Parallel\Parallel;
use Hibla\Promise\Promise;

use function Hibla\async;
use function Hibla\await;
use function Hibla\delay;

require __DIR__ . '/../vendor/autoload.php';

$pool = Parallel::pool(size: 4);

for ($i = 0; $i < 3; $i++) {
    echo "Iteration: $i\n";
    Promise::all([
        $pool->run(function () {
            echo 'Pid: ' . getmypid() . "\n";
            sleep(1);
        }),
        $pool->run(function () {
            echo 'Pid: ' . getmypid() . "\n";
            sleep(1);
        }),
        $pool->run(function () {
            echo 'Pid: ' . getmypid() . "\n";
            sleep(1);
        }),
        $pool->run(function () {
            echo 'Pid: ' . getmypid() . "\n";
            sleep(1);
        }),
        $pool->run(function () {
            echo 'Pid: ' . getmypid() . "\n";
            sleep(1);
        }),
        async(function () {
            echo 'Async Pid: ' . getmypid() . "\n";
            await(delay(0.1));
        }),
    ])->wait();
    echo "Iteration $i completed\n";
}

$pool->shutdown();
