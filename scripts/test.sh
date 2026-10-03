#!/usr/bin/env bash
# Uçtan uca API testi.
# Geçici bir veritabanı ve geçici bir sunucu (varsayılan port 8799) kullanır;
# backend/storage/app.sqlite'a (gerçek veriye) DOKUNMAZ. Bitince her şeyi siler.
#
# Kullanım: ./scripts/test.sh        (çıkış kodu: 0 = hepsi geçti, 1 = en az biri kaldı)
set -uo pipefail
cd "$(dirname "$0")/.."

PORT="${TEST_PORT:-8799}"
TMP="$(mktemp -d)"
export BARKOD_DB_PATH="$TMP/test.sqlite"
B="http://127.0.0.1:${PORT}/api/v1"
REP='X-API-Key: dev-rep-key'
PROD='X-API-Key: dev-production-key'
JSON='Content-Type: application/json'

php backend/bin/migrate.php --seed >/dev/null || { echo "migrate başarısız"; exit 1; }
php -S "127.0.0.1:${PORT}" -t backend/public backend/public/index.php >"$TMP/server.log" 2>&1 &
PID=$!
trap 'kill $PID 2>/dev/null; rm -rf "$TMP"' EXIT

for _ in $(seq 50); do curl -s -o /dev/null "$B/health" && break; sleep 0.1; done

PASS=0
FAIL=0

