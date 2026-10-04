<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * Analiz ve pano verileri. Süreler okutma zamanlarından hesaplanır:
 * bir istasyonda geçen süre = iş emrinin o istasyondaki okutması ile bir sonraki okutması arası.
 * Zamanlar UTC saklanır; "gün" hesapları sunucunun yerel saatine göre yapılır.
 */
final class ReportService
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** İstasyon süre analizi, son $days gün. */
    public function stations(int $days): array
    {
        $since = "-$days days";

        // LEAD tüm okutmalar üzerinde hesaplanır, dönem filtresi sonra uygulanır:
        // dönem başından önce başlayıp dönem içinde biten geçişler de doğru ölçülür.
        $stmt = $this->db->prepare(
            "WITH s AS (
                SELECT sc.work_order_id, sc.station_id, sc.scanned_at,
                       LEAD(sc.scanned_at) OVER (PARTITION BY sc.work_order_id ORDER BY sc.scanned_at, sc.id) AS next_at
                  FROM scans sc
             )
             SELECT st.id, st.name, st.sort_order, st.is_final, st.active,
                    COUNT(s.station_id) AS passes,
                    SUM(s.next_at IS NOT NULL) AS measured,
                    AVG(CASE WHEN s.next_at IS NOT NULL THEN (julianday(s.next_at) - julianday(s.scanned_at)) * 86400 END) AS avg_seconds,
                    MAX(CASE WHEN s.next_at IS NOT NULL THEN (julianday(s.next_at) - julianday(s.scanned_at)) * 86400 END) AS max_seconds
               FROM stations st
               LEFT JOIN s ON s.station_id = st.id AND s.scanned_at >= datetime('now', :since)
              GROUP BY st.id
             HAVING st.active = 1 OR COUNT(s.station_id) > 0
              ORDER BY st.sort_order, st.id"
        );
        $stmt->execute(['since' => $since]);
        $wip = $this->wipByStation();

        $stations = array_map(static fn (array $r): array => [
            'id'          => (int) $r['id'],
            'name'        => $r['name'],
            'is_final'    => (bool) $r['is_final'],
            'active'      => (bool) $r['active'],
            'passes'      => (int) $r['passes'],
            'measured'    => (int) $r['measured'],
            'avg_seconds' => $r['avg_seconds'] === null ? null : (int) round((float) $r['avg_seconds']),
            'max_seconds' => $r['max_seconds'] === null ? null : (int) round((float) $r['max_seconds']),
            'wip'         => $wip[(int) $r['id']] ?? 0,
        ], $stmt->fetchAll());

        // Kayıttan ilk istasyona bekleme (ilk okutması dönem içinde olan iş emirleri)
        $queue = $this->db->prepare(
            "SELECT AVG((julianday(f.first_at) - julianday(w.created_at)) * 86400) AS avg_seconds, COUNT(*) AS n
               FROM work_orders w
               JOIN (SELECT work_order_id, MIN(scanned_at) AS first_at FROM scans GROUP BY work_order_id) f
                 ON f.work_order_id = w.id
              WHERE f.first_at >= datetime('now', :since)"
        );
        $queue->execute(['since' => $since]);
        $queueRow = $queue->fetch();

        // Toplam üretim süresi: kayıt → son istasyon (dönem içinde tamamlananlar)
        $lead = $this->db->prepare(
            "SELECT AVG((julianday(sc.scanned_at) - julianday(w.created_at)) * 86400) AS avg_seconds, COUNT(*) AS n
               FROM scans sc
               JOIN stations st ON st.id = sc.station_id AND st.is_final = 1
               JOIN work_orders w ON w.id = sc.work_order_id
              WHERE sc.scanned_at >= datetime('now', :since)"
        );
        $lead->execute(['since' => $since]);
        $leadRow = $lead->fetch();

        // Günlük okutma ve tamamlanma (yerel gün)
        $daily = $this->db->prepare(
            "SELECT date(sc.scanned_at, 'localtime') AS day, COUNT(*) AS scans, SUM(st.is_final) AS completed
               FROM scans sc
               JOIN stations st ON st.id = sc.station_id
              WHERE sc.scanned_at >= datetime('now', :since)
              GROUP BY day
              ORDER BY day"
        );
        $daily->execute(['since' => $since]);

        $open = (int) $this->db->query("SELECT COUNT(*) FROM work_orders WHERE status = 'open'")->fetchColumn();

        return [
            'days'     => $days,
            'today'    => $this->today(),
            'stations' => $stations,
            'open'     => $open,
            'queue'    => self::avgRow($queueRow),
            'lead'     => self::avgRow($leadRow),
            'daily'    => array_map(static fn (array $r): array => [
                'day'       => $r['day'],
                'scans'     => (int) $r['scans'],
                'completed' => (int) $r['completed'],
            ], $daily->fetchAll()),
        ];
    }

    /** Atölye panosu: istasyon başına bekleyen iş, bugünün sayıları, son okutmalar, termin durumu. */
    public function board(): array
    {
        $wip = $this->wipByStation();
        $stations = [];
        foreach ($this->db->query('SELECT id, name, is_final, active FROM stations ORDER BY sort_order, id') as $s) {
            $count = $wip[(int) $s['id']] ?? 0;
            if ((int) $s['active'] === 1 || $count > 0) {
                $stations[] = [
                    'id'       => (int) $s['id'],
                    'name'     => $s['name'],
                    'is_final' => (bool) $s['is_final'],
                    'active'   => (bool) $s['active'],
                    'wip'      => $count,
                ];
            }
        }

        $today = $this->db->query(
            "SELECT COUNT(*) AS scans, COALESCE(SUM(st.is_final), 0) AS completed
               FROM scans sc
               JOIN stations st ON st.id = sc.station_id
              WHERE date(sc.scanned_at, 'localtime') = date('now', 'localtime')"
        )->fetch();

        $recent = $this->db->query(
            "SELECT sc.scanned_at, w.ma_code, p.po_number, st.name AS station_name, st.is_final
               FROM scans sc
               JOIN work_orders w ON w.id = sc.work_order_id
               JOIN purchase_orders p ON p.id = w.po_id
               JOIN stations st ON st.id = sc.station_id
              ORDER BY sc.scanned_at DESC, sc.id DESC
              LIMIT 12"
        )->fetchAll();

        return [
            'version'       => $this->boardVersion(),
            'today'         => $this->today(),
            'stations'      => $stations,
            'open'          => (int) $this->db->query("SELECT COUNT(*) FROM work_orders WHERE status = 'open'")->fetchColumn(),
            'scans_today'   => (int) $today['scans'],
            'done_today'    => (int) $today['completed'],
            'recent'        => array_map(static fn (array $r): array => [
                'scanned_at'   => $r['scanned_at'],
                'ma_code'      => $r['ma_code'],
                'po_number'    => $r['po_number'],
                'station_name' => $r['station_name'],
                'is_final'     => (bool) $r['is_final'],
            ], $recent),
            'late'          => $this->duePos("p.due_date < date('now', 'localtime')"),
            'due_soon'      => $this->duePos("p.due_date BETWEEN date('now', 'localtime') AND date('now', 'localtime', '+2 days')"),
        ];
    }

    /**
     * Pano verisinin parmak izi: okutma, iş emri ve PO tablolarındaki herhangi bir değişiklikte
     * (ekleme, silme, güncelleme) ve gün dönümünde değişir. Pano bunu sık sorar,
     * değişmişse tüm veriyi çeker. Sorgular birincil anahtar / küçük tablo taraması kadar ucuzdur.
     */
    public function boardVersion(): string
    {
        $row = $this->db->query(
            "SELECT (SELECT COALESCE(MAX(id), 0) FROM scans)               AS scan_max,
                    (SELECT COUNT(*) FROM scans)                           AS scan_count,
                    (SELECT COUNT(*) FROM work_orders)                     AS wo_count,
                    (SELECT COALESCE(MAX(updated_at), '') FROM work_orders) AS wo_updated,
                    (SELECT COUNT(*) FROM purchase_orders)                 AS po_count,
                    (SELECT COALESCE(MAX(updated_at), '') FROM purchase_orders) AS po_updated,
                    date('now', 'localtime')                               AS today"
        )->fetch();
        return substr(hash('sha256', implode('|', $row)), 0, 16);
    }

    /** Termin koşuluna uyan ve tamamlanmamış (bitmemiş iş emri olan ya da hiç iş emri olmayan) PO'lar. */
    private function duePos(string $condition): array
    {
        $rows = $this->db->query(
            "SELECT p.id, p.po_number, p.customer, p.due_date,
                    COUNT(w.id) AS total,
                    COALESCE(SUM(w.status IN ('done', 'cancelled')), 0) AS finished
               FROM purchase_orders p
               LEFT JOIN work_orders w ON w.po_id = p.id
              WHERE p.due_date IS NOT NULL AND $condition
              GROUP BY p.id
             HAVING total = 0 OR finished < total
              ORDER BY p.due_date, p.po_number"
        )->fetchAll();

        return array_map(static fn (array $r): array => [
            'id'        => (int) $r['id'],
            'po_number' => $r['po_number'],
            'customer'  => $r['customer'],
            'due_date'  => $r['due_date'],
            'total'     => (int) $r['total'],
            'finished'  => (int) $r['finished'],
        ], $rows);
    }

    /** @return array<int, int> istasyon id => o istasyonda bekleyen (üretimdeki) iş emri sayısı */
    private function wipByStation(): array
    {
        $wip = [];
        $rows = $this->db->query(
            "SELECT current_station_id, COUNT(*) AS n FROM work_orders
              WHERE status = 'in_progress' AND current_station_id IS NOT NULL
              GROUP BY current_station_id"
        );
        foreach ($rows as $r) {
            $wip[(int) $r['current_station_id']] = (int) $r['n'];
        }
        return $wip;
    }

    private function today(): string
    {
        return (string) $this->db->query("SELECT date('now', 'localtime')")->fetchColumn();
    }

    private static function avgRow(array|false $row): array
    {
        return [
            'avg_seconds' => $row === false || $row['avg_seconds'] === null ? null : (int) round((float) $row['avg_seconds']),
            'count'       => $row === false ? 0 : (int) $row['n'],
        ];
    }
}
