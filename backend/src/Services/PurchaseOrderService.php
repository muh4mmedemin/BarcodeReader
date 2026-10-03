<?php

declare(strict_types=1);

namespace App\Services;

use App\Http\HttpException;
use PDO;
use PDOException;

final class PurchaseOrderService
{
    public function __construct(
        private readonly PDO $db,
        private readonly UniqueGuard $unique,
    ) {
    }

    /** @return array{items: list<array>, total: int} */
    public function list(?string $search, int $limit, int $offset): array
    {
        $where = '';
        $params = [];
        if ($search !== null && $search !== '') {
            $where = 'WHERE p.po_number LIKE :q OR p.customer LIKE :q';
            $params['q'] = '%' . $search . '%';
        }

        $total = $this->db->prepare("SELECT COUNT(*) FROM purchase_orders p $where");
        $total->execute($params);

        $stmt = $this->db->prepare(
            "SELECT p.*,
                    COUNT(w.id) AS work_order_count,
                    SUM(CASE WHEN w.status = 'done' THEN 1 ELSE 0 END) AS done_count
               FROM purchase_orders p
               LEFT JOIN work_orders w ON w.po_id = p.id
               $where
              GROUP BY p.id
              ORDER BY p.created_at DESC, p.id DESC
              LIMIT :limit OFFSET :offset"
        );
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $items = array_map(function (array $row): array {
            $row['work_order_count'] = (int) $row['work_order_count'];
            $row['done_count'] = (int) $row['done_count'];
            return $this->cast($row);
        }, $stmt->fetchAll());

        return ['items' => $items, 'total' => (int) $total->fetchColumn()];
    }

    public function get(int $id): array
    {
        $stmt = $this->db->prepare('SELECT * FROM purchase_orders WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw HttpException::notFound('PO bulunamadı.');
        }
        return $this->cast($row);
    }

    public function create(array $input): array
    {
        $poNumber = Validator::code($input, 'po_number', 'PO numarası');
        $this->unique->assertAvailable('po_number', $poNumber);

        try {
            $stmt = $this->db->prepare(
                'INSERT INTO purchase_orders (po_number, customer, note) VALUES (:po, :customer, :note)'
            );
            $stmt->execute([
                'po'       => $poNumber,
                'customer' => Validator::optionalText($input, 'customer', 200),
                'note'     => Validator::optionalText($input, 'note', 2000),
            ]);
        } catch (PDOException $e) {
            throw UniqueGuard::translate($e);
        }

        return $this->get((int) $this->db->lastInsertId());
    }

    public function update(int $id, array $input): array
    {
        $current = $this->get($id);

        $poNumber = array_key_exists('po_number', $input)
            ? Validator::code($input, 'po_number', 'PO numarası')
            : $current['po_number'];
        $this->unique->assertAvailable('po_number', $poNumber, $id);

        try {
            $stmt = $this->db->prepare(
                "UPDATE purchase_orders
                    SET po_number = :po, customer = :customer, note = :note, updated_at = datetime('now')
                  WHERE id = :id"
            );
            $stmt->execute([
                'id'       => $id,
                'po'       => $poNumber,
                'customer' => array_key_exists('customer', $input)
                    ? Validator::optionalText($input, 'customer', 200) : $current['customer'],
                'note'     => array_key_exists('note', $input)
                    ? Validator::optionalText($input, 'note', 2000) : $current['note'],
            ]);
        } catch (PDOException $e) {
            throw UniqueGuard::translate($e);
        }

        return $this->get($id);
    }

    public function delete(int $id): void
    {
        $this->get($id);
        $this->db->prepare('DELETE FROM purchase_orders WHERE id = :id')->execute(['id' => $id]);
    }

    private function cast(array $row): array
    {
        $row['id'] = (int) $row['id'];
        return $row;
    }
}
