-- Kullanıcı girişi.
--   rep        : PO / iş emri yönetimi + barkod okutma (istasyonu ekrandan seçer)
--   production : sadece barkod okutma; istasyonu hesabına sabittir (station_id)
-- Başlangıç şifreleri "123" (bcrypt). Gerçek kullanıma geçmeden değiştirin.

CREATE TABLE users (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    username       TEXT    NOT NULL COLLATE NOCASE UNIQUE,
    password_hash  TEXT    NOT NULL,
    role           TEXT    NOT NULL CHECK (role IN ('rep', 'production')),
    station_id     INTEGER REFERENCES stations(id),
    active         INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0, 1)),
    created_at     TEXT    NOT NULL DEFAULT (datetime('now')),
    CHECK (role <> 'production' OR station_id IS NOT NULL)
);

-- Oturumlar: token'ın kendisi değil SHA-256 özeti saklanır.
CREATE TABLE sessions (
    token_hash  TEXT    PRIMARY KEY,
    user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    created_at  TEXT    NOT NULL DEFAULT (datetime('now')),
    expires_at  TEXT    NOT NULL
);

CREATE INDEX idx_sessions_user ON sessions(user_id);

INSERT INTO users (username, password_hash, role, station_id) VALUES
    ('mami', '$2y$12$mX7QGaYhcxenvIsQVus3rOP06WXVW1eLkNuuq8rSSUYG86GFE5oAu', 'rep', NULL),
    ('kesim', '$2y$12$yuHlMVBtLy58qZX8wgRiUubuy5HAsP3omtkzCajFUn5jGbAsWxtwO', 'production', (SELECT id FROM stations WHERE code = 'KESIM')),
    ('bukum', '$2y$12$dJHr87moZ6QbinQIBOYa3uchrbnnmuGwc1GCaKenVuZ/44sNuQq2O', 'production', (SELECT id FROM stations WHERE code = 'BUKUM')),
    ('kaynak', '$2y$12$CGMJRBEfokIPxz24V6tsje69phiOQwLpyyJXtHSNGwZDI8WVSwI9S', 'production', (SELECT id FROM stations WHERE code = 'KAYNAK')),
    ('taslama', '$2y$12$O.f7rLP/zu3ae6HjLew.lOLDVeopSzXxZJ6/.P/MRNBuv.rNfNdQy', 'production', (SELECT id FROM stations WHERE code = 'TASLAMA')),
    ('boya', '$2y$12$SooBN48EbcyQnzIgmgVm0uMUJLvwqHiOWZgfjg2OPMrYIqBs8i9Tq', 'production', (SELECT id FROM stations WHERE code = 'BOYA')),
    ('montaj', '$2y$12$s.1XYLO4LaBsMTjvFuxS2e7sqMgnzTGNxVcaKa5zDv7xkRiFhfna2', 'production', (SELECT id FROM stations WHERE code = 'MONTAJ')),
    ('kalite', '$2y$12$D66brTD66eyb7Y8.4B5h1OFASjbXkBe6CSuSqhWLx1r6U2V7zRBA6', 'production', (SELECT id FROM stations WHERE code = 'KALITE')),
    ('paketleme', '$2y$12$4pwvSWYQH5wxIL95EZmM1.ByMWlCpVLC8bbu5.KptHkc3xVcCMk/S', 'production', (SELECT id FROM stations WHERE code = 'PAKET'));

-- Okutmayı hangi kullanıcının yaptığı
ALTER TABLE scans ADD COLUMN user_id INTEGER REFERENCES users(id);
