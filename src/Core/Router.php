<?php

namespace App\Core;

class Router
{
    private array $routes = [];
    private array $middleware = [];
    private array $groupMiddleware = [];
    private string $groupPrefix = '';
    
    public function addMiddleware($middleware): void
    {
        $this->middleware[] = $middleware;
    }
    
    public function get(string $path, string $handler): void
    {
        $this->addRoute('GET', $path, $handler);
    }
    
    public function post(string $path, string $handler): void
    {
        $this->addRoute('POST', $path, $handler);
    }
    
    public function put(string $path, string $handler): void
    {
        $this->addRoute('PUT', $path, $handler);
    }
    
    public function delete(string $path, string $handler): void
    {
        $this->addRoute('DELETE', $path, $handler);
    }
    
    public function group(string $prefix, callable $callback, array $middleware = []): void
    {
        $oldPrefix = $this->groupPrefix;
        $oldMiddleware = $this->groupMiddleware;
        
        $this->groupPrefix = $oldPrefix . $prefix;
        $this->groupMiddleware = array_merge($oldMiddleware, $middleware);
        
        $callback($this);
        
        $this->groupPrefix = $oldPrefix;
        $this->groupMiddleware = $oldMiddleware;
    }
    
    private function addRoute(string $method, string $path, string $handler): void
    {
        $fullPath = $this->groupPrefix . $path;
        $this->routes[] = [
            'method' => $method,
            'path' => $fullPath,
            'handler' => $handler,
            'middleware' => array_merge($this->middleware, $this->groupMiddleware)
        ];
    }
    
    public function dispatch(string $method, string $uri): void
    {
        // Remove query string
        $uri = parse_url($uri, PHP_URL_PATH);
        
        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }
            
            $pattern = $this->convertToRegex($route['path']);
            if (preg_match($pattern, $uri, $matches)) {
                // Extract parameters
                $params = array_slice($matches, 1);
                
                // Execute middleware
                foreach ($route['middleware'] as $middleware) {
                    if (method_exists($middleware, 'handle')) {
                        $result = $middleware->handle();
                        if ($result === false) {
                            return;
                        }
                    }
                }
                
                // Execute handler
                $this->executeHandler($route['handler'], $params);
                return;
            }
        }
        
        // No route found
        http_response_code(404);
        echo json_encode(['error' => 'Route not found']);
    }
    
    private function convertToRegex(string $path): string
    {
        $pattern = preg_replace('/\{([^}]+)\}/', '([^/]+)', $path);
        return '#^' . $pattern . '$#';
    }
    
    private function executeHandler(string $handler, array $params): void
    {
        [$controllerName, $method] = explode('@', $handler);
        $controllerClass = "App\\Controllers\\{$controllerName}";
        
        if (!class_exists($controllerClass)) {
            throw new \Exception("Controller {$controllerClass} not found");
        }
        
        $controller = new $controllerClass();
        
        if (!method_exists($controller, $method)) {
            throw new \Exception("Method {$method} not found in {$controllerClass}");
        }
        
        call_user_func_array([$controller, $method], $params);
    }
}


