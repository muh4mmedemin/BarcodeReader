<?php

declare(strict_types=1);

// Ayarlar ortam değişkenleriyle ezilebilir (BARKOD_DB_PATH, BARKOD_CORS_ORIGINS ...).
return [
    'db_path' => getenv('BARKOD_DB_PATH') ?: __DIR__ . '/storage/app.sqlite',

    // Virgülle ayrılmış izinli origin listesi. "*" = hepsi (geliştirme için).
    'cors_origins' => getenv('BARKOD_CORS_ORIGINS') ?: '*',

    // Tarihlerin gösterildiği saat dilimi (veritabanında UTC saklanır).
    'timezone' => getenv('BARKOD_TIMEZONE') ?: 'Europe/Istanbul',

    // Excel dışa aktarma şablonları: yüklenen özel şablon varsa o, yoksa varsayılan kullanılır.
    'po_template_default' => __DIR__ . '/templates/po.xlsx',
    'po_template_custom'  => getenv('BARKOD_PO_TEMPLATE') ?: __DIR__ . '/storage/templates/po.xlsx',
];
