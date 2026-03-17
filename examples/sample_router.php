<?php

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
        // If the path has no curly braces, it's a fast-path static route
        if (strpos($path, '{') === false) {
            $this->static[$method][$path] = $handler;

            return;
        }

        // Convert {id} placeholders to named regex groups
        $pattern = preg_replace('/\{(\w+)\}/', '(?P<$1>[^/]+)', $path);
        $this->dynamic[$method]['#^' . $pattern . '$#'] = $handler;
    }

    public function dispatch(string $method, string $uri): string
    {
        // 1. Check static routes (O(1) lookup - Extremely Fast)
        if (isset($this->static[$method][$uri])) {
            return ($this->static[$method][$uri])([]);
        }

        // 2. Check dynamic routes (Regex scan)
        foreach ($this->dynamic[$method] ?? [] as $pattern => $handler) {
            if (preg_match($pattern, $uri, $matches)) {
                // Filter out numeric keys from preg_match
                $params = array_filter($matches, fn ($k) => ! is_int($k), ARRAY_FILTER_USE_KEY);

                return $handler($params);
            }
        }

        return '404 Not Found';
    }
}
