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
export BARKOD_PO_TEMPLATE="$TMP/templates/po.xlsx"
B="http://127.0.0.1:${PORT}/api/v1"
JSON='Content-Type: application/json'

php backend/bin/migrate.php --seed >/dev/null || { echo "migrate başarısız"; exit 1; }
php -S "127.0.0.1:${PORT}" -t backend/public backend/public/index.php >"$TMP/server.log" 2>&1 &
PID=$!
trap 'kill $PID 2>/dev/null; rm -rf "$TMP"' EXIT

for _ in $(seq 50); do curl -s -o /dev/null "$B/health" && break; sleep 0.1; done

# Giriş yapıp oturum token'ı alır (migration 003'teki başlangıç kullanıcıları, şifre 123)
login() {
  curl -s -X POST -H "$JSON" -d "{\"username\":\"$1\",\"password\":\"123\"}" "$B/auth/login" \
    | grep -oE '"token":"[0-9a-f]{64}"' | cut -d'"' -f4
}
REP="Authorization: Bearer $(login mami)"
PROD="Authorization: Bearer $(login freze)"

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
# Aktif istasyonlar: 9 Elektrik, 10 Freze, 11 CNC, 7 Kalite Kontrol, 8 Paketleme (son)

echo "1) Giriş ve rol yetkileri"
check "health girişsiz açık"                    200 '"status":"ok"'          "$B/health"
check "yanlış şifre reddedilir"                 401 'INVALID_CREDENTIALS'    -X POST -H "$JSON" -d '{"username":"mami","password":"yanlis"}' "$B/auth/login"
check "girişsiz istek reddedilir"               401 'UNAUTHORIZED'           "$B/pos"
check "geçersiz token reddedilir"               401 'SESSION_EXPIRED'        -H "Authorization: Bearer $(printf '0%.0s' {1..64})" "$B/pos"
check "üretim kullanıcısı PO listeleyemez"      403 'FORBIDDEN'              -H "$PROD" "$B/pos"
check "üretim kullanıcısı PO oluşturamaz"       403 'FORBIDDEN'              -X POST -H "$PROD" -H "$JSON" -d '{"po_number":"X"}' "$B/pos"
check "üretim kullanıcısı istasyonları görür"   200 '"name":"Paketleme"'     -H "$PROD" "$B/stations"
check "/me kullanıcı ve istasyonu döndürür"     200 '"station_name":"Freze"' -H "$PROD" "$B/me"
TMPTOK="Authorization: Bearer $(login cnc)"
check "çıkış yapılır"                           204 ''                       -X POST -H "$TMPTOK" "$B/auth/logout"
check "çıkıştan sonra token geçersiz"           401 'SESSION_EXPIRED'        -H "$TMPTOK" "$B/me"

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
check "istasyonsuz okutma reddedilir"           422 '"field":"station_id"'   -X POST -H "$REP" -H "$JSON" -d '{"barcode":"B9"}' "$B/production/scan"
check "olmayan istasyon reddedilir"             422 '"field":"station_id"'   -X POST -H "$REP" -H "$JSON" -d '{"barcode":"B9","station_id":99}' "$B/production/scan"
check "bilinmeyen barkod"                       404 'BARCODE_NOT_FOUND'      -X POST -H "$REP" -H "$JSON" -d '{"barcode":"YOK","station_id":9}' "$B/production/scan"
check "Elektrik'te okut → üretimde"         201 '"status":"in_progress"' -X POST -H "$REP" -H "$JSON" -d '{"barcode":"B9","station_id":9}' "$B/production/scan"
check "aynı istasyonda tekrar okutma yasak"     409 'ALREADY_AT_STATION'     -X POST -H "$REP" -H "$JSON" -d '{"barcode":"B9","station_id":9}' "$B/production/scan"
check "küçük harf barkodla Freze'de okut"      201 '"current_station_name":"Freze"' -X POST -H "$REP" -H "$JSON" -d '{"barcode":"b9","station_id":10}' "$B/production/scan"
check "PO detayında istasyon görünür"           200 '"current_station_name":"Freze"' -H "$REP" "$B/pos/2"
check "Paketleme (son) → tamamlandı"            201 '"status":"done"'        -X POST -H "$REP" -H "$JSON" -d '{"barcode":"B9","station_id":8}' "$B/production/scan"
check "tamamlanmış iş emri okutulamaz"          409 'WORK_ORDER_DONE'        -X POST -H "$REP" -H "$JSON" -d '{"barcode":"B9","station_id":11}' "$B/production/scan"
check "PO listesinde tamamlanan sayısı"         200 '"done_count":1'         -H "$REP" "$B/pos?search=PO-2"
check "iş emri iptal edilebilir"                200 '"status":"cancelled"'   -X PUT -H "$REP" -H "$JSON" -d '{"status":"cancelled"}' "$B/work-orders/2"
check "iptal iş emri okutulamaz"                409 'WORK_ORDER_CANCELLED'   -X POST -H "$REP" -H "$JSON" -d '{"barcode":"8690000000028","station_id":9}' "$B/production/scan"
check "open'a alınınca istasyon temizlenir"     200 '"current_station_id":null' -X PUT -H "$REP" -H "$JSON" -d '{"status":"open"}' "$B/work-orders/3"
check "barkod sorgulama (lookup)"               200 '"ma_code":"MA-0001"'    -H "$PROD" "$B/production/lookup/8690000000011"
expect_sql "her okutma istasyonuyla kaydedildi" \
  "SELECT s.name FROM scans sc JOIN stations s ON s.id = sc.station_id ORDER BY sc.id" \
  '[{"name":"Elektrik"},{"name":"Freze"},{"name":"Paketleme"}]'
