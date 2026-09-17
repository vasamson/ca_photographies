<?php
declare(strict_types=1);

namespace App;

/** Requête courante (lecture seule). */
final class Request
{
    public readonly string $method;
    public readonly string $path;
    private ?array $json = null;

    public function __construct()
    {
        $this->method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $path = '/' . trim(rawurldecode($path), '/');
        $this->path = $path === '/' ? '/' : $path;
    }

    public function query(string $k, ?string $d = null): ?string { $v = $_GET[$k] ?? null; return is_string($v) ? $v : $d; }
    public function int(string $k, int $d = 0): int { return (int) ($this->query($k) ?? $d); }

    /** Corps JSON (POST/PATCH). */
    public function json(): array
    {
        if ($this->json !== null) return $this->json;
        $raw = file_get_contents('php://input') ?: '';
        $data = json_decode($raw, true);
        return $this->json = is_array($data) ? $data : [];
    }

    public function input(string $k, mixed $d = null): mixed { return $this->json()[$k] ?? $d; }
    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return $_SERVER[$key] ?? null;
    }
    public function ip(): string { return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'; }
    public function wantsJson(): bool { return str_starts_with($this->path, '/api/') || str_contains($this->header('Accept') ?? '', 'application/json'); }
}
