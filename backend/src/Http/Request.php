<?php

declare(strict_types=1);

namespace App\Http;

final class Request
{
    /** Giriş yapmış kullanıcının rolü (rep | production). Auth sonrası set edilir. */
    public ?string $role = null;

    /** Giriş yapmış kullanıcı (id, username, role, station_id, station_name). */
    public ?array $user = null;

    private function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $headers,
        private readonly string $rawBody,
    ) {
    }

    public static function fromGlobals(): self
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $path = '/' . trim($path, '/');

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
            }
        }

        return new self(
            strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            $path,
            $_GET,
            $headers,
            (string) file_get_contents('php://input'),
        );
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /** "Authorization: Bearer <token>" başlığındaki token. */
    public function bearerToken(): ?string
    {
        $header = $this->header('authorization') ?? '';
        return preg_match('/^Bearer\s+([A-Fa-f0-9]{64})$/', trim($header), $m) ? $m[1] : null;
    }

    /** Başarıyla yüklenmiş dosyanın geçici yolu (multipart/form-data), yoksa null. */
    public function uploadedFile(string $name): ?string
    {
        $f = $_FILES[$name] ?? null;
        return is_array($f) && ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && is_uploaded_file($f['tmp_name'])
            ? $f['tmp_name'] : null;
    }

    public function json(): array
    {
        if ($this->rawBody === '') {
            return [];
        }
        $data = json_decode($this->rawBody, true);
        if (!is_array($data)) {
            throw new HttpException(400, 'INVALID_JSON', 'İstek gövdesi geçerli bir JSON nesnesi olmalı.');
        }
        return $data;
    }
}
