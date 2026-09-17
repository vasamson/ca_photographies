<?php
declare(strict_types=1);

namespace App;

/** Routeur : motifs simples avec {param}. */
final class Router
{
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler): void
    {
        $regex = '#^' . preg_replace('#\{([a-zA-Z_]+)\}#', '(?P<$1>[^/]+)', $pattern) . '$#';
        $this->routes[] = [$method, $regex, $handler];
    }
    public function get(string $p, callable $h): void { $this->add('GET', $p, $h); }
    public function post(string $p, callable $h): void { $this->add('POST', $p, $h); }
    public function put(string $p, callable $h): void { $this->add('PUT', $p, $h); }
    public function patch(string $p, callable $h): void { $this->add('PATCH', $p, $h); }
    public function delete(string $p, callable $h): void { $this->add('DELETE', $p, $h); }

    public function dispatch(Request $req): void
    {
        $methodMatched = false;
        foreach ($this->routes as [$method, $regex, $handler]) {
            if (!preg_match($regex, $req->path, $m)) continue;
            $methodMatched = true;
            if ($method !== $req->method && !($method === 'GET' && $req->method === 'HEAD')) continue;
            $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            $handler($req, ...array_values($params));
            return;
        }
        throw new HttpException($methodMatched ? 405 : 404, $methodMatched ? 'Méthode non autorisée' : 'Page introuvable');
    }
}
