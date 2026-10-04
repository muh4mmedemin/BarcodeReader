<?php

declare(strict_types=1);

namespace App\Services;

use App\Http\HttpException;

final class Validator
{
    /** PO no, MA kodu ve barkod için izin verilen karakterler. */
    private const CODE_PATTERN = '/^[A-Za-z0-9._\-\/]{1,64}$/';

    public static function code(array $input, string $field, string $label): string
    {
        $value = trim((string) ($input[$field] ?? ''));
        if ($value === '') {
            throw HttpException::validation($field, "$label zorunludur.");
        }
        if (!preg_match(self::CODE_PATTERN, $value)) {
            throw HttpException::validation(
                $field,
                "$label en fazla 64 karakter olmalı; sadece harf, rakam ve . _ - / içerebilir."
            );
        }
        return $value;
    }

    public static function optionalText(array $input, string $field, int $max = 500): ?string
    {
        if (!array_key_exists($field, $input) || $input[$field] === null) {
            return null;
        }
        $value = trim((string) $input[$field]);
        // mbstring zorunlu olmasın diye UTF-8 karakter sayımı regex ile yapılır.
        if (preg_match_all('/./su', $value) > $max) {
            throw HttpException::validation($field, "En fazla $max karakter olabilir.");
        }
        return $value === '' ? null : $value;
    }

    /** İsteğe bağlı tarih, YYYY-MM-DD biçiminde ve geçerli bir takvim günü olmalı. */
    public static function optionalDate(array $input, string $field): ?string
    {
        $value = trim((string) ($input[$field] ?? ''));
        if ($value === '') {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($d === false || $d->format('Y-m-d') !== $value) {
            throw HttpException::validation($field, 'Tarih YYYY-AA-GG biçiminde olmalı.');
        }
        return $value;
    }

    public static function positiveInt(array $input, string $field, int $default): int
    {
        $value = $input[$field] ?? $default;
        if (filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            throw HttpException::validation($field, 'Pozitif bir tam sayı olmalı.');
        }
        return (int) $value;
    }

    public static function oneOf(array $input, string $field, array $allowed, string $default): string
    {
        $value = (string) ($input[$field] ?? $default);
        if (!in_array($value, $allowed, true)) {
            throw HttpException::validation($field, 'Geçerli değerler: ' . implode(', ', $allowed));
        }
        return $value;
    }
}
