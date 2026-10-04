-- migrate:no-foreign-keys
-- 1) PO termin tarihi (YYYY-MM-DD, isteğe bağlı)
ALTER TABLE purchase_orders ADD COLUMN due_date TEXT;

-- 2) Atölye panosu rolü: 'board' yalnızca pano ekranını görür.
--    SQLite'ta CHECK kısıtı değiştirilemediği için users tablosu yeniden kurulur.
--    Yabancı anahtarlar kapalı çalışır (ilk satırdaki işaret); id'ler korunduğu için
--    sessions/scans referansları bozulmaz, commit öncesi foreign_key_check doğrular.

CREATE TABLE users_new (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    username       TEXT    NOT NULL COLLATE NOCASE UNIQUE,
    password_hash  TEXT    NOT NULL,
    role           TEXT    NOT NULL CHECK (role IN ('rep', 'production', 'board')),
    station_id     INTEGER REFERENCES stations(id),
    active         INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0, 1)),
    created_at     TEXT    NOT NULL DEFAULT (datetime('now')),
    CHECK (role <> 'production' OR station_id IS NOT NULL)
);

INSERT INTO users_new (id, username, password_hash, role, station_id, active, created_at)
SELECT id, username, password_hash, role, station_id, active, created_at FROM users;

DROP TABLE users;
ALTER TABLE users_new RENAME TO users;

INSERT INTO users (username, password_hash, role) VALUES ('pano', '$2y$12$nUlbe8VqE059x3Xi0jifJ.fGf.9J3Y/0Z.LmBz7jAh8LTPV0Nukxu', 'board');
