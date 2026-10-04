-- İstasyonlar: Elektrik → Freze → CNC → Kalite Kontrol → Paketleme (son).
-- Eski istasyonlar silinmez, pasife alınır: geçmiş okutmalar ve o istasyonda bekleyen
-- iş emirleri istasyon adını göstermeye devam eder.

INSERT INTO stations (code, name, sort_order, is_final) VALUES
    ('ELEKTRIK', 'Elektrik', 10, 0),
    ('FREZE',    'Freze',    20, 0),
    ('CNC',      'CNC',      30, 0);

UPDATE stations SET sort_order = 40 WHERE code = 'KALITE';
UPDATE stations SET sort_order = 50 WHERE code = 'PAKET';
UPDATE stations SET active = 0
 WHERE code IN ('KESIM', 'BUKUM', 'KAYNAK', 'TASLAMA', 'BOYA', 'MONTAJ');

-- Kullanıcılar: pasif istasyonların hesapları kapatılır, yeni istasyonlara hesap açılır (şifre 123).
UPDATE users SET active = 0
 WHERE station_id IN (SELECT id FROM stations WHERE active = 0);
DELETE FROM sessions WHERE user_id IN (SELECT id FROM users WHERE active = 0);

INSERT INTO users (username, password_hash, role, station_id) VALUES
    ('elektrik', '$2y$12$biJlDNoGmVlKA4uAridRfOD2zUSQRW3vZscO0X1V4MxjRIwmtWIxW', 'production', (SELECT id FROM stations WHERE code = 'ELEKTRIK')),
    ('freze',    '$2y$12$6neVKck6fK8g0II3g96XnedcbLeoYgSNwQer3aGrtN5x.K6Lf9YAq', 'production', (SELECT id FROM stations WHERE code = 'FREZE')),
    ('cnc',      '$2y$12$ITy/cw86FrdeZVgoYzZq3OcBIQVUvwSLSUzp9VtYZcITqM3uSvNlq', 'production', (SELECT id FROM stations WHERE code = 'CNC'));
