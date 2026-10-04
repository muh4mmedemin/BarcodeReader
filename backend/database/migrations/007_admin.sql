-- migrate:no-foreign-keys
-- 1) Yönetici rolü: 'admin' yalnızca iş emirlerini istediği konuma taşıyabilir
--    (herhangi bir istasyon, Bekliyor veya İptal). Kullanıcılar üzerinde yetkisi yoktur.
--    CHECK kısıtı değiştirilemediği için users tablosu yeniden kurulur (bkz. 005).

CREATE TABLE users_new (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    username       TEXT    NOT NULL COLLATE NOCASE UNIQUE,
    password_hash  TEXT    NOT NULL,
    role           TEXT    NOT NULL CHECK (role IN ('rep', 'production', 'board', 'admin')),
    station_id     INTEGER REFERENCES stations(id),
    active         INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0, 1)),
    created_at     TEXT    NOT NULL DEFAULT (datetime('now')),
    CHECK (role <> 'production' OR station_id IS NOT NULL)
);

INSERT INTO users_new (id, username, password_hash, role, station_id, active, created_at)
SELECT id, username, password_hash, role, station_id, active, created_at FROM users;

DROP TABLE users;
ALTER TABLE users_new RENAME TO users;

INSERT INTO users (username, password_hash, role) VALUES ('admin', '$2y$12$.Euo63vCZAfDHNuxjBfz/uTrwOTPOzPVZff1a4enZ2YanMvCds2Em', 'admin');

-- 2) Hareket kayıtları: okutma (scan) veya yönetici taşıması (move).
--    status: hareketten sonraki iş emri durumu. Bekliyor/İptal'e taşımada station_id boştur.
ALTER TABLE scans ADD COLUMN kind TEXT NOT NULL DEFAULT 'scan' CHECK (kind IN ('scan', 'move'));
ALTER TABLE scans ADD COLUMN status TEXT CHECK (status IS NULL OR status IN ('open', 'in_progress', 'done', 'cancelled'));
