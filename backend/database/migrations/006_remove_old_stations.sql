-- Eski istasyonlar (Kesim, Büküm, Kaynak, Taşlama, Boya, Montaj) ve onlara bağlı her şey silinir:
-- okutma kayıtları, istasyon kullanıcıları ve oturumları. Bu istasyonlarda görünen iş emirleri
-- kalan son okutmalarına göre konumlanır; okutması kalmayanlar "Bekliyor" durumuna döner.

CREATE TEMP TABLE old_stations AS
    SELECT id FROM stations WHERE code IN ('KESIM', 'BUKUM', 'KAYNAK', 'TASLAMA', 'BOYA', 'MONTAJ');
CREATE TEMP TABLE old_users AS
    SELECT id FROM users WHERE station_id IN (SELECT id FROM old_stations);

DELETE FROM scans
 WHERE station_id IN (SELECT id FROM old_stations)
    OR user_id IN (SELECT id FROM old_users);

UPDATE work_orders
   SET current_station_id = (SELECT sc.station_id FROM scans sc
                              WHERE sc.work_order_id = work_orders.id
                              ORDER BY sc.scanned_at DESC, sc.id DESC LIMIT 1),
       updated_at = datetime('now')
 WHERE current_station_id IN (SELECT id FROM old_stations);

UPDATE work_orders
   SET status = 'open'
 WHERE status = 'in_progress' AND current_station_id IS NULL;

DELETE FROM sessions WHERE user_id IN (SELECT id FROM old_users);
DELETE FROM users WHERE id IN (SELECT id FROM old_users);
DELETE FROM stations WHERE id IN (SELECT id FROM old_stations);

DROP TABLE old_stations;
DROP TABLE old_users;
