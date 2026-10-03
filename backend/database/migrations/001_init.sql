-- Barkod / İş Emri Takip Sistemi - SQLite şeması
-- Benzersizlik kuralları veritabanı seviyesinde garanti altındadır (UNIQUE).
-- COLLATE NOCASE: "ma-001" ile "MA-001" aynı kod kabul edilir.


-- Satın alma siparişleri (PO)
CREATE TABLE IF NOT EXISTS purchase_orders (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    po_number   TEXT    NOT NULL COLLATE NOCASE UNIQUE,   -- Her PO numarası benzersiz
    customer    TEXT,
    note        TEXT,
    created_at  TEXT    NOT NULL DEFAULT (datetime('now')),
    updated_at  TEXT    NOT NULL DEFAULT (datetime('now'))
);

-- İş emirleri (bir PO'ya bağlı)
CREATE TABLE IF NOT EXISTS work_orders (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    po_id        INTEGER NOT NULL REFERENCES purchase_orders(id) ON DELETE CASCADE,
    ma_code      TEXT    NOT NULL COLLATE NOCASE UNIQUE,  -- MA kodu sistem genelinde benzersiz
    barcode      TEXT    NOT NULL COLLATE NOCASE UNIQUE,  -- Barkod sistem genelinde benzersiz
    description  TEXT,
    quantity     INTEGER NOT NULL DEFAULT 1 CHECK (quantity > 0),
    scanned_qty  INTEGER NOT NULL DEFAULT 0 CHECK (scanned_qty >= 0),
    status       TEXT    NOT NULL DEFAULT 'open'
                 CHECK (status IN ('open', 'in_progress', 'done', 'cancelled')),
    created_at   TEXT    NOT NULL DEFAULT (datetime('now')),
    updated_at   TEXT    NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_work_orders_po_id ON work_orders(po_id);

-- Üretimde yapılan her okutma kaydı (denetim izi)
CREATE TABLE IF NOT EXISTS scans (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    work_order_id  INTEGER NOT NULL REFERENCES work_orders(id) ON DELETE CASCADE,
    station        TEXT,                                   -- Hangi istasyon / cihaz okuttu
    scanned_at     TEXT    NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_scans_work_order_id ON scans(work_order_id);
