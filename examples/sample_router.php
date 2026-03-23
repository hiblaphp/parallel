<?php

declare(strict_types=1);

class Router
{
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
}
