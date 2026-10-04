<?php

declare(strict_types=1);

namespace App\Http;

final class Response
{
    public static function json(mixed $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** Başarılı yanıtlar her zaman {"data": ...} zarfı içinde döner. */
    public static function data(mixed $data, int $status = 200, array $meta = []): void
    {
        $payload = ['data' => $data];
        if ($meta !== []) {
            $payload['meta'] = $meta;
        }
        self::json($payload, $status);
    }

    /** Dosya indirme yanıtı (Türkçe karakterli adlar için filename* kullanılır). */
    public static function file(string $content, string $filename, string $mime): void
    {
        $ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename);
        http_response_code(200);
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . strlen($content));
        header("Content-Disposition: attachment; filename=\"$ascii\"; filename*=UTF-8''" . rawurlencode($filename));
        header('Cache-Control: no-store');
        echo $content;
    }

    public static function noContent(): void
    {
        http_response_code(204);
    }

    public static function cors(string $allowedOrigins, ?string $origin): void
    {
        $allowed = array_map('trim', explode(',', $allowedOrigins));
        if (in_array('*', $allowed, true)) {
            header('Access-Control-Allow-Origin: *');
        } elseif ($origin !== null && in_array($origin, $allowed, true)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Vary: Origin');
        }
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
        header('Access-Control-Max-Age: 86400');
        header('Access-Control-Expose-Headers: Content-Disposition');
    }
}
