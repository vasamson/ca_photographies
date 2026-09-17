<?php
declare(strict_types=1);

namespace App;

/** Helpers de réponse. */
final class Response
{
    public static function json(mixed $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function html(string $body, int $status = 200, array $headers = []): void
    {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        foreach ($headers as $h) header($h);
        echo $body;
        exit;
    }

    public static function redirect(string $url, int $status = 302): void
    {
        http_response_code($status);
        header('Location: ' . $url);
        exit;
    }

    /** Sert un fichier statique (mode local uniquement : en production LiteSpeed sert les photos directement). */
    public static function file(string $abs): void
    {
        $mime = mime_content_type($abs) ?: 'application/octet-stream';
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($abs));
        header('Cache-Control: public, max-age=31536000, immutable');
        readfile($abs);
        exit;
    }
}
