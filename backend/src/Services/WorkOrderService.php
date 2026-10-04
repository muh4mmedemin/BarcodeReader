<?php

declare(strict_types=1);

namespace App\Services;

use App\Http\HttpException;
use PDO;
use PDOException;

final class WorkOrderService
{
    public const STATUSES = ['open', 'in_progress', 'done', 'cancelled'];

    private const SELECT = 'SELECT w.*, p.po_number, p.customer, s.name AS current_station_name
                              FROM work_orders w
                              JOIN purchase_orders p ON p.id = w.po_id
                              LEFT JOIN stations s ON s.id = w.current_station_id';

    public function __construct(
        private readonly PDO $db,
        private readonly UniqueGuard $unique,
        private readonly PurchaseOrderService $purchaseOrders,
        private readonly StationService $stations,
    ) {
    }

    public function listByPo(int $poId): array
    {
        $this->purchaseOrders->get($poId);

        $stmt = $this->db->prepare(self::SELECT . ' WHERE w.po_id = :po ORDER BY w.id');
        $stmt->execute(['po' => $poId]);
        return array_map($this->cast(...), $stmt->fetchAll());
    }

    public function get(int $id): array
    {
        return $this->findOne('w.id = :v', $id) ?? throw HttpException::notFound('İş emri bulunamadı.');
    }

    public function findByBarcode(string $barcode): array
    {
        return $this->findOne('w.barcode = :v', trim($barcode))
            ?? throw new HttpException(404, 'BARCODE_NOT_FOUND', "\"$barcode\" barkoduna ait iş emri yok.");
    }

    public function create(int $poId, array $input): array
    {
        $this->purchaseOrders->get($poId);

        $maCode  = Validator::code($input, 'ma_code', 'MA kodu');
        $barcode = Validator::code($input, 'barcode', 'Barkod');
        $this->unique->assertAvailable('ma_code', $maCode);
        $this->unique->assertAvailable('barcode', $barcode);

        try {
            $stmt = $this->db->prepare(
                'INSERT INTO work_orders (po_id, ma_code, barcode, description, quantity)
                 VALUES (:po, :ma, :barcode, :description, :quantity)'
            );
            $stmt->execute([
                'po'          => $poId,
                'ma'          => $maCode,
                'barcode'     => $barcode,
                'description' => Validator::optionalText($input, 'description', 500),
                'quantity'    => Validator::positiveInt($input, 'quantity', 1),
            ]);
        } catch (PDOException $e) {
            throw UniqueGuard::translate($e);
        }

        return $this->get((int) $this->db->lastInsertId());
    }

    public function update(int $id, array $input): array
    {
        $current = $this->get($id);

        $maCode = array_key_exists('ma_code', $input)
            ? Validator::code($input, 'ma_code', 'MA kodu') : $current['ma_code'];
        $barcode = array_key_exists('barcode', $input)
            ? Validator::code($input, 'barcode', 'Barkod') : $current['barcode'];
        $this->unique->assertAvailable('ma_code', $maCode, $id);
        $this->unique->assertAvailable('barcode', $barcode, $id);

        try {
            $stmt = $this->db->prepare(
                "UPDATE work_orders
                    SET ma_code = :ma, barcode = :barcode, description = :description,
                        quantity = :quantity, status = :status, updated_at = datetime('now'),
                        -- Elle open durumuna geri alınan iş emri istasyondan çıkar
                        current_station_id = CASE WHEN :status = 'open' THEN NULL ELSE current_station_id END
                  WHERE id = :id"
            );
            $stmt->execute([
                'id'          => $id,
                'ma'          => $maCode,
                'barcode'     => $barcode,
                'description' => array_key_exists('description', $input)
                    ? Validator::optionalText($input, 'description', 500) : $current['description'],
                'quantity'    => Validator::positiveInt($input, 'quantity', $current['quantity']),
                'status'      => Validator::oneOf($input, 'status', self::STATUSES, $current['status']),
            ]);
        } catch (PDOException $e) {
            throw UniqueGuard::translate($e);
        }

        return $this->get($id);
    }

    /**
     * Tüm PO'lar ve içlerindeki iş emirleri (rapor sayfası için tek sorguda).
     * Her iş emrine son okutma zamanı (last_scan_at) eklenir.
     */
    public function overview(): array
    {
        $pos = $this->db->query('SELECT * FROM purchase_orders ORDER BY created_at DESC, id DESC')->fetchAll();

        $rows = $this->db->query(
            'SELECT w.*, p.po_number, p.customer, s.name AS current_station_name,
                    (SELECT MAX(sc.scanned_at) FROM scans sc WHERE sc.work_order_id = w.id) AS last_scan_at
               FROM work_orders w
               JOIN purchase_orders p ON p.id = w.po_id
               LEFT JOIN stations s ON s.id = w.current_station_id
              ORDER BY w.po_id, w.id'
        )->fetchAll();

        $byPo = [];
        foreach ($rows as $row) {
            $byPo[(int) $row['po_id']][] = $this->cast($row);
        }

        return array_map(static function (array $po) use ($byPo): array {
            $po['id'] = (int) $po['id'];
            $po['work_orders'] = $byPo[$po['id']] ?? [];
            return $po;
        }, $pos);
    }

