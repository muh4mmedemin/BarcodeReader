<?php

declare(strict_types=1);

// Ayarlar ortam değişkenleriyle ezilebilir (BARKOD_DB_PATH, BARKOD_CORS_ORIGINS ...).
return [
    'db_path' => getenv('BARKOD_DB_PATH') ?: __DIR__ . '/storage/app.sqlite',

    // Virgülle ayrılmış izinli origin listesi. "*" = hepsi (geliştirme için).
    'cors_origins' => getenv('BARKOD_CORS_ORIGINS') ?: '*',

    // Her client kendi API anahtarı ile gelir; anahtar hangi rolde olduğunu belirler.
    //   rep        -> müşteri temsilcisi: PO ve iş emri yönetimi
    //   production -> üretim: sadece barkod sorgulama ve okutma
    // Üretimde bu anahtarları mutlaka değiştirin (ortam değişkeni ile).
    'api_keys' => [
        (getenv('BARKOD_REP_KEY') ?: 'dev-rep-key')               => 'rep',
        (getenv('BARKOD_PRODUCTION_KEY') ?: 'dev-production-key') => 'production',
    ],
];
