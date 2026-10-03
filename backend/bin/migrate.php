<?php

declare(strict_types=1);

// Kullanım: php backend/bin/migrate.php [--seed]
// Bekleyen migration'ları uygular (her istekte de otomatik çalışır; bu script elle kontrol içindir).

use App\Database;
use App\Services\PurchaseOrderService;
use App\Services\StationService;
use App\Services\UniqueGuard;
use App\Services\WorkOrderService;

$config = require __DIR__ . '/../bootstrap.php';

// connect() bekleyen migration'ları zaten uygular.
$db = Database::connect($config['db_path']);
$versions = $db->query('SELECT version FROM schema_migrations ORDER BY version')->fetchAll(PDO::FETCH_COLUMN);
echo "Veritabanı: {$config['db_path']}\nUygulanmış migration'lar: " . implode(', ', $versions) . "\n";

if (in_array('--seed', $argv, true)) {
    $unique = new UniqueGuard($db);
    $pos    = new PurchaseOrderService($db, $unique);
    $wos    = new WorkOrderService($db, $unique, $pos, new StationService($db));

    if (!$unique->isAvailable('po_number', 'PO-2026-001')) {
        echo "Örnek veri zaten var, atlandı.\n";
        exit(0);
    }

    $po = $pos->create(['po_number' => 'PO-2026-001', 'customer' => 'Örnek Müşteri A.Ş.']);
    $wos->create($po['id'], ['ma_code' => 'MA-0001', 'barcode' => '8690000000011', 'quantity' => 3, 'description' => 'Gövde']);
    $wos->create($po['id'], ['ma_code' => 'MA-0002', 'barcode' => '8690000000028', 'quantity' => 1, 'description' => 'Kapak']);
    echo "Örnek veri eklendi.\n";
}
