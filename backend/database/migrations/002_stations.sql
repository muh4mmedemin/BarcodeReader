-- Üretim istasyonları: iş emrinin durumu artık "hangi istasyonda" bilgisidir.
-- Okutulan adet sayacı kaldırıldı.

CREATE TABLE stations (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    code        TEXT    NOT NULL COLLATE NOCASE UNIQUE,
    name        TEXT    NOT NULL,
    sort_order  INTEGER NOT NULL DEFAULT 0,
    is_final    INTEGER NOT NULL DEFAULT 0 CHECK (is_final IN (0, 1)),  -- Burada okutulan iş emri tamamlanır
    active      INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0, 1))
);

INSERT INTO stations (code, name, sort_order, is_final) VALUES
    ('KESIM',   'Kesim',          10, 0),
    ('BUKUM',   'Büküm',          20, 0),
    ('KAYNAK',  'Kaynak',         30, 0),
    ('TASLAMA', 'Taşlama',        40, 0),
    ('BOYA',    'Boya',           50, 0),
    ('MONTAJ',  'Montaj',         60, 0),
    ('KALITE',  'Kalite Kontrol', 70, 0),
    ('PAKET',   'Paketleme',      80, 1);

ALTER TABLE work_orders ADD COLUMN current_station_id INTEGER REFERENCES stations(id);
ALTER TABLE work_orders DROP COLUMN scanned_qty;

-- Okutma kayıtları serbest metin yerine istasyon kaydına bağlanır.
ALTER TABLE scans ADD COLUMN station_id INTEGER REFERENCES stations(id);
ALTER TABLE scans DROP COLUMN station;

CREATE INDEX idx_work_orders_station ON work_orders(current_station_id);
