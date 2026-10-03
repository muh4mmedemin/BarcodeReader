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
    public function scan(string $barcode, int $stationId): array
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

            $this->db->prepare('INSERT INTO scans (work_order_id, station_id) VALUES (:id, :station)')
                ->execute(['id' => $wo['id'], 'station' => $station['id']]);
            $scanId = (int) $this->db->lastInsertId();

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return ['work_order' => $this->get($wo['id']), 'scan_id' => $scanId];
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
