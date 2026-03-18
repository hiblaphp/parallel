<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/sample_router.php';

use function Hibla\asyncFn;

use Hibla\EventLoop\Factories\EventLoopComponentFactory;
use Hibla\Parallel\Parallel;
use Hibla\Promise\Promise;
use Hibla\Socket\SocketServer;

// use benchmarking tool like ab or wrk to test the server
// for best performance consider installing ext-uv extension
$poolSize = 8;// match base on your available cpu core
$pool = Parallel::pool(size: $poolSize)->withBootstrap(__DIR__ . '/bootsrap.php');

echo 'Loop Driver: ' . EventLoopComponentFactory::resolveDriver() . "\n";
echo 'Master Supervising Cluster (PID: ' . getmypid() . ")\n";
echo "Simulating simulated I/O latency per request...\n";

$startAcceptor = function () use (&$startAcceptor, $pool) {
    $pool->run(function ()  {
        $pid = getmypid();
        $router = new Router();

        $router->get('/', function () use ($pid) {
            return "Welcome to Hibla! Processed by worker {$pid} after simulated I/O";
        });

        $router->get('/user/{name}', function ($params) {
            return 'Profile of user: ' . $params['name'];
        });

        $server = new SocketServer('127.0.0.1:8080', [
            'tcp' => ['so_reuseport' => true, 'backlog' => 65535],
        ]);

        $server->on('connection', function ($connection) use ($router) {
            $connection->on('data', function (string $rawRequest) use ($connection, $router) {

                $firstLine = substr($rawRequest, 0, strpos($rawRequest, "\r\n"));
                $parts = explode(' ', $firstLine);
                $method = $parts[0] ?? 'GET';
                $uri = explode('?', $parts[1] ?? '/')[0];

                $content = $router->dispatch($method, $uri);

                $response = "HTTP/1.1 200 OK\r\n"
                    . "Content-Type: text/plain\r\n"
                    . 'Content-Length: ' . strlen($content) . "\r\n"
                    . "Connection: keep-alive\r\n"
                    . "\r\n"
                    . $content;

                $connection->write($response);
            });
        });

        return new Promise();
    });
};

for ($i = 0; $i < $poolSize; $i++) {
    $startAcceptor();
}
