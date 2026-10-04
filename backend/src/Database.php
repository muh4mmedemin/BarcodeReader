<?php

declare(strict_types=1);

namespace App;

use PDO;

final class Database
{
    private const MIGRATIONS_DIR = __DIR__ . '/../database/migrations';

    public static function connect(string $path): PDO
    {
        if (!extension_loaded('pdo_sqlite')) {
            throw new \RuntimeException('pdo_sqlite PHP eklentisi yüklü değil.');
        }

        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $pdo = new PDO('sqlite:' . $path, options: [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');   // Aynı anda okuma/yazma yapan cihazlar için
        $pdo->exec('PRAGMA busy_timeout = 5000');

        self::migrate($pdo);

        return $pdo;
    }

    /**
     * database/migrations/*.sql dosyalarını isim sırasıyla, her biri bir kez olacak şekilde uygular.
     * Şema değişikliği = yeni numaralı dosya (ör. 003_xxx.sql). Uygulanmış dosya değiştirilmez.
     *
     * @return list<string> Bu çağrıda uygulanan migration'lar
     */
    public static function migrate(PDO $pdo): array
    {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS schema_migrations (
                version    TEXT PRIMARY KEY,
                applied_at TEXT NOT NULL DEFAULT (datetime('now'))
            )"
        );
        $applied = $pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);

        $files = glob(self::MIGRATIONS_DIR . '/*.sql') ?: [];
        sort($files);

        $ran = [];
        foreach ($files as $file) {
            $version = basename($file, '.sql');
            if (in_array($version, $applied, true)) {
                continue;
            }

            $sql = (string) file_get_contents($file);

            // Tablo yeniden kurulan migration'lar (SQLite'ta CHECK/sütun değişikliği) yabancı
            // anahtarlar kapalıyken çalışmalı; bunu dosyanın ilk satırındaki işaret belirtir.
            // Commit'ten önce foreign_key_check ile bütünlük doğrulanır.
            $noForeignKeys = str_starts_with($sql, '-- migrate:no-foreign-keys');
            if ($noForeignKeys) {
                $pdo->exec('PRAGMA foreign_keys = OFF');
            }

            $pdo->beginTransaction();
            try {
                $pdo->exec($sql);
                if ($noForeignKeys && $pdo->query('PRAGMA foreign_key_check')->fetch() !== false) {
                    throw new \RuntimeException('yabancı anahtar bütünlüğü bozuldu');
                }
                $pdo->prepare('INSERT INTO schema_migrations (version) VALUES (:v)')->execute(['v' => $version]);
                $pdo->commit();
            } catch (\Throwable $e) {
                $pdo->rollBack();
                throw new \RuntimeException("Migration başarısız: $version — " . $e->getMessage(), 0, $e);
            } finally {
                if ($noForeignKeys) {
                    $pdo->exec('PRAGMA foreign_keys = ON');
                }
            }
            $ran[] = $version;
        }

        return $ran;
    }
}
