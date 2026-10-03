<?php

declare(strict_types=1);

namespace App\Services;

use App\Http\HttpException;
use PDO;
use PDOException;

/**
 * Benzersizlik kurallarının tek merkezi.
 *
 * İki katmanlı koruma:
 *  1) assertAvailable(): kayıttan önce kontrol eder, kullanıcıya hangi alanın
 *     çakıştığını ve hangi kayıtla çakıştığını söyler.
 *  2) translate(): iki cihaz aynı anda aynı kodu yazmaya çalışırsa veritabanındaki
 *     UNIQUE kısıtı devreye girer; bu hata da aynı 409 formatına çevrilir.
 */
final class UniqueGuard
{
    /** alan => [tablo, sütun, etiket] */
    private const FIELDS = [
        'po_number' => ['purchase_orders', 'po_number', 'PO numarası'],
        'ma_code'   => ['work_orders', 'ma_code', 'MA kodu'],
        'barcode'   => ['work_orders', 'barcode', 'Barkod'],
    ];

    public function __construct(private readonly PDO $db)
    {
    }

    /** Değer kullanımdaysa 409 fırlatır. $exceptId: güncellenen kaydın kendisi. */
    public function assertAvailable(string $field, string $value, ?int $exceptId = null): void
    {
        [$table, $column, $label] = self::FIELDS[$field];

        $stmt = $this->db->prepare("SELECT id FROM $table WHERE $column = :v AND id IS NOT :except LIMIT 1");
        $stmt->execute(['v' => $value, 'except' => $exceptId]);

        if ($stmt->fetchColumn() !== false) {
            throw HttpException::duplicate($field, "$label \"$value\" zaten kullanılıyor.");
        }
    }

    /** Değerin kullanılabilir olup olmadığını döndürür (client tarafı anlık kontrol için). */
    public function isAvailable(string $field, string $value, ?int $exceptId = null): bool
    {
        try {
            $this->assertAvailable($field, $value, $exceptId);
            return true;
        } catch (HttpException) {
            return false;
        }
    }

    public static function supports(string $field): bool
    {
        return isset(self::FIELDS[$field]);
    }

    /** SQLite UNIQUE ihlalini anlamlı bir 409 hatasına çevirir; diğer hataları olduğu gibi bırakır. */
    public static function translate(PDOException $e): \Throwable
    {
        if (!str_contains($e->getMessage(), 'UNIQUE constraint failed')) {
            return $e;
        }
        foreach (self::FIELDS as $field => [$table, $column, $label]) {
            if (str_contains($e->getMessage(), "$table.$column")) {
                return HttpException::duplicate($field, "$label zaten kullanılıyor.");
            }
        }
        return HttpException::duplicate('unknown', 'Benzersiz olması gereken bir değer tekrar kullanıldı.');
    }
}