    /** İş emrinin istasyon geçmişi: hangi istasyonda, ne zaman, kim okuttu (eskiden yeniye). */
    public function history(int $id): array
    {
        $this->get($id);

        $stmt = $this->db->prepare(
            'SELECT sc.id, sc.scanned_at, sc.station_id, s.name AS station_name, s.is_final, u.username, sc.kind, sc.status
               FROM scans sc
               LEFT JOIN stations s ON s.id = sc.station_id
               LEFT JOIN users u ON u.id = sc.user_id
              WHERE sc.work_order_id = :id
              ORDER BY sc.scanned_at, sc.id'
        );
        $stmt->execute(['id' => $id]);

        return array_map(static fn (array $r): array => [
            'id'           => (int) $r['id'],
            'scanned_at'   => $r['scanned_at'],
            'station_id'   => $r['station_id'] === null ? null : (int) $r['station_id'],
            'station_name' => $r['station_name'],
            'is_final'     => (bool) $r['is_final'],
            'username'     => $r['username'],
            'kind'         => $r['kind'],
            'status'       => $r['status'],
        ], $stmt->fetchAll());
    }

    public function delete(int $id): void
    {
        $this->get($id);
        $this->db->prepare('DELETE FROM work_orders WHERE id = :id')->execute(['id' => $id]);
    }

    /**
     * Üretim okutması: iş emrini okutulan istasyona taşır ve okutma kaydı düşer.
     * Son istasyonda (is_final) okutulan iş emri tamamlanır.
     * Tamamlanmış/iptal iş emri veya zaten o istasyonda olan iş emri okutulamaz.
     *
     * @return array{work_order: array, scan_id: int}
     */
    public function scan(string $barcode, int $stationId, ?int $userId = null): array
    {
        $station = $this->stations->getActive($stationId);

        $this->db->beginTransaction();
        try {
            $wo = $this->findByBarcode($barcode);

            if ($wo['status'] === 'cancelled') {
                throw new HttpException(409, 'WORK_ORDER_CANCELLED', "{$wo['ma_code']} iptal edilmiş.");
            }
            if ($wo['status'] === 'done') {
                throw new HttpException(409, 'WORK_ORDER_DONE', "{$wo['ma_code']} zaten tamamlanmış.");
            }
            if ($wo['current_station_id'] === $station['id']) {
                throw new HttpException(409, 'ALREADY_AT_STATION', "{$wo['ma_code']} zaten {$station['name']} istasyonunda.");
            }

            // Koşullu güncelleme: arada başka bir cihaz tamamladıysa satır etkilenmez.
            $update = $this->db->prepare(
                "UPDATE work_orders
                    SET current_station_id = :station, status = :status, updated_at = datetime('now')
                  WHERE id = :id AND status IN ('open', 'in_progress')"
            );
            $update->execute([
                'id'      => $wo['id'],
                'station' => $station['id'],
                'status'  => $station['is_final'] ? 'done' : 'in_progress',
            ]);
            if ($update->rowCount() === 0) {
                throw new HttpException(409, 'WORK_ORDER_DONE', "{$wo['ma_code']} zaten tamamlanmış.");
            }

            $this->db->prepare(
                "INSERT INTO scans (work_order_id, station_id, user_id, kind, status) VALUES (:id, :station, :user, 'scan', :status)"
            )->execute([
                'id' => $wo['id'], 'station' => $station['id'], 'user' => $userId,
                'status' => $station['is_final'] ? 'done' : 'in_progress',
            ]);
            $scanId = (int) $this->db->lastInsertId();

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return ['work_order' => $this->get($wo['id']), 'scan_id' => $scanId];
    }

    /**
     * Yönetici taşıması: iş emri, durumundan bağımsız olarak istenen konuma alınır.
     * Hedef bir istasyon (son istasyonsa tamamlanır), Bekliyor (open) veya İptal (cancelled) olabilir.
     * Her taşıma geçmişe "move" olarak, yapan kullanıcıyla kaydedilir.
     *
     * @param array{station_id?: mixed, status?: mixed} $input
     */
    public function move(int $id, array $input, int $userId): array
    {
        if (isset($input['station_id'])) {
            $station   = $this->stations->getActive(Validator::positiveInt($input, 'station_id', 0));
            $stationId = $station['id'];
            $status    = $station['is_final'] ? 'done' : 'in_progress';
        } elseif (in_array($input['status'] ?? null, ['open', 'cancelled'], true)) {
            $stationId = null;
            $status    = $input['status'];
        } else {
            throw HttpException::validation('station_id', 'Hedef istasyon veya durum (open, cancelled) seçilmeli.');
        }

        $this->db->beginTransaction();
        try {
            $wo = $this->get($id);
            if ($wo['status'] === $status && $wo['current_station_id'] === $stationId) {
                throw new HttpException(409, 'NO_CHANGE', "{$wo['ma_code']} zaten bu konumda.");
            }

            $this->db->prepare(
                "UPDATE work_orders SET current_station_id = :station, status = :status, updated_at = datetime('now')
                  WHERE id = :id"
            )->execute(['id' => $id, 'station' => $stationId, 'status' => $status]);

            $this->db->prepare(
                "INSERT INTO scans (work_order_id, station_id, user_id, kind, status) VALUES (:id, :station, :user, 'move', :status)"
            )->execute(['id' => $id, 'station' => $stationId, 'user' => $userId, 'status' => $status]);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return $this->get($id);
    }

    private function findOne(string $condition, int|string $value): ?array
    {
        $stmt = $this->db->prepare(self::SELECT . " WHERE $condition LIMIT 1");
        $stmt->execute(['v' => $value]);
        $row = $stmt->fetch();
        return $row === false ? null : $this->cast($row);
    }

    private function cast(array $row): array
    {
        foreach (['id', 'po_id', 'quantity'] as $key) {
            $row[$key] = (int) $row[$key];
        }
        $row['current_station_id'] = $row['current_station_id'] === null ? null : (int) $row['current_station_id'];
        return $row;
    }
}