# check "açıklama" <beklenen HTTP kodu> <yanıtta geçmesi gereken metin> -- <curl argümanları>
check() {
  local name=$1 status=$2 needle=$3
  shift 3
  local out code body
  out=$(curl -s -w $'\n%{http_code}' "$@")
  code=${out##*$'\n'}
  body=${out%$'\n'*}
  if [[ $code == "$status" && $body == *"$needle"* ]]; then
    PASS=$((PASS + 1)); echo "  ✓ $name"
  else
    FAIL=$((FAIL + 1)); echo "  ✗ $name"
    echo "      beklenen: $status + '$needle'"
    echo "      gelen:    $code ${body:0:300}"
  fi
}

# Veritabanına servis katmanını atlayarak doğrudan SQL çalıştırır; çıktıyı döndürür.
sql() {
  php -r '
    $p = new PDO("sqlite:" . getenv("BARKOD_DB_PATH"));
    $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    try { $r = $p->query($argv[1]); echo json_encode($r->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE); }
    catch (PDOException $e) { echo "HATA: ", $e->getMessage(); }
  ' "$1"
}

expect_sql() {
  local name=$1 query=$2 needle=$3 out
  out=$(sql "$query")
  if [[ $out == *"$needle"* ]]; then
    PASS=$((PASS + 1)); echo "  ✓ $name"
  else
    FAIL=$((FAIL + 1)); echo "  ✗ $name"; echo "      beklenen '$needle', gelen: ${out:0:300}"
  fi
}

# Örnek veri (migrate --seed): PO id=1 "PO-2026-001";
#   iş emri 1: MA-0001 / 8690000000011, iş emri 2: MA-0002 / 8690000000028
# İstasyonlar: 1 Kesim, 2 Büküm, 3 Kaynak, ... 8 Paketleme (son istasyon)

echo "1) Kimlik ve rol yetkileri"
check "health anahtarsız açık"                 200 '"status":"ok"'          "$B/health"
check "anahtarsız istek reddedilir"             401 'UNAUTHORIZED'           "$B/pos"
check "yanlış anahtar reddedilir"               401 'INVALID_API_KEY'        -H 'X-API-Key: yanlis' "$B/pos"
check "üretim anahtarı PO listeleyemez"         403 'FORBIDDEN'              -H "$PROD" "$B/pos"
check "üretim anahtarı PO oluşturamaz"          403 'FORBIDDEN'              -X POST -H "$PROD" -H "$JSON" -d '{"po_number":"X"}' "$B/pos"
check "temsilci anahtarı okutma yapamaz"        403 'FORBIDDEN'              -X POST -H "$REP" -H "$JSON" -d '{"barcode":"8690000000011","station_id":1}' "$B/production/scan"
check "üretim anahtarı istasyonları görür"      200 '"name":"Paketleme"'     -H "$PROD" "$B/stations"
check "/me rolü döndürür"                       200 '"role":"production"'    -H "$PROD" "$B/me"

echo "2) Benzersizlik (PO no, MA kodu, barkod)"
check "aynı PO no (küçük harfle) reddedilir"    409 '"field":"po_number"'    -X POST -H "$REP" -H "$JSON" -d '{"po_number":"po-2026-001"}' "$B/pos"
check "yeni PO oluşturulur (id=2)"              201 '"id":2'                 -X POST -H "$REP" -H "$JSON" -d '{"po_number":"PO-2","customer":"Çağrı Ltd"}' "$B/pos"
check "başka PO'da aynı MA kodu reddedilir"     409 '"field":"ma_code"'      -X POST -H "$REP" -H "$JSON" -d '{"ma_code":"ma-0001","barcode":"YENI1"}' "$B/pos/2/work-orders"
check "başka PO'da aynı barkod reddedilir"      409 '"field":"barcode"'      -X POST -H "$REP" -H "$JSON" -d '{"ma_code":"MA-9","barcode":"8690000000011"}' "$B/pos/2/work-orders"
check "benzersiz iş emri eklenir (id=3)"        201 '"id":3'                 -X POST -H "$REP" -H "$JSON" -d '{"ma_code":"MA-9","barcode":"B9","quantity":2}' "$B/pos/2/work-orders"
check "kendi kodlarıyla güncelleme serbest"     200 '"description":"x"'      -X PUT -H "$REP" -H "$JSON" -d '{"barcode":"B9","description":"x"}' "$B/work-orders/3"
check "güncellemede başkasının barkodu yasak"   409 '"field":"barcode"'      -X PUT -H "$REP" -H "$JSON" -d '{"barcode":"8690000000028"}' "$B/work-orders/3"
check "PO'yu dolu numaraya çevirmek yasak"      409 '"field":"po_number"'    -X PUT -H "$REP" -H "$JSON" -d '{"po_number":"PO-2026-001"}' "$B/pos/2"
check "/check: dolu barkod (küçük harf)"        200 '"available":false'      -H "$REP" "$B/check?field=barcode&value=b9"
check "/check: boş barkod"                      200 '"available":true'       -H "$REP" "$B/check?field=barcode&value=BOS-BARKOD"

echo "3) Veri doğrulama"
check "boş PO no reddedilir"                    422 '"field":"po_number"'    -X POST -H "$REP" -H "$JSON" -d '{"po_number":"  "}' "$B/pos"
check "geçersiz karakterli MA kodu reddedilir"  422 '"field":"ma_code"'      -X POST -H "$REP" -H "$JSON" -d '{"ma_code":"MA 1","barcode":"Z1"}' "$B/pos/2/work-orders"
check "65 karakterlik barkod reddedilir"        422 '"field":"barcode"'      -X POST -H "$REP" -H "$JSON" -d "{\"ma_code\":\"MA-L\",\"barcode\":\"$(printf 'A%.0s' {1..65})\"}" "$B/pos/2/work-orders"
check "adet 0 reddedilir"                       422 '"field":"quantity"'     -X POST -H "$REP" -H "$JSON" -d '{"ma_code":"MA-Q","barcode":"Q1","quantity":0}' "$B/pos/2/work-orders"
check "bozuk JSON reddedilir"                   400 'INVALID_JSON'           -X POST -H "$REP" -H "$JSON" -d '{bozuk' "$B/pos"
check "olmayan PO'ya iş emri eklenemez"         404 'NOT_FOUND'              -X POST -H "$REP" -H "$JSON" -d '{"ma_code":"MA-N","barcode":"N1"}' "$B/pos/999/work-orders"

echo "4) Okutma ve istasyon akışı"
check "istasyonsuz okutma reddedilir"           422 '"field":"station_id"'   -X POST -H "$PROD" -H "$JSON" -d '{"barcode":"B9"}' "$B/production/scan"
check "olmayan istasyon reddedilir"             422 '"field":"station_id"'   -X POST -H "$PROD" -H "$JSON" -d '{"barcode":"B9","station_id":99}' "$B/production/scan"
check "bilinmeyen barkod"                       404 'BARCODE_NOT_FOUND'      -X POST -H "$PROD" -H "$JSON" -d '{"barcode":"YOK","station_id":1}' "$B/production/scan"
check "Kesim'de okut → üretimde, Kesim"         201 '"status":"in_progress"' -X POST -H "$PROD" -H "$JSON" -d '{"barcode":"B9","station_id":1}' "$B/production/scan"
check "aynı istasyonda tekrar okutma yasak"     409 'ALREADY_AT_STATION'     -X POST -H "$PROD" -H "$JSON" -d '{"barcode":"B9","station_id":1}' "$B/production/scan"
check "küçük harf barkodla Kaynak'ta okut"      201 '"current_station_name":"Kaynak"' -X POST -H "$PROD" -H "$JSON" -d '{"barcode":"b9","station_id":3}' "$B/production/scan"
check "PO detayında istasyon görünür"           200 '"current_station_name":"Kaynak"' -H "$REP" "$B/pos/2"
check "Paketleme (son) → tamamlandı"            201 '"status":"done"'        -X POST -H "$PROD" -H "$JSON" -d '{"barcode":"B9","station_id":8}' "$B/production/scan"
check "tamamlanmış iş emri okutulamaz"          409 'WORK_ORDER_DONE'        -X POST -H "$PROD" -H "$JSON" -d '{"barcode":"B9","station_id":2}' "$B/production/scan"
check "PO listesinde tamamlanan sayısı"         200 '"done_count":1'         -H "$REP" "$B/pos?search=PO-2"
check "iş emri iptal edilebilir"                200 '"status":"cancelled"'   -X PUT -H "$REP" -H "$JSON" -d '{"status":"cancelled"}' "$B/work-orders/2"
check "iptal iş emri okutulamaz"                409 'WORK_ORDER_CANCELLED'   -X POST -H "$PROD" -H "$JSON" -d '{"barcode":"8690000000028","station_id":1}' "$B/production/scan"
check "open'a alınınca istasyon temizlenir"     200 '"current_station_id":null' -X PUT -H "$REP" -H "$JSON" -d '{"status":"open"}' "$B/work-orders/3"
check "barkod sorgulama (lookup)"               200 '"ma_code":"MA-0001"'    -H "$PROD" "$B/production/lookup/8690000000011"
expect_sql "her okutma istasyonuyla kaydedildi" \
  "SELECT s.name FROM scans sc JOIN stations s ON s.id = sc.station_id ORDER BY sc.id" \
  '[{"name":"Kesim"},{"name":"Kaynak"},{"name":"Paketleme"}]'

echo "5) Silme ve bağlı kayıtlar"
check "PO silinir"                              204 ''                       -X DELETE -H "$REP" "$B/pos/2"
check "silinen PO'nun iş emri de silindi"       404 'BARCODE_NOT_FOUND'      -H "$PROD" "$B/production/lookup/B9"
expect_sql "silinen iş emrinin okutmaları da silindi" "SELECT COUNT(*) AS n FROM scans" '[{"n":0}]'

echo "6) Yönlendirici"
check "olmayan uç"                              404 'ROUTE_NOT_FOUND'        -H "$REP" "$B/yok"
check "desteklenmeyen metod"                    405 'METHOD_NOT_ALLOWED'     -X PATCH -H "$REP" "$B/pos"

echo "7) Veritabanı katmanı (API'yi atlayarak doğrudan SQL)"
expect_sql "UNIQUE: aynı barkod SQL ile de eklenemez" \
  "INSERT INTO work_orders (po_id, ma_code, barcode) VALUES (1, 'MA-SQL', '8690000000011')" \
  'UNIQUE constraint failed: work_orders.barcode'
expect_sql "NOCASE: küçük harf MA kodu da çakışır" \
  "INSERT INTO work_orders (po_id, ma_code, barcode) VALUES (1, 'ma-0001', 'SQL-1')" \
  'UNIQUE constraint failed: work_orders.ma_code'
expect_sql "UNIQUE: aynı PO no SQL ile de eklenemez" \
  "INSERT INTO purchase_orders (po_number) VALUES ('PO-2026-001')" \
  'UNIQUE constraint failed: purchase_orders.po_number'
expect_sql "CHECK: geçersiz durum değeri eklenemez" \
  "UPDATE work_orders SET status = 'bilinmeyen' WHERE id = 1" \
  'CHECK constraint failed'
expect_sql "migration'lar kayıtlı" \
  "SELECT version FROM schema_migrations ORDER BY version" \
  '[{"version":"001_init"},{"version":"002_stations"}]'
if php backend/bin/migrate.php >/dev/null 2>&1; then
  PASS=$((PASS + 1)); echo "  ✓ migrate tekrar çalıştırılabilir (idempotent)"
else
  FAIL=$((FAIL + 1)); echo "  ✗ migrate tekrar çalıştırılamadı"
fi

if grep -qi "fatal\|uncaught" "$TMP/server.log"; then
  FAIL=$((FAIL + 1)); echo "  ✗ sunucu logunda PHP hatası var:"; grep -i "fatal\|uncaught" "$TMP/server.log" | head -5
fi

echo
echo "Sonuç: $PASS geçti, $FAIL kaldı"
[[ $FAIL -eq 0 ]]
