<?php

declare(strict_types=1);

namespace App\Services;

use App\Excel\XlsxTemplate;
use App\Http\HttpException;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * PO'yu Excel şablonuna doldurarak dışa aktarır.
 * Şablon: önce yüklenmiş özel şablon (storage), yoksa varsayılan (templates/po.xlsx).
 */
final class PoExportService
{
    private const STATUS = ['open' => 'Bekliyor', 'in_progress' => 'Hatta', 'done' => 'Tamamlandı', 'cancelled' => 'İptal'];
    private const MAX_TEMPLATE_BYTES = 5 * 1024 * 1024;

    /** Şablonda kullanılabilen yer tutucular: anahtar => [grup, açıklama, örnek] */
    public const PLACEHOLDERS = [
        'po_number'        => ['Genel', 'PO numarası', 'PO-2026-001'],
        'customer'         => ['Genel', 'Müşteri', 'Örnek Müşteri A.Ş.'],
        'note'             => ['Genel', 'PO notu', ''],
        'due_date'         => ['Genel', 'Termin tarihi', '15.10.2026'],
        'due_status'       => ['Genel', 'Termin durumu', '3 gün gecikti'],
        'created_at'       => ['Genel', 'PO kayıt zamanı', '01.10.2026 09:30'],
        'total'            => ['Genel', 'Toplam iş emri (sayı)', 12],
        'open'             => ['Genel', 'Bekleyen iş emri (sayı)', 3],
        'in_progress'      => ['Genel', 'Hattaki iş emri (sayı)', 5],
        'done'             => ['Genel', 'Tamamlanan iş emri (sayı)', 4],
        'cancelled'        => ['Genel', 'İptal iş emri (sayı)', 0],
        'completion'       => ['Genel', 'Tamamlanma yüzdesi (metin)', '%33'],
        'completion_ratio' => ['Genel', 'Tamamlanma oranı (sayı, 0–1; hücreyi % biçimlendirin)', 0.33],
        'export_date'      => ['Genel', 'Raporun alındığı zaman', '05.10.2026 14:20'],
        'exported_by'      => ['Genel', 'Raporu alan kullanıcı', 'mami'],

        'wo.no'            => ['İş emri satırı', 'Sıra no', 1],
        'wo.ma_code'       => ['İş emri satırı', 'MA kodu', 'MA-0001'],
        'wo.barcode'       => ['İş emri satırı', 'Barkod', '8690000000011'],
        'wo.description'   => ['İş emri satırı', 'Açıklama', 'Gövde'],
        'wo.quantity'      => ['İş emri satırı', 'Adet (sayı)', 3],
        'wo.status'        => ['İş emri satırı', 'Durum: Bekliyor / Hatta / Tamamlandı / İptal', 'Hatta'],
        'wo.station'       => ['İş emri satırı', 'Bulunduğu istasyon', 'Freze'],
        'wo.location'      => ['İş emri satırı', 'Konum: hattaysa istasyon, değilse durum', 'Freze'],
        'wo.last_scan'     => ['İş emri satırı', 'Son okutma zamanı', '04.10.2026 11:05'],
        'wo.created_at'    => ['İş emri satırı', 'İş emri kayıt zamanı', '01.10.2026 09:31'],
        'wo.route'         => ['İş emri satırı', 'Geçtiği istasyonlar ve zamanları', 'Elektrik 02.10 08:10 → Freze 03.10 14:00'],
        'wo.lead_time'     => ['İş emri satırı', 'Kayıttan tamamlanmaya geçen süre', '3 g 4 sa'],

        'scan.no'          => ['Geçmiş satırı', 'Sıra no', 1],
        'scan.ma_code'     => ['Geçmiş satırı', 'MA kodu', 'MA-0001'],
        'scan.barcode'     => ['Geçmiş satırı', 'Barkod', '8690000000011'],
        'scan.station'     => ['Geçmiş satırı', 'Konum (ilk satır: Kayıt)', 'Elektrik'],
        'scan.date'        => ['Geçmiş satırı', 'Tarih ve saat', '02.10.2026 08:10'],
        'scan.user'        => ['Geçmiş satırı', 'Okutan kullanıcı', 'elektrik'],
        'scan.duration'    => ['Geçmiş satırı', 'O konumda geçen süre', '1 g 5 sa'],

        'loc.name'         => ['Dağılım satırı', 'Konum adı', 'Freze'],
        'loc.count'        => ['Dağılım satırı', 'İş emri sayısı (sayı)', 5],
        'loc.percent'      => ['Dağılım satırı', 'Yüzde (metin)', '%42'],
        'loc.ratio'        => ['Dağılım satırı', 'Oran (sayı, 0–1)', 0.42],
    ];

