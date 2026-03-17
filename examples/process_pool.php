<?php

use Hibla\Parallel\Parallel;
use Hibla\Promise\Promise;

require __DIR__ . '/../vendor/autoload.php';

$pool = Parallel::pool(size: 4);

for ($i = 0; $i < 3; $i++) {
    echo "Iteration: $i\n";
    Promise::all([
        $pool->run(function () {
            echo "Pid: " . getmypid() . "\n";
            sleep(1);
        }),
        $pool->run(function () {
            echo "Pid: " . getmypid() . "\n";
            sleep(1);
        }),
        $pool->run(function () {
            echo "Pid: " . getmypid() . "\n";
            sleep(1);
        }),
        $pool->run(function () {
            echo "Pid: " . getmypid() . "\n";
            sleep(1);
        }),
        $pool->run(function () {
            echo "Pid: " . getmypid() . "\n";
            sleep(1);
        }),
    ])->wait();
    echo "Iteration $i completed\n";
}

$pool->shutdown();