check "iş emri istasyon geçmişi (tarih + okutan)" 200 '"station_name":"Elektrik","is_final":false,"username":"mami"' -H "$REP" "$B/work-orders/3/scans"
check "PO takip raporu (PO + iş emirleri)"     200 '"last_scan_at":'          -H "$REP" "$B/reports/overview"
check "PO termin tarihiyle kaydedilir"         201 '"due_date":"2020-01-15"' -X POST -H "$REP" -H "$JSON" -d '{"po_number":"PO-GEC","due_date":"2020-01-15"}' "$B/pos"
check "geçersiz termin tarihi reddedilir"       422 '"field":"due_date"'     -X POST -H "$REP" -H "$JSON" -d '{"po_number":"PO-X1","due_date":"2020-02-30"}' "$B/pos"
check "istasyon süre analizi"                   200 '"avg_seconds":'         -H "$REP" "$B/reports/stations?days=30"
BOARDTOK="Authorization: Bearer $(login pano)"
check "pano verisi (geciken PO dahil)"          200 '"po_number":"PO-GEC"'   -H "$BOARDTOK" "$B/board"
V1=$(curl -s -H "$BOARDTOK" "$B/board/version" | grep -oE '"version":"[0-9a-f]{16}"')
check "pano sürümü değişmezse aynı kalır"       200 "$V1"                    -H "$BOARDTOK" "$B/board/version"
curl -s -o /dev/null -X POST -H "$REP" -H "$JSON" -d '{"po_number":"PO-SURUM"}' "$B/pos"
V2=$(curl -s -H "$BOARDTOK" "$B/board/version" | grep -oE '"version":"[0-9a-f]{16}"')
if [[ -n $V1 && $V1 != "$V2" ]]; then PASS=$((PASS + 1)); echo "  ✓ veri değişince pano sürümü değişir"; else FAIL=$((FAIL + 1)); echo "  ✗ veri değişince pano sürümü değişir ($V1 / $V2)"; fi
check "pano kullanıcısı PO göremez"             403 'FORBIDDEN'              -H "$BOARDTOK" "$B/pos"
check "pano kullanıcısı okutma yapamaz"         403 'FORBIDDEN'              -X POST -H "$BOARDTOK" -H "$JSON" -d '{"barcode":"B9"}' "$B/production/scan"
check "üretim kullanıcısı panoyu göremez"       403 'FORBIDDEN'              -H "$PROD" "$B/board"

# Excel dışa aktarma ve şablon
code=$(curl -s -o "$TMP/po1.xlsx" -w '%{http_code} %{content_type}' -H "$REP" "$B/pos/1/export")
if [[ $code == "200 application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" ]] \
   && php -r 'require "backend/bootstrap.php"; $f = App\Excel\Zip::read(file_get_contents($argv[1])); exit(str_contains($f["xl/worksheets/sheet1.xml"], "MA-0001") && !str_contains($f["xl/worksheets/sheet1.xml"], "{wo.") ? 0 : 1);' "$TMP/po1.xlsx"; then
  PASS=$((PASS + 1)); echo "  ✓ PO Excel'e aktarılır (iş emirleri dolu, yer tutucu kalmaz)"
else
  FAIL=$((FAIL + 1)); echo "  ✗ PO Excel'e aktarılır ($code)"
