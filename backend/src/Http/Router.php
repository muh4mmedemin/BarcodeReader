<?php

declare(strict_types=1);

namespace App\Http;

final class Router
{
    /** @var list<array{method: string, regex: string, roles: list<string>, handler: callable}> */
    private array $routes = [];

    /**
     * @param list<string> $roles Bu uca erişebilen roller.
     */
    public function add(string $method, string $pattern, array $roles, callable $handler): void
    {
        $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $pattern) . '$#';
        $this->routes[] = ['method' => $method, 'regex' => $regex, 'roles' => $roles, 'handler' => $handler];
    }

    public function dispatch(Request $request): void
    {
        $pathMatched = false;

        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $request->path, $matches)) {
                continue;
            }
            $pathMatched = true;
            if ($route['method'] !== $request->method) {
                continue;
            }

            if ($route['roles'] !== [] && !in_array($request->role, $route['roles'], true)) {
                throw new HttpException(403, 'FORBIDDEN', 'Bu işlem için yetkiniz yok.');
            }

            $params = array_map('rawurldecode', array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY));
            ($route['handler'])($request, $params);
            return;
        }

        throw $pathMatched
            ? new HttpException(405, 'METHOD_NOT_ALLOWED', 'Bu uç için HTTP metodu desteklenmiyor.')
            : new HttpException(404, 'ROUTE_NOT_FOUND', 'API ucu bulunamadı.');
    }
}
