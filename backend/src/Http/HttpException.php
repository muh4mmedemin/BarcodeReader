<?php

declare(strict_types=1);

namespace App\Http;

/**
 * API'nin döndürdüğü tüm hatalar bu sınıftan geçer.
 * JSON çıktısı: {"error": {"code": "...", "message": "...", "field": "..."}}
 */
final class HttpException extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly ?string $field = null,
    ) {
        parent::__construct($message);
    }

    public static function notFound(string $message = 'Kayıt bulunamadı.'): self
    {
        return new self(404, 'NOT_FOUND', $message);
    }

    public static function validation(string $field, string $message): self
    {
        return new self(422, 'VALIDATION_ERROR', $message, $field);
    }

    public static function duplicate(string $field, string $message): self
    {
        return new self(409, 'DUPLICATE', $message, $field);
    }

    public function toArray(): array
    {
        $error = ['code' => $this->errorCode, 'message' => $this->getMessage()];
        if ($this->field !== null) {
            $error['field'] = $this->field;
        }
        return ['error' => $error];
    }
}