fi
check "üretim kullanıcısı Excel alamaz"         403 'FORBIDDEN'              -H "$PROD" "$B/pos/1/export"
check "şablon bilgisi ve yer tutucular"         200 '"key":"wo.ma_code"'     -H "$REP" "$B/templates/po"
printf 'excel degil' > "$TMP/bad.xlsx"
check "geçersiz şablon reddedilir"              422 '"field":"file"'         -X POST -H "$REP" -F "file=@$TMP/bad.xlsx" "$B/templates/po"
check "geçerli şablon yüklenir"                 200 '"custom":true'          -X POST -H "$REP" -F "file=@backend/templates/po.xlsx" "$B/templates/po"
check "varsayılan şablona dönülür"              200 '"custom":false'         -X DELETE -H "$REP" "$B/templates/po"
check "üretim kullanıcısı raporu göremez"       403 'FORBIDDEN'              -H "$PROD" "$B/reports/overview"
check "üretim kullanıcısı geçmişi göremez"      403 'FORBIDDEN'              -H "$PROD" "$B/work-orders/3/scans"
check "üretim kullanıcısının istasyonu sabit (gövde yok sayılır)" 201 '"current_station_name":"Freze"' -X POST -H "$PROD" -H "$JSON" -d '{"barcode":"8690000000011","station_id":9}' "$B/production/scan"
expect_sql "okutmayı yapan kullanıcı kaydedildi" \
  "SELECT u.username FROM scans sc JOIN users u ON u.id = sc.user_id ORDER BY sc.id DESC LIMIT 1" '[{"username":"freze"}]'

# Yönetici: iş emrini istediği konuma taşır (iş emri 3 = B9, şu an Bekliyor)
ADMINTOK="Authorization: Bearer $(login admin)"
check "yönetici iş emrini Paketleme'ye taşır → tamamlandı" 200 '"status":"done"'   -X POST -H "$ADMINTOK" -H "$JSON" -d '{"station_id":8}' "$B/work-orders/3/move"
check "yönetici tamamlanmışı geri istasyona alır"   200 '"current_station_name":"CNC"' -X POST -H "$ADMINTOK" -H "$JSON" -d '{"station_id":11}' "$B/work-orders/3/move"
check "aynı konuma taşıma reddedilir"               409 'NO_CHANGE'                 -X POST -H "$ADMINTOK" -H "$JSON" -d '{"station_id":11}' "$B/work-orders/3/move"
check "yönetici Bekliyor'a alır"                    200 '"current_station_id":null' -X POST -H "$ADMINTOK" -H "$JSON" -d '{"status":"open"}' "$B/work-orders/3/move"
check "geçersiz hedef reddedilir"                   422 '"field":"station_id"'      -X POST -H "$ADMINTOK" -H "$JSON" -d '{"status":"done"}' "$B/work-orders/3/move"
check "taşıma geçmişte yöneticiyle görünür"         200 '"username":"admin","kind":"move","status":"open"' -H "$ADMINTOK" "$B/work-orders/3/scans"
check "temsilci taşıma yapamaz"                     403 'FORBIDDEN'                 -X POST -H "$REP" -H "$JSON" -d '{"station_id":9}' "$B/work-orders/3/move"
check "üretim kullanıcısı taşıma yapamaz"           403 'FORBIDDEN'                 -X POST -H "$PROD" -H "$JSON" -d '{"station_id":9}' "$B/work-orders/3/move"
check "yönetici PO oluşturamaz"                     403 'FORBIDDEN'                 -X POST -H "$ADMINTOK" -H "$JSON" -d '{"po_number":"ADM"}' "$B/pos"
check "yönetici okutma yapamaz"                     403 'FORBIDDEN'                 -X POST -H "$ADMINTOK" -H "$JSON" -d '{"barcode":"B9"}' "$B/production/scan"
check "yönetici Excel şablonunu değiştiremez"       403 'FORBIDDEN'                 -X DELETE -H "$ADMINTOK" "$B/templates/po"

echo "5) Silme ve bağlı kayıtlar"
check "PO silinir"                              204 ''                       -X DELETE -H "$REP" "$B/pos/2"
check "silinen PO'nun iş emri de silindi"       404 'BARCODE_NOT_FOUND'      -H "$PROD" "$B/production/lookup/B9"
expect_sql "silinen iş emrinin okutmaları da silindi" "SELECT COUNT(*) AS n FROM scans" '[{"n":1}]'

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
  '[{"version":"001_init"},{"version":"002_stations"},{"version":"003_auth"},{"version":"004_new_stations"},{"version":"005_due_date_board"},{"version":"006_remove_old_stations"},{"version":"007_admin"}]'
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