    private readonly DateTimeZone $tz;

    public function __construct(
        private readonly PDO $db,
        private readonly PurchaseOrderService $purchaseOrders,
        private readonly WorkOrderService $workOrders,
        private readonly StationService $stations,
        private readonly string $defaultTemplate,
        private readonly string $customTemplate,
    ) {
        $this->tz = new DateTimeZone(date_default_timezone_get());
    }

    /** @return array{filename: string, content: string} */
    public function export(int $poId, array $user): array
    {
        $po = $this->purchaseOrders->get($poId);
        $wos = $this->workOrders->listByPo($poId);
        [$values, $lists] = $this->buildData($po, $wos, $user['username']);

        $content = (new XlsxTemplate($this->templateBytes()))->render($values, $lists);
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', $po['po_number']);
        return ['filename' => "$safe.xlsx", 'content' => $content];
    }

    // ---------- Şablon yönetimi ----------

    public function templateInfo(): array
    {
        $custom = is_file($this->customTemplate);
        $path = $custom ? $this->customTemplate : $this->defaultTemplate;
        return [
            'custom'       => $custom,
            'size'         => is_file($path) ? filesize($path) : 0,
            'updated_at'   => $custom ? gmdate('Y-m-d H:i:s', (int) filemtime($path)) : null,
            'placeholders' => array_map(
                static fn (string $key, array $d): array => ['key' => $key, 'group' => $d[0], 'description' => $d[1]],
                array_keys(self::PLACEHOLDERS),
                self::PLACEHOLDERS
            ),
        ];
    }

    /** @return array{filename: string, content: string} */
    public function templateFile(): array
    {
        return ['filename' => 'po-sablon.xlsx', 'content' => $this->templateBytes()];
    }

