<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use function Hibla\asyncFn;

use Hibla\Parallel\Interfaces\ProcessPoolInterface;
use Hibla\Parallel\Parallel;
use Hibla\Socket\SocketServer;

$routerClass = new class () {
    private array $static = [];

    public function get(string $path, callable $handler): void
    {
        $this->static['GET'][$path] = $handler;
    }

    public function dispatch(string $method, string $uri): string
    {
        return isset($this->static[$method][$uri]) ? ($this->static[$method][$uri])() : '404 Not Found';
    }
};

$serverTask = function () use ($routerClass) {
    $pid = getmypid();
    $router = new $routerClass();

    $router->get('/', function () use ($pid) {
        return "[Worker $pid] Hello! I am healthy and serving requests.";
    });

    $router->get('/crash', function () use ($pid) { // open your browser and visit http://127.0.0.1:8080/crash and see the cli logs
        echo "[Worker $pid]  Received suicide command! Crashing now...\n";
        Hibla\delay(0.1)->then(fn () => exit(1));

        return "[Worker $pid]  Acknowledged. I am dying. Goodbye world.";
    });

    $server = new SocketServer('127.0.0.1:8080', [
        'tcp' => ['so_reuseport' => true, 'backlog' => 65535],
    ]);

    $server->on('connection', function ($connection) use ($router) {
        $connection->on('data', asyncFn(function (string $rawRequest) use ($connection, $router) {
            $firstLine = substr($rawRequest, 0, strpos($rawRequest, "\r\n"));
            $parts = explode(' ', $firstLine);
            $method = $parts[0] ?? 'GET';
            $uri = explode('?', $parts[1] ?? '/')[0];

            $content = $router->dispatch($method, $uri);

            $response = "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nContent-Length: " . strlen($content) . "\r\n\r\n" . $content;
            $connection->write($response);
        }));
    });

    echo "[Worker $pid] Started listening on 8080\n";
};

$poolSize = 4;

$pool = Parallel::pool(size: $poolSize)
    ->withoutTimeout()
    ->onWorkerRespawn(function (ProcessPoolInterface $pool) use ($serverTask) {
        echo "\n[Master]  ALERT: Worker process died! Triggering onWorkerRespawn hook...\n";
        echo "[Master] Re-submitting Socket Server Task to the replacement worker.\n\n";

        $pool->run($serverTask);
    })
;

echo "--- Hibla Parallel: Chaos Web Server Test ---\n";
echo '[Master PID: ' . getmypid() . "] Supervising $poolSize workers...\n\n";

for ($i = 0; $i < $poolSize; $i++) {
    $pool->run($serverTask)->catch(fn ($e) => null);
}
