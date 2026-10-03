<?php

declare(strict_types=1);

namespace App\Services;

use App\Http\HttpException;
use PDO;

final class StationService
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** Aktif istasyonlar, üretim sırasına göre. */
    public function list(): array
    {
        $rows = $this->db->query('SELECT * FROM stations WHERE active = 1 ORDER BY sort_order, id')->fetchAll();
        return array_map($this->cast(...), $rows);
    }

    public function getActive(int $id): array
    {
        $stmt = $this->db->prepare('SELECT * FROM stations WHERE id = :id AND active = 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw HttpException::validation('station_id', 'Geçerli bir istasyon seçilmeli.');
        }
        return $this->cast($row);
    }

    private function cast(array $row): array
    {
        return [
            'id'         => (int) $row['id'],
            'code'       => $row['code'],
            'name'       => $row['name'],
            'sort_order' => (int) $row['sort_order'],
            'is_final'   => (bool) $row['is_final'],
        ];
    }
}