    /** Yüklenen dosyayı doğrular (örnek veriyle doldurmayı dener) ve özel şablon olarak kaydeder. */
    public function saveTemplate(string $bytes): void
    {
        if (strlen($bytes) > self::MAX_TEMPLATE_BYTES) {
            throw HttpException::validation('file', 'Şablon en fazla 5 MB olabilir.');
        }
        try {
            [$values, $lists] = $this->sampleData();
            (new XlsxTemplate($bytes))->render($values, $lists);
        } catch (\Throwable $e) {
            throw HttpException::validation('file', 'Şablon kullanılamıyor: ' . $e->getMessage());
        }

        $dir = dirname($this->customTemplate);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Şablon klasörü oluşturulamadı: $dir");
        }
        $tmp = $this->customTemplate . '.tmp';
        file_put_contents($tmp, $bytes);
        rename($tmp, $this->customTemplate);
    }

    public function resetTemplate(): void
    {
        if (is_file($this->customTemplate)) {
            unlink($this->customTemplate);
        }
    }

    private function templateBytes(): string
    {
        $path = is_file($this->customTemplate) ? $this->customTemplate : $this->defaultTemplate;
        $bytes = @file_get_contents($path);
        if ($bytes === false) {
            throw new \RuntimeException("Excel şablonu bulunamadı: $path");
        }
        return $bytes;
    }

    // ---------- Veri ----------

    /** @return array{array<string, scalar|null>, array<string, list<array>>} */
    private function buildData(array $po, array $wos, string $username): array
    {
        $count = static fn (string $status): int => count(array_filter($wos, static fn ($w) => $w['status'] === $status));
        $total = count($wos);
        $done = $count('done');
        $finished = $done + $count('cancelled');
        $complete = $total > 0 && $finished === $total;

        $values = [
            'po_number'        => $po['po_number'],
            'customer'         => $po['customer'] ?? '',
            'note'             => $po['note'] ?? '',
            'due_date'         => $po['due_date'] ? $this->date($po['due_date']) : '',
            'due_status'       => $this->dueStatus($po['due_date'], $complete),
            'created_at'       => $this->dateTime($po['created_at']),
            'total'            => $total,
            'open'             => $count('open'),
            'in_progress'      => $count('in_progress'),
            'done'             => $done,
            'cancelled'        => $count('cancelled'),
            'completion'       => $total ? '%' . round($done / $total * 100) : '—',
            'completion_ratio' => $total ? round($done / $total, 4) : 0,
            'export_date'      => (new DateTimeImmutable('now', $this->tz))->format('d.m.Y H:i'),
            'exported_by'      => $username,
        ];

        // Okutmalar (tek sorgu), iş emri bazında
        $scans = [];
        if ($wos) {
            $stmt = $this->db->prepare(
                'SELECT sc.work_order_id, sc.scanned_at, s.name AS station_name, s.is_final, u.username, sc.status
                   FROM scans sc
                   JOIN work_orders w ON w.id = sc.work_order_id
                   LEFT JOIN stations s ON s.id = sc.station_id
                   LEFT JOIN users u ON u.id = sc.user_id
                  WHERE w.po_id = :po
                  ORDER BY sc.scanned_at, sc.id'
            );
            $stmt->execute(['po' => $po['id']]);
            foreach ($stmt->fetchAll() as $s) {
                // Bekliyor / İptal'e taşımada istasyon yoktur; konum durum adıdır
                $s['station_name'] ??= self::STATUS[$s['status'] ?? ''] ?? '?';
                $scans[(int) $s['work_order_id']][] = $s;
            }
        }

        $woRows = [];
        $scanRows = [];
        $now = time();
        foreach ($wos as $n => $wo) {
            $woScans = $scans[$wo['id']] ?? [];
            $last = $woScans ? end($woScans) : null;
            $final = null;
            foreach ($woScans as $s) {
                if ($s['is_final']) {
                    $final = $s;
                }
            }

            $woRows[] = [
                'no'          => $n + 1,
                'ma_code'     => $wo['ma_code'],
                'barcode'     => $wo['barcode'],
                'description' => $wo['description'] ?? '',
                'quantity'    => $wo['quantity'],
                'status'      => self::STATUS[$wo['status']] ?? $wo['status'],
                'station'     => $wo['current_station_name'] ?? '',
                'location'    => $wo['status'] === 'in_progress' && $wo['current_station_name']
                    ? $wo['current_station_name'] : (self::STATUS[$wo['status']] ?? $wo['status']),
                'last_scan'   => $last ? $this->dateTime($last['scanned_at']) : '',
                'created_at'  => $this->dateTime($wo['created_at']),
                'route'       => implode(' → ', array_map(
                    fn ($s) => ($s['station_name'] ?? '?') . ' ' . $this->dateTime($s['scanned_at'], 'd.m H:i'),
                    $woScans
                )),
                'lead_time'   => $final && $wo['status'] === 'done'
                    ? self::duration($this->ts($final['scanned_at']) - $this->ts($wo['created_at'])) : '',
            ];

            // Geçmiş: kayıt + her okutma, o konumda geçen süreyle
            $events = array_merge(
                [['at' => $wo['created_at'], 'place' => 'Kayıt (bekliyor)', 'user' => '']],
                array_map(static fn ($s) => ['at' => $s['scanned_at'], 'place' => $s['station_name'] ?? '?', 'user' => $s['username'] ?? ''], $woScans)
            );
            foreach ($events as $k => $ev) {
                $next = $events[$k + 1] ?? null;
                if ($next) {
                    $spent = self::duration($this->ts($next['at']) - $this->ts($ev['at']));
                } elseif (!in_array($wo['status'], ['done', 'cancelled'], true)) {
                    $spent = self::duration($now - $this->ts($ev['at'])) . ' (sürüyor)';
                } else {
                    $spent = '';
                }
                $scanRows[] = [
                    'no'       => count($scanRows) + 1,
                    'ma_code'  => $wo['ma_code'],
                    'barcode'  => $wo['barcode'],
                    'station'  => $ev['place'],
                    'date'     => $this->dateTime($ev['at']),
                    'user'     => $ev['user'],
                    'duration' => $spent,
                ];
            }
        }

        return [$values, ['wo' => $woRows, 'scan' => $scanRows, 'loc' => $this->distribution($wos)]];
    }

    /** Konum dağılımı: Bekliyor / aktif istasyonlar (son hariç) / diğer istasyonlar / Tamamlandı / İptal */
    private function distribution(array $wos): array
    {
        $total = count($wos);
        $groups = [['Bekliyor', fn ($w) => $w['status'] === 'open']];
        $listed = [];
        foreach ($this->stations->list() as $s) {
            if ($s['is_final']) {
                continue;
            }
            $listed[$s['id']] = true;
            $groups[] = [$s['name'], fn ($w) => $w['status'] === 'in_progress' && $w['current_station_id'] === $s['id']];
        }
        foreach ($wos as $w) {
            if ($w['status'] === 'in_progress' && !isset($listed[$w['current_station_id']])) {
                $name = $w['current_station_name'] ?? '?';
                $listed[$w['current_station_id']] = true;
                $groups[] = [$name, fn ($x) => $x['status'] === 'in_progress' && $x['current_station_id'] === $w['current_station_id']];
            }
        }
        $groups[] = ['Tamamlandı', fn ($w) => $w['status'] === 'done'];
        if (array_filter($wos, fn ($w) => $w['status'] === 'cancelled')) {
            $groups[] = ['İptal', fn ($w) => $w['status'] === 'cancelled'];
        }

        return array_map(static function (array $g) use ($wos, $total): array {
            $n = count(array_filter($wos, $g[1]));
            return [
                'name'    => $g[0],
                'count'   => $n,
                'percent' => $total ? '%' . round($n / $total * 100) : '—',
                'ratio'   => $total ? round($n / $total, 4) : 0,
            ];
        }, $groups);
    }

    /** Şablon doğrulaması için örnek veri */
    private function sampleData(): array
    {
        $values = [];
        $lists = ['wo' => [[]], 'scan' => [[]], 'loc' => [[]]];
        foreach (self::PLACEHOLDERS as $key => [, , $example]) {
            if (str_contains($key, '.')) {
                [$list, $field] = explode('.', $key, 2);
                $lists[$list][0][$field] = $example;
            } else {
                $values[$key] = $example;
            }
        }
        return [$values, $lists];
    }

    private function dueStatus(?string $due, bool $complete): string
    {
        if (!$due) {
            return '';
        }
        if ($complete) {
            return 'Tamamlandı';
        }
        $today = new DateTimeImmutable('today', $this->tz);
        $left = (int) $today->diff(new DateTimeImmutable($due, $this->tz))->format('%r%a');
        return match (true) {
            $left < 0  => -$left . ' gün gecikti',
            $left === 0 => 'Termin bugün',
            default    => $left . ' gün kaldı',
        };
    }

    private function ts(string $utc): int
    {
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->getTimestamp();
    }

    /** UTC "Y-m-d H:i:s" → yerel saat */
    private function dateTime(string $utc, string $format = 'd.m.Y H:i'): string
    {
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone($this->tz)->format($format);
    }

    private function date(string $ymd): string
    {
        return (new DateTimeImmutable($ymd))->format('d.m.Y');
    }

    /** "2 g 3 sa", "1 sa 20 dk", "45 dk", "<1 dk" */
    private static function duration(int $seconds): string
    {
        $min = intdiv(max(0, $seconds), 60);
        if ($min < 1) {
            return '<1 dk';
        }
        $d = intdiv($min, 1440);
        $h = intdiv($min % 1440, 60);
        $m = $min % 60;
        return $d ? "$d g $h sa" : ($h ? "$h sa $m dk" : "$m dk");
    }
}
