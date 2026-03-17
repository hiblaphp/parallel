<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use function Hibla\asyncFn;

use Hibla\EventLoop\Factories\EventLoopComponentFactory;
use Hibla\Parallel\Parallel;
use Hibla\Promise\Promise;
use Hibla\Socket\SocketServer;

$routerClass = new class () {
    private array $static = [];
    private array $dynamic = [];

    public function get(string $path, callable $handler): void
    {
        $this->addRoute('GET', $path, $handler);
    }

    private function addRoute(string $method, string $path, callable $handler): void
    {
        if (strpos($path, '{') === false) {
            $this->static[$method][$path] = $handler;

            return;
        }

        $pattern = preg_replace('/\{(\w+)\}/', '(?P<$1>[^/]+)', $path);
        $this->dynamic[$method]['#^' . $pattern . '$#'] = $handler;
    }

    public function dispatch(string $method, string $uri): string
    {
        if (isset($this->static[$method][$uri])) {
            return ($this->static[$method][$uri])([]);
        }

        foreach ($this->dynamic[$method] ?? [] as $pattern => $handler) {
            if (preg_match($pattern, $uri, $matches)) {
                $params = array_filter($matches, fn ($k) => ! is_int($k), ARRAY_FILTER_USE_KEY);

                return $handler($params);
            }
        }

        return '404 Not Found';
    }
};

$pool = Parallel::pool(size: 6);

echo 'Loop Driver: ' . EventLoopComponentFactory::resolveDriver() . "\n";
echo 'Master Supervising Cluster (PID: ' . getmypid() . ")\n";
echo "Simulating simulated I/O latency per request...\n";

$startAcceptor = function () use (&$startAcceptor, $pool, $routerClass) {
    $pool->run(function () use ($routerClass) {
        $pid = getmypid();
        $router = new $routerClass();

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
            $connection->on('data', asyncFn(function (string $rawRequest) use ($connection, $router) {

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
            }));
        });

        return new Promise();
    });
};

for ($i = 0; $i < 8; $i++) {
    $startAcceptor();
}
