# BarcodeReader — Barkod / İş Emri Takip Sistemi

Müşteri temsilcisi PO (satın alma siparişi) açar ve PO'lara iş emri ekler. Üretimde her istasyon
bilgisayarı kendi hesabıyla giriş yapar ve iş emrinin barkodunu okutarak iş emrini kendi
istasyonuna alır. Son istasyonda (Paketleme) okutulan iş emri tamamlanır. Temsilci her iş emrinin
şu an nerede olduğunu, hangi istasyonlardan ne zaman geçtiğini, PO'ların termin durumunu ve
istasyon sürelerini görür; PO'yu kendi Excel şablonuyla dışa aktarır. Atölyedeki ekranda canlı
güncellenen bir pano çalışır.

Bu belge sistemin **nasıl çalıştığını, nasıl test edileceğini ve neyin henüz eksik olduğunu**
anlatır. İddiaların yanında kodda nerede yapıldığını gösteren bağlantılar (`dosya:satır`) vardır.
Davranışlarla ilgili iddialar [otomatik test script'i](scripts/test.sh) ile doğrulanır
(bkz. [Test etme](#12-test-etme)).

## İçindekiler

1. [Hızlı başlangıç](#1-hızlı-başlangıç)
2. [Mimari](#2-mimari)
3. [Bir isteğin yolculuğu](#3-bir-isteğin-yolculuğu)
4. [Giriş, roller ve yetkiler](#4-giriş-roller-ve-yetkiler)
5. [Veri modeli](#5-veri-modeli)
6. [Benzersizlik kuralları](#6-benzersizlik-kuralları)
7. [Veri doğrulama](#7-veri-doğrulama)
8. [İstasyon ve okutma akışı](#8-istasyon-ve-okutma-akışı)
9. [Raporlar, analiz ve pano](#9-raporlar-analiz-ve-pano)
10. [Excel'e aktarma ve şablon](#10-excele-aktarma-ve-şablon)
11. [Arayüz (frontend)](#11-arayüz-frontend)
12. [Test etme](#12-test-etme)
13. [Migration (şema güncelleme) sistemi](#13-migration-şema-güncelleme-sistemi)
14. [Yapılandırma](#14-yapılandırma)
15. [Bilinen sınırlamalar](#15-bilinen-sınırlamalar)
16. [Güvenlik durumu](#16-güvenlik-durumu)
17. [Geliştirme rehberi](#17-geliştirme-rehberi)

---

## 1. Hızlı başlangıç

### Gereksinimler

| Gereksinim | Neden | Kontrol |
|---|---|---|
| PHP 8.1+ (8.4 ile test edildi) | Backend (`readonly` özellikler ve `fn(...)` sözdizimi 8.1 ile geldi) | `php -v` |
| `pdo_sqlite` eklentisi | Veritabanı. Yoksa backend açıkça hata verir ([Database.php:15-16](backend/src/Database.php#L15-L16)) | `php -m \| grep pdo_sqlite` |
| `dom` ve `zlib` eklentileri | Excel dosyası okuma/yazma. PHP ile çoğunlukla hazır gelir | `php -m \| grep -E 'dom\|zlib'` |
| `curl` | Sadece test script'i için | `curl --version` |

Debian/Ubuntu'da SQLite eklentisini kurmak için:

```bash
sudo apt install php8.4-sqlite3
```

**Gerekmeyenler:** `mbstring` (metin uzunluğu regex ile sayılır, [Validator.php:36](backend/src/Services/Validator.php#L36)),
`zip` eklentisi ve Composer (Excel için zip okuma/yazma saf PHP ile yazıldı, [Zip.php](backend/src/Excel/Zip.php)).

### Çalıştırma

```bash
./scripts/dev.sh
```

Bu komut ([scripts/dev.sh](scripts/dev.sh)):

1. Veritabanını hazırlar (bekleyen migration'ları uygular) ve örnek veri ekler. Örnek veri zaten varsa atlar ([migrate.php:26](backend/bin/migrate.php#L26)).
2. **API sunucusunu** `:8000` portunda başlatır.
3. **Arayüz sunucusunu** `:5173` portunda, ayrı bir süreç olarak başlatır.

Sunucular `0.0.0.0` adresinde dinler; ağdaki diğer bilgisayarlar da bağlanabilir (bkz. [14](#başka-bir-bilgisayardan-erişim)).
Durdurmak için `Ctrl+C`.

| Adres | Ne var |
|---|---|
| http://localhost:5173/ | Giriş yapılmamışsa giriş ekranına, yapılmışsa rolün sayfasına yönlendirir |
| http://localhost:8000/api/v1/health | API sağlık kontrolü |

### Hesaplar

Başlangıç hesaplarının hepsinin şifresi **`123`**. Gerçek kullanıma geçmeden değiştirin
(bkz. [16](#16-güvenlik-durumu)).

| Kullanıcı | Rol | Girişten sonra açılan sayfa | Ne yapabilir |
|---|---|---|---|
| `mami` | Müşteri temsilcisi (`rep`) | Ana menü | Her şey: PO/iş emri, okutma (istasyonu ekrandan seçer), raporlar, pano, Excel |
| `elektrik` | Üretim (`production`) | İş Emri Okut, istasyon **Elektrik** sabit | Sadece okutma |
| `freze` | Üretim | İş Emri Okut, **Freze** sabit | Sadece okutma |
| `cnc` | Üretim | İş Emri Okut, **CNC** sabit | Sadece okutma |
| `kalite` | Üretim | İş Emri Okut, **Kalite Kontrol** sabit | Sadece okutma |
| `paketleme` | Üretim | İş Emri Okut, **Paketleme** sabit | Sadece okutma |
| `pano` | Pano (`board`) | Atölye Panosu | Sadece panoyu görür |
| `admin` | Yönetici (`admin`) | İş Emri Taşıma | Sadece iş emirlerini istediği konuma taşır (her istasyon, Bekliyor, İptal; tamamlanmış iş emri dahil). Kullanıcılar üzerinde yetkisi yok |

Hesaplar migration dosyalarında oluşturulur:
[003_auth.sql](backend/database/migrations/003_auth.sql) (`mami`, `kalite`, `paketleme`),
[004_new_stations.sql](backend/database/migrations/004_new_stations.sql) (`elektrik`, `freze`, `cnc`),
[005_due_date_board.sql](backend/database/migrations/005_due_date_board.sql) (`pano`),
[007_admin.sql](backend/database/migrations/007_admin.sql) (`admin`).

**Örnek veri:** `PO-2026-001` numaralı bir PO ve iki iş emri:
`MA-0001` / `8690000000011` (3 adet) ve `MA-0002` / `8690000000028` (1 adet).

Veritabanı dosyası `backend/storage/app.sqlite`. Git'e eklenmez ([.gitignore](.gitignore)).

---

## 2. Mimari

```
 ┌─────────────────────────────────────┐   ┌──────────────────────┐   ┌───────────────────────┐
 │ Temsilci (mami)  /  ana menü        │   │ İstasyon PC'leri     │   │ İleride: senin        │
 │  1 PO Oluşturma   4 Üretim Analizi  │   │ /production          │   │ ürettiğin okuma       │
 │  2 İş Emri Okut   5 Atölye Panosu   │   │ (elektrik, freze...) │   │ cihazları, mobil ...  │
 │  3 PO Takip       6 Excel Şablonu   │   ├──────────────────────┤   │                       │
 │                                     │   │ Atölye ekranı /board │   │                       │
 └──────────────────┬──────────────────┘   └──────────┬───────────┘   └───────────┬───────────┘
                    │   HTTP + JSON, her istekte "Authorization: Bearer <token>"  │
                    └──────────────────────────────┬──────────────────────────────┘
                                          ┌────────▼─────────┐
                                          │ Backend (PHP)    │  REST API: /api/v1/...
                                          │ :8000            │
                                          └────────┬─────────┘
                                          ┌────────▼─────────┐
                                          │ SQLite           │  backend/storage/app.sqlite
                                          └──────────────────┘
```

### Temel kararlar

- **Backend ile arayüz birbirinden bağımsız.**
  - Backend HTML üretmez; JSON döner ([Response.php:17](backend/src/Http/Response.php#L17)). Tek istisna, Excel dosyası indirme ucudur ([Response.php:27](backend/src/Http/Response.php#L27)).
  - İki ayrı sunucu, iki ayrı portta çalışır.
  - Arayüz backend'e yalnızca HTTP üzerinden ulaşır ([shared/api.js](frontend/shared/api.js)).
  - Yeni bir cihaz için client yazarken backend'e dokunmak gerekmez; API uçları [bölüm 4](#uçlar-ve-izinli-roller)'te listelidir.
- **Tüm iş kuralları ve yetkiler backend'de.** Arayüz kural uygulamaz; sadece sorar ve gösterir. Bir butonu gizlemek yetki vermez, göstermek de yetki almaz.
- **Framework ve Composer yok.** Sınıflar küçük bir otomatik yükleyiciyle yüklenir ([bootstrap.php](backend/bootstrap.php)).
- **Arayüzde build adımı yok.** Saf HTML/CSS/JS ve tarayıcının yerel ES modülleri. Dışarıdan kütüphane veya yazı tipi yüklenmez (fabrika ağında internet olmayabilir).

### Klasör yapısı

```
backend/
  public/index.php            Tek giriş noktası: CORS, oturum, hata yakalama, bağımlılıkları kurma
  public/.htaccess            Apache ile yayınlarken istekleri index.php'ye yönlendirir
  routes.php                  Tüm API uçları ve her uca hangi rolün erişebileceği
  config.php                  DB yolu, CORS, saat dilimi, Excel şablon yolları
  bootstrap.php               Otomatik yükleyici, config, saat dilimi
  bin/migrate.php             Migration durumunu gösterir; --seed ile örnek veri ekler
  bin/build-po-template.php   Varsayılan Excel şablonunu (templates/po.xlsx) üretir
  database/migrations/        Numaralı şema dosyaları (001 … 006)
  templates/po.xlsx           Varsayılan PO Excel şablonu
  storage/                    SQLite dosyası ve yüklenen özel şablon (git'e girmez)
  src/
    Database.php              Bağlantı, PRAGMA ayarları, migration çalıştırıcı
    Http/                     Request, Response, Router, HttpException
    Services/                 İş kuralları: giriş, PO, iş emri, okutma, istasyon, rapor,
                              Excel dışa aktarma, benzersizlik, doğrulama
    Excel/                    Zip.php (saf PHP zip), XlsxTemplate.php (şablon doldurucu)
    Controllers/              HTTP isteğini servise bağlayan ince katman
frontend/
  login.html, login.js        Giriş ekranı
  index.html, menu.css/js     Ana menü (temsilci)
  rep/                        PO Oluşturma
  production/                 İş Emri Okut
  report/                     PO Takip
  analysis/                   Üretim Analizi
  board/                      Atölye Panosu
  template/                   Excel Şablonu
  admin/                      İş Emri Taşıma (yönetici)
  shared/api.js               Tek API istemcisi (tüm sayfalar bunu kullanır)
  shared/api-base.js          API adresini sayfanın açıldığı adresten türetir
  shared/auth.js              Oturum saklama, giriş zorunluluğu, rol yönlendirmesi
  shared/statusbar.js         Alt durum çubuğu (bağlantı, API adresi, saat)
  shared/history.js           İş emri istasyon geçmişi tablosu, süre ve saat biçimleme
  shared/distribution.js      İş emirlerinin konuma göre dağılımı
  shared/due.js               Termin durumu (gecikti / yaklaşıyor / zamanında)
  shared/base.css             Ortak stil, açık/koyu tema
scripts/dev.sh                Geliştirme sunucularını başlatır
scripts/test.sh               Uçtan uca otomatik test (84 kontrol)
```

---

## 3. Bir isteğin yolculuğu

Örnek: Freze istasyonundaki bilgisayarda `freze` kullanıcısı `B9` barkodunu okutuyor.

```
Tarayıcı ──POST /api/v1/production/scan  {"barcode":"B9"}  + Authorization: Bearer <token>──► index.php
```

| # | Adım | Kod |
|---|---|---|
| 1 | Sayfa, girişte aldığı token'ı `Authorization` başlığına koyarak isteği gönderir | [api.js:26](frontend/shared/api.js#L26) |
| 2 | `index.php` CORS başlıklarını ekler; tarayıcının ön kontrol (`OPTIONS`) isteğine boş cevap verir | [index.php:28-33](backend/public/index.php#L28-L33) |
| 3 | Veritabanına bağlanılır; bekleyen migration varsa uygulanır | [index.php:36](backend/public/index.php#L36) |
| 4 | Token varsa oturumdan kullanıcı bulunur. Token geçersiz veya süresi dolmuşsa **401 SESSION_EXPIRED** | [index.php:40-45](backend/public/index.php#L40-L45) |
| 5 | Router yolu eşleştirir ve kullanıcının rolü bu uç için izinli mi bakar. İzinsizse **403**, metod yanlışsa **405**, yol yoksa **404** | [Router.php:34-36](backend/src/Http/Router.php#L34-L36) |
| 6 | Controller, üretim kullanıcısının istasyonunu **hesabından** alır; gövdede `station_id` gönderilse bile yok sayar | [ProductionController.php:41-52](backend/src/Controllers/ProductionController.php#L41-L52) |
| 7 | Servis okutma kurallarını uygular (bkz. [bölüm 8](#8-istasyon-ve-okutma-akışı)) ve okutmayı kullanıcıyla birlikte kaydeder | [WorkOrderService.php:212](backend/src/Services/WorkOrderService.php#L212) |
| 8 | Cevap `{"data": ...}` zarfıyla döner | [Response.php:17](backend/src/Http/Response.php#L17) |
| 9 | Hatalar tek tip `{"error":{"code","message","field"}}` biçimine çevrilir. Girişsiz istek 403 yerine **401 UNAUTHORIZED** alır. Beklenmeyen hatalar loglanır ve ayrıntı verilmeden **500** döner | [index.php:66-74](backend/public/index.php#L66-L74), [HttpException.php:37](backend/src/Http/HttpException.php#L37) |

---

## 4. Giriş, roller ve yetkiler

### Giriş nasıl çalışır

1. Giriş ekranı `POST /auth/login` ucuna kullanıcı adı ve şifreyi gönderir ([login.js](frontend/login.js)).
2. Şifre bcrypt özetiyle karşılaştırılır ([AuthService.php:29](backend/src/Services/AuthService.php#L29)). Pasif hesaplar giriş yapamaz. Kullanıcı adında büyük/küçük harf fark etmez.
3. Başarılıysa 32 baytlık rastgele bir token üretilir ([AuthService.php:35](backend/src/Services/AuthService.php#L35)). Veritabanına token'ın kendisi değil **SHA-256 özeti** yazılır ([AuthService.php:38](backend/src/Services/AuthService.php#L38)); veritabanı dosyası ele geçse bile oturumlar kullanılamaz.
4. Oturum **7 gün** geçerlidir ([AuthService.php:12](backend/src/Services/AuthService.php#L12)). Süresi dolan oturumlar yeni girişlerde temizlenir.
5. Token ve kullanıcı bilgisi tarayıcıda saklanır ([shared/auth.js](frontend/shared/auth.js)). Her sayfa açılışta oturum ister; yoksa giriş ekranına, rolü o sayfaya yetkili değilse kendi sayfasına yönlendirir.
6. Sunucu 401 dönerse (oturum silinmiş, süresi dolmuş) sayfa otomatik olarak giriş ekranına döner.
7. **Çıkış** oturumu sunucudan siler; o token bir daha kullanılamaz.

### Roller

| Rol | Sayfalar | İstasyon |
|---|---|---|
| `rep` (müşteri temsilcisi) | Tüm sayfalar | Okutmada istasyonu ekrandan seçer |
| `production` (istasyon) | Sadece İş Emri Okut | Hesabına sabittir (`users.station_id`); ekranda değiştirilemez, API'de de gövdeden gelen istasyon yok sayılır |
| `board` (atölye ekranı) | Sadece Atölye Panosu | — |
| `admin` (yönetici) | Sadece İş Emri Taşıma | Taşırken hedefi kendisi seçer |

### Uçlar ve izinli roller

Hepsi [routes.php](backend/routes.php) içinde. Yetki kontrolü sunucuda yapılır ([Router.php:34](backend/src/Http/Router.php#L34)).

| Uç | rep | production | board | admin |
|---|:-:|:-:|:-:|:-:|
| `GET /health`, `POST /auth/login` | herkese açık | herkese açık | herkese açık | herkese açık |
| `GET /me`, `POST /auth/logout` | ✓ | ✓ | ✓ | ✓ |
| `GET /check` (anlık benzersizlik kontrolü) | ✓ | ✗ | ✗ | ✗ |
| PO: `GET/POST /pos`, `GET/PUT/DELETE /pos/{id}` | ✓ | ✗ | ✗ | ✗ |
| İş emri: `GET/POST /pos/{poId}/work-orders`, `GET/PUT/DELETE /work-orders/{id}` | ✓ | ✗ | ✗ | ✗ |
| `GET /work-orders/{id}/scans` (istasyon geçmişi) | ✓ | ✗ | ✗ | ✓ |
| `POST /work-orders/{id}/move` (taşıma) | ✗ | ✗ | ✗ | ✓ |
| `GET /reports/overview` (PO Takip) | ✓ | ✗ | ✗ | ✓ |
| `GET /reports/stations?days=N` (analiz) | ✓ | ✗ | ✗ | ✗ |
| `GET /pos/{id}/export` (Excel) | ✓ | ✗ | ✗ | ✗ |
| Şablon: `GET/POST/DELETE /templates/po`, `GET /templates/po/file` | ✓ | ✗ | ✗ | ✗ |
| `GET /board`, `GET /board/version` | ✓ | ✗ | ✓ | ✗ |
| `GET /stations` | ✓ | ✓ | ✗ | ✓ |
| `GET /production/lookup/{barcode}`, `POST /production/scan` | ✓ | ✓ | ✗ | ✗ |

Tüm yollar `/api/v1` ile başlar. Başarılı yanıt `{"data": ..., "meta": ...}` (`meta` sadece
listelerde), hata yanıtı `{"error": {"code", "message", "field"}}`. `DELETE` ve çıkış `204` döner.

**Kanıt:** test bölüm 1 (10 kontrol) ve bölüm 4'teki yetki kontrolleri (pano kullanıcısı PO
göremez / okutamaz, üretim kullanıcısı pano, rapor, geçmiş ve Excel göremez, üretim kullanıcısının
istasyonu sabit).

---

## 5. Veri modeli

```
purchase_orders 1 ──── * work_orders * ──── 0..1 stations ◄──── 0..1 users ──── * sessions
                              │                     │                 │
                              1                     1                 │
                              │                     │                 │
                              * scans * ────────────┘                 │
                                  * ──────────────────────────────────┘ (okutan kullanıcı)
```

| Tablo | Sütunlar | Önemli kısıtlar |
|---|---|---|
| `purchase_orders` | id, po_number, customer, note, due_date, created_at, updated_at | `po_number` UNIQUE + NOCASE. `due_date` (termin) isteğe bağlı, `YYYY-MM-DD` ([005](backend/database/migrations/005_due_date_board.sql)) |
| `work_orders` | id, po_id, ma_code, barcode, description, quantity, status, current_station_id, created_at, updated_at | `ma_code`, `barcode` UNIQUE + NOCASE; `quantity > 0`; `status` yalnızca `open`, `in_progress`, `done`, `cancelled`; PO silinince iş emri de silinir ([001](backend/database/migrations/001_init.sql)) |
| `stations` | id, code, name, sort_order, is_final, active | `code` UNIQUE ([002](backend/database/migrations/002_stations.sql)) |
| `scans` | id, work_order_id, station_id, user_id, kind, status, scanned_at | Hareket kayıtları. İş emri silinince bunlar da silinir. `user_id` hareketi yapan kullanıcı; `kind` `scan` (okutma) veya `move` (yönetici taşıması); `status` hareketten sonraki durum. Bekliyor/İptal'e taşımada `station_id` boştur ([007](backend/database/migrations/007_admin.sql)) |
| `users` | id, username, password_hash, role, station_id, active, created_at | `username` UNIQUE + NOCASE; `role` yalnızca `rep`, `production`, `board`, `admin`; `production` rolünde istasyon zorunlu ([005](backend/database/migrations/005_due_date_board.sql)) |
| `sessions` | token_hash, user_id, created_at, expires_at | Kullanıcı silinince oturumları da silinir ([003](backend/database/migrations/003_auth.sql)) |
| `schema_migrations` | version, applied_at | Hangi migration'ın uygulandığını tutar |

**Durum (`status`) anlamları:**

| Değer | Arayüzde | Ne zaman olur |
|---|---|---|
| `open` | Bekliyor | İş emri eklendiğinde |
| `in_progress` | **İstasyonun adı** (ör. "Freze") | Son istasyon dışında bir istasyonda okutulunca |
| `done` | Tamamlandı | Son istasyonda (Paketleme) okutulunca |
| `cancelled` | İptal | Yalnızca API ile elle (`PUT /work-orders/{id}`) |

"İstasyonun adı" gösterimi [api.js:136](frontend/shared/api.js#L136) içindeki `statusLabel()` fonksiyonundan gelir.

**Veritabanı ayarları** ([Database.php:30-32](backend/src/Database.php#L30-L32)):
- `foreign_keys = ON`: SQLite'ta bu ayar varsayılan olarak kapalıdır. Açılmazsa bağlı kayıtları silme (CASCADE) çalışmaz.
- `journal_mode = WAL`: Bir cihaz yazarken diğerleri okumaya devam edebilir.
- `busy_timeout = 5000`: Veritabanı meşgulse hemen hata vermek yerine 5 saniyeye kadar bekler.

**Zaman:** Zaman damgaları `datetime('now')` ile yazılır, yani **UTC**'dir. Arayüz yerel saate
çevirerek gösterir. Excel dosyasındaki tarihler ve "bugün" hesapları sunucunun saat dilimiyle
(`Europe/Istanbul`, [config.php:13](backend/config.php#L13)) yapılır.

---

## 6. Benzersizlik kuralları

| Değer | Kapsam | Büyük/küçük harf |
|---|---|---|
| PO numarası | Tüm sistem | Duyarsız (`po-1` = `PO-1`) |
| MA kodu | **Tüm sistem**: farklı PO'larda bile aynı MA kodu kullanılamaz | Duyarsız |
| Barkod | **Tüm sistem**: farklı PO'larda bile aynı barkod kullanılamaz | Duyarsız |

### İki katmanlı koruma

**Katman 1: Servis katmanında ön kontrol** ([UniqueGuard.php:34](backend/src/Services/UniqueGuard.php#L34))

- Kaydetmeden önce aynı değer var mı diye bakılır.
- Varsa hangi alanın ve değerin çakıştığı söylenir. Örnek: `409`, `"field":"barcode"`, mesaj `Barkod "869..." zaten kullanılıyor.`
- Güncellemede kaydın kendisi hariç tutulur; bir kayıt kendi koduyla çakışmış sayılmaz.
- Temsilci ekranındaki anlık kontrol de bu katmanı `/check` ucuyla kullanır.

**Katman 2: Veritabanı kısıtı** (`UNIQUE COLLATE NOCASE`, [001_init.sql](backend/database/migrations/001_init.sql))

- İki cihaz aynı anda aynı kodu gönderirse ikisi de ön kontrolü geçebilir. Bu durumda veritabanı ikinci kaydı reddeder.
- Veritabanının hatası aynı `409` biçimine çevrilir ([UniqueGuard.php:63](backend/src/Services/UniqueGuard.php#L63)).

**Kanıt:**
- Test bölüm 2 (10 kontrol): API üzerinden tekrar eden kayıtlar reddediliyor.
- Test bölüm 7: API atlanıp doğrudan SQL ile tekrar eden kayıt eklenmeye çalışılınca veritabanı `UNIQUE constraint failed` ile reddediyor; küçük harfle yazılan MA kodu da çakışıyor.
- **Bozma denemesi:** Projenin bir kopyasında Katman 1 tamamen kapatıldı; tekrar eden kayıt testleri yine `409` ve doğru `field` ile geçti. Katman 2 tek başına koruyor. Ayrıntı: [12.2](#122-testin-gerçekten-hata-yakaladığının-kanıtı).

Çakışmayı Katman 2 yakalarsa mesajda değer yer almaz (`Barkod zaten kullanılıyor.`). Bu yalnızca
eşzamanlı yazma gibi nadir durumlarda olur.

---

## 7. Veri doğrulama

| Kural | Kod | Hata |
|---|---|---|
| PO no, MA kodu, barkod: zorunlu, 1–64 karakter, sadece harf, rakam ve `. _ - /` | [Validator.php:12-25](backend/src/Services/Validator.php#L12-L25) | 422 + `field` |
| Baştaki ve sondaki boşluklar silinir; sadece boşluktan oluşan değer "boş" sayılır | [Validator.php:16](backend/src/Services/Validator.php#L16) | 422 |
| Adet: pozitif tam sayı, varsayılan 1 | [Validator.php:56](backend/src/Services/Validator.php#L56) | 422 |
| Müşteri en fazla 200, açıklama 500, not 2000 karakter | [Validator.php:29](backend/src/Services/Validator.php#L29) | 422 |
| Termin tarihi: boş veya gerçek bir takvim günü (`2020-02-30` reddedilir) | [Validator.php:43](backend/src/Services/Validator.php#L43) | 422 |
| İstek gövdesi geçerli bir JSON nesnesi olmalı | [Request.php:72](backend/src/Http/Request.php#L72) | 400 |
| Temsilci okutmasında istasyon zorunlu, var olmalı ve aktif olmalı | [ProductionController.php:47](backend/src/Controllers/ProductionController.php#L47), [StationService.php:23](backend/src/Services/StationService.php#L23) | 422 |
| Excel şablonu: en fazla 5 MB ve örnek veriyle doldurulabilmeli | [PoExportService.php:117](backend/src/Services/PoExportService.php#L117) | 422 + `field: file` |

**SQL injection koruması:**
- Kullanıcıdan gelen her değer sorguya parametre olarak bağlanır (`prepare` + `execute`).
- Sorgu metnine eklenen tablo/sütun adları ve koşullar kullanıcıdan gelmez; koddaki sabitlerdir ([UniqueGuard.php:23](backend/src/Services/UniqueGuard.php#L23)).

**XSS koruması:** Arayüz sunucudan gelen veriyi HTML'e basmadan önce `escapeHtml()`
fonksiyonundan geçirir ([api.js:141](frontend/shared/api.js#L141)). Açıklamaya `<script>` yazılsa
bile çalışmaz, düz metin görünür.

**Kanıt:** test bölüm 3 (6 kontrol) ve bölüm 4'teki termin tarihi kontrolleri.

---

## 8. İstasyon ve okutma akışı

### İstasyonlar

Hat sırası: **Elektrik → Freze → CNC → Kalite Kontrol → Paketleme**

| id | Kod | Ad | Kullanıcı | Son istasyon mu? |
|---|---|---|---|---|
| 9 | ELEKTRIK | Elektrik | `elektrik` | |
| 10 | FREZE | Freze | `freze` | |
| 11 | CNC | CNC | `cnc` | |
| 7 | KALITE | Kalite Kontrol | `kalite` | |
| 8 | PAKET | Paketleme | `paketleme` | ✓ (`is_final = 1`) |

Sıra `id` ile değil `sort_order` ile belirlenir ([004_new_stations.sql](backend/database/migrations/004_new_stations.sql)).
İlk kurulumdaki eski istasyonlar (Kesim, Büküm, Kaynak, Taşlama, Boya, Montaj), onların
kullanıcıları, oturumları ve okutma kayıtları [006_remove_old_stations.sql](backend/database/migrations/006_remove_old_stations.sql)
ile silindi. O istasyonlarda görünen iş emirleri kalan son okutmalarına göre konumlandı; hiç
okutması kalmayanlar "Bekliyor" durumuna döndü.

### Durum geçişleri

```
              okut (son istasyon değil)          okut (Paketleme)
  ┌────────┐ ─────────────────────────► ┌──────────────┐ ──────────────► ┌──────┐
  │  open  │                            │ in_progress  │                 │ done │
  │Bekliyor│ ──────────── okut (Paketleme) ──────────────────────────► │      │
  └────────┘                            │ (istasyon X) │ ◄─┐             └──────┘
                                        └──────────────┘   │ okut (başka istasyon)
                                               └───────────┘
  cancelled: yalnızca API ile elle verilir; okutulamaz.
```

### Okutma kuralları

Hepsi [WorkOrderService.php](backend/src/Services/WorkOrderService.php) içindeki `scan()` fonksiyonunda:

| Durum | Sonuç | Satır |
|---|---|---|
| Barkod sistemde yok | 404 `BARCODE_NOT_FOUND` | [:45](backend/src/Services/WorkOrderService.php#L45) |
| İş emri iptal edilmiş | 409 `WORK_ORDER_CANCELLED` | [:187](backend/src/Services/WorkOrderService.php#L187) |
| İş emri tamamlanmış | 409 `WORK_ORDER_DONE` | [:190](backend/src/Services/WorkOrderService.php#L190) |
| İş emri zaten bu istasyonda (çift okutmayı önler) | 409 `ALREADY_AT_STATION` | [:194](backend/src/Services/WorkOrderService.php#L194) |
| Başarılı, son istasyon değil | Durum `in_progress`, iş emri bu istasyona geçer | [:198-207](backend/src/Services/WorkOrderService.php#L198-L207) |
| Başarılı, son istasyon (`is_final`) | Durum `done` | [:198-207](backend/src/Services/WorkOrderService.php#L198-L207) |
| Her başarılı okutma | `scans` tablosuna istasyon ve **okutan kullanıcı** ile kaydedilir | [:212](backend/src/Services/WorkOrderService.php#L212) |

**Eşzamanlılık:**
- Güncelleme koşulludur: `WHERE id = :id AND status IN ('open','in_progress')` ([:201](backend/src/Services/WorkOrderService.php#L201)).
- İki cihaz aynı iş emrini aynı anda Paketleme'de okutursa ikincisinin güncellemesi hiçbir satırı etkilemez ve `409` alır ([:208](backend/src/Services/WorkOrderService.php#L208)).
- Okutma kaydı ve durum güncellemesi tek bir transaction içindedir ([:183](backend/src/Services/WorkOrderService.php#L183)); biri başarısız olursa ikisi birden geri alınır.

**Diğer:**
- Okutulan barkodda büyük/küçük harf fark etmez: `b9` ile okutmak `B9`'u bulur.
- İstasyon sırası zorunlu değildir; iş emri istasyon atlayabilir.
- Okutmayı geri alma bilinçli olarak yok. Hatalı bir konumu yalnızca **yönetici** düzeltir: `POST /work-orders/{id}/move` ile iş emrini herhangi bir istasyona (tamamlanmış olsa bile), Bekliyor'a veya İptal'e taşır ([WorkOrderService.php](backend/src/Services/WorkOrderService.php) `move()`). Aynı konuma taşıma `409 NO_CHANGE` döner. Her taşıma geçmişe `move` olarak, yöneticinin adıyla kaydedilir; geçmiş tablosunda "admin · taşıma" görünür.
- `PUT /work-orders/{id}` ile `status: "open"` verilirse iş emrinin istasyon bilgisi de temizlenir.

**Kanıt:** test bölüm 4. Okutmaların doğru istasyon ve kullanıcıyla kaydedildiği doğrudan
veritabanından kontrol edilir.

---

## 9. Raporlar, analiz ve pano

### İstasyon geçmişi

`GET /work-orders/{id}/scans` iş emrinin geçtiği her istasyonu, okutma zamanını ve okutan
kullanıcıyı eskiden yeniye döner ([WorkOrderService.php:142](backend/src/Services/WorkOrderService.php#L142)).
Arayüz bundan her istasyonda geçen süreyi hesaplar ([shared/history.js](frontend/shared/history.js)).

### PO Takip verisi

`GET /reports/overview` tüm PO'ları ve iş emirlerini tek istekte, her iş emrinin son okutma
zamanıyla birlikte döner ([WorkOrderService.php:116](backend/src/Services/WorkOrderService.php#L116)).

### Termin durumu

Hesap arayüzde yapılır ([shared/due.js](frontend/shared/due.js)):

| Durum | Koşul |
|---|---|
| Gecikti | Termin geçti ve PO bitmedi |
| Yaklaşıyor | Termine 0–2 gün kaldı ve PO bitmedi |
| Zamanında | Termine 2 günden fazla var |
| Tamamlandı | Tüm iş emirleri tamamlandı veya iptal |

İş emri olmayan PO "bitmedi" sayılır.

### İstasyon süre analizi

`GET /reports/stations?days=N` ([ReportService.php](backend/src/Services/ReportService.php)):

- **Bir istasyonda geçen süre** = iş emrinin o istasyondaki okutması ile bir sonraki okutması arasındaki süre (SQL `LEAD` pencere fonksiyonu). Son istasyon için süre ölçülmez.
- Her istasyon için: geçiş sayısı, ortalama ve en uzun süre, şu an orada bekleyen iş emri sayısı.
- **Ortalama üretim süresi:** iş emrinin kaydından son istasyonda okutulmasına kadar.
- **İlk istasyona bekleme:** kayıttan ilk okutmaya kadar.
- Günlük okutma ve tamamlanma sayıları.

Süreler **istasyonlar arası geçiş süresidir**; makinede çalışılan net süreyi değil, iş emrinin o
istasyonda kaldığı toplam süreyi gösterir (bekleme dahil).

### Atölye panosu

`GET /board` istasyon başına bekleyen iş, bugünkü okutma ve tamamlanma sayıları, son 12 okutma,
geciken ve termini yaklaşan PO'ları döner.

**Canlı güncelleme** ([board/app.js](frontend/board/app.js)):
- Pano her **3 saniyede** `GET /board/version` ucunu sorar. Bu uç, okutma, iş emri ve PO tablolarındaki herhangi bir değişiklikte ve gün dönümünde değişen 16 karakterlik bir parmak izi döner ([ReportService.php:168](backend/src/Services/ReportService.php#L168)).
- Parmak izi değişirse tüm veri çekilip çizilir; değişen sayılar kısa süre vurgulanır.
- Değişiklik görünmese de **60 saniyede** bir tam yenileme yapılır.
- Sekme veya ekran gizliyken sorgu durur; görünür olunca hemen kontrol edilir.
- Bağlantı koparsa son veri ekranda kalır, durum çubuğu kırmızı olur.

**Kanıt:** test bölüm 4: geçmiş, rapor, analiz, pano verisi, parmak izinin veri değişmeyince
aynı kalıp değişince değiştiği.

---

## 10. Excel'e aktarma ve şablon

### Kullanım

- **PO Oluşturma** sayfasında PO başlığındaki ve **PO Takip** sayfasında her PO kartındaki **Excel'e aktar** butonu PO'yu `.xlsx` olarak indirir (`GET /pos/{id}/export`).
- Dosya adı PO numarasından türetilir.

### Varsayılan şablon

[backend/templates/po.xlsx](backend/templates/po.xlsx), [build-po-template.php](backend/bin/build-po-template.php) ile üretilir. Üç sayfası var:

| Sayfa | İçerik |
|---|---|
| **PO** | PO bilgileri (müşteri, termin, termin durumu, not), durum sayıları, tüm iş emirleri ve altında toplam adet |
| **Geçmiş** | Her iş emrinin geçtiği istasyonlar, tarihleri, okutan kullanıcılar ve her konumda geçen süre |
| **Dağılım** | İş emirlerinin konumlara göre sayısı ve yüzdesi |

### Şablonu değiştirmek

1. Ana menüden **Excel Şablonu** sayfasını açın (tuş `6`).
2. **Şablonu indir** ile mevcut şablonu alın, Excel'de istediğiniz gibi düzenleyin (logo, renkler, sütun sırası, ek başlıklar). Biçimlendirme olduğu gibi korunur.
3. **Yeni şablon yükle** ile geri yükleyin. Yüklenen dosya önce örnek veriyle doldurulup denenir; bozuk veya Excel olmayan dosya reddedilir ([PoExportService.php:117](backend/src/Services/PoExportService.php#L117)).
4. **Varsayılana dön** yüklenen şablonu siler.

Yüklenen şablon `backend/storage/templates/po.xlsx` dosyasına yazılır ve varsayılanın yerine geçer.
Bu klasör git'e girmez.

### Şablon kuralları

[XlsxTemplate.php](backend/src/Excel/XlsxTemplate.php):

| Kural | Örnek |
|---|---|
| Hücreye yazılan `{anahtar}` gerçek değerle değişir; yazının içinde de kullanılabilir | `{po_number}`, `PO: {po_number}` |
| `{wo.…}`, `{scan.…}` veya `{loc.…}` içeren **satır** listedeki her kayıt için tekrarlanır; altındaki satırlar aşağı kayar. Bir satırda tek liste kullanılmalı | `{wo.ma_code}` |
| Hücrede tek başına duran sayısal yer tutucu Excel'e **sayı** olarak yazılır | `{wo.quantity}` |
| Tekrar satırının altındaki formüller kaydırılır; tekrar satırını kapsayan aralık tüm tekrarları kapsayacak şekilde genişler | `=TOPLA(E12)` → `=TOPLA(E12:E14)` |
| Birleştirilmiş hücreler, koşullu biçimlendirme, veri doğrulama ve bağlantılar da kaydırılır | — |
| Excel dosyayı açarken formülleri yeniden hesaplar | — |

**Kaydırılmayanlar:** başka sayfaya işaret eden formül referansları, Excel Tablosu (Ctrl+T) ve
yazdırma alanı. Bunlar tekrar satırının altındaysa yerinde kalır.

### Yer tutucular

Tam liste Excel Şablonu sayfasında (tıklayınca kopyalanır) ve
[PoExportService.php:23](backend/src/Services/PoExportService.php#L23) içinde.

| Grup | Yer tutucular |
|---|---|
| Genel | `po_number`, `customer`, `note`, `due_date`, `due_status`, `created_at`, `total`, `open`, `in_progress`, `done`, `cancelled`, `completion` (metin, `%33`), `completion_ratio` (sayı, 0–1), `export_date`, `exported_by` |
| İş emri satırı | `wo.no`, `wo.ma_code`, `wo.barcode`, `wo.description`, `wo.quantity`, `wo.status`, `wo.station`, `wo.location`, `wo.last_scan`, `wo.created_at`, `wo.route` (geçtiği istasyonlar), `wo.lead_time` (kayıttan tamamlanmaya süre) |
| Geçmiş satırı | `scan.no`, `scan.ma_code`, `scan.barcode`, `scan.station` (ilk satır: Kayıt), `scan.date`, `scan.user`, `scan.duration` |
| Dağılım satırı | `loc.name`, `loc.count`, `loc.percent` (metin), `loc.ratio` (sayı, 0–1) |

### Teknik ayrıntı

- `.xlsx` bir zip dosyasıdır. PHP'nin `zip` eklentisi gerektirmemek için zip okuma/yazma saf PHP ve `zlib` ile yazıldı ([Zip.php](backend/src/Excel/Zip.php)).
- LibreOffice kaydederken `TOPLA(E12:E12)` formülünü `TOPLA(E12)` olarak kısaltır. Bu yüzden toplama fonksiyonlarının (SUM, AVERAGE, COUNT, MIN, MAX …) tek hücre argümanı da tekrar satırını kapsıyorsa aralığa genişletilir ([XlsxTemplate.php:330](backend/src/Excel/XlsxTemplate.php#L330)).
- Paylaşılan formüller (Excel'in tekrarlayan formülleri sıkıştırması) önce açılır, sonra kaydırılır.
- Hesap zinciri (`calcChain.xml`) silinir ve açılışta tam hesaplama istenir; böylece eski önbellek değerleri görünmez.

**Kanıt:**
- Test bölüm 4: dışa aktarılan dosyada iş emirleri dolu ve hiç yer tutucu kalmamış; üretim kullanıcısı Excel alamıyor; geçersiz şablon reddediliyor; geçerli şablon yükleniyor; varsayılana dönülüyor.
- Elle: üretilen dosyalar LibreOffice ile açıldı, iş emri satırları ve toplam formülü doğru hesaplandı. LibreOffice ile açılıp tekrar kaydedilen şablon da doğru çalıştı.
- **Microsoft Excel ile denenmedi.**

---

## 11. Arayüz (frontend)

Sayfalar ayrıdır ve temsilci için ana menüden yönlendirilir.

### Tasarım yaklaşımı

Arayüz üretim yazılımlarında (MES / SCADA) kullanılan **ISA-101 "yüksek performanslı HMI"**
ilkelerine göre tasarlandı ([base.css](frontend/shared/base.css)):

- **Renk sadece durum bildirir.** Yüzeyler nötr gridir. Yeşil = tamam, amber = hatta, kırmızı = hata/gecikme, mavi = seçim ve odak.
- **Süs yok.** Gölge, gradyan, ikonlu kutucuk kullanılmaz.
- **Yoğun ve tablo odaklı.** Sayılar sabit genişlikte (`tabular-nums`); sütunlar kaymaz.
- **Uygulama çerçevesi:** Üstte başlık çubuğu (sayfa, kullanıcı, rol, istasyon, Çıkış), altta durum çubuğu (sunucu bağlantısı, API adresi, saat; [statusbar.js](frontend/shared/statusbar.js)).
- **Kare durum göstergeleri** (HMI'larda yaygın).
- **Tema:** İşletim sisteminin açık/koyu ayarını izler. İş Emri Okut ve Atölye Panosu her zaman koyudur.

### Giriş — `/login.html`

Kullanıcı adı ve şifre. Zaten giriş yapılmışsa doğrudan rolün sayfasına gider.

### Ana menü — `/` (rep)

- Modüller bir tablo hâlinde listelenir; satırın tamamı tıklanabilir.
- Klavye kısayolları `1`–`6` ([menu.js](frontend/menu.js)):

| Tuş | Modül | Adres |
|---|---|---|
| 1 | PO Oluşturma | `/rep/` |
| 2 | İş Emri Okut | `/production/` |
| 3 | PO Takip | `/report/` |
| 4 | Üretim Analizi | `/analysis/` |
| 5 | Atölye Panosu | `/board/` |
| 6 | Excel Şablonu | `/template/` |

### PO Oluşturma — `/rep/` (rep)

- **Sol panel (PO defteri):**
  - Arama (PO numarası ve müşteri), filtreler: **Tümü / Açık / Termin riski / Biten**, her filtrede kayıt sayısı.
  - Her PO satırında: PO no, tamamlanma yüzdesi, müşteri, termin durumu (gecikti / kaldı), tamamlanan ve hattaki iş emirlerini gösteren yığılmış çubuk, `tamamlanan/toplam`.
  - **Yeni PO** formu açılır panel olarak gelir (kısayol `N`): PO no, müşteri, termin, not.
- **PO detayı:**
  - Başlık: PO numarası, müşteri, kayıt zamanı, **düzenlenebilir termin tarihi** (değiştirilince hemen kaydedilir), **Excel'e aktar**, PO silme.
  - Durum sayıları: Toplam / Bekliyor / Hatta / Tamamlandı.
  - **Konum dağılımı:** iş emirlerinin kaçı nerede, iş emri sayısına ve adete göre yüzde.
  - **İş emri ekleme:** MA kodu, barkod, adet, açıklama. Alandan çıkıldığı anda benzersizlik sorulur; çakışma alanın altında yazar.
  - **İş emri tablosu:** #, MA kodu, barkod, açıklama, adet, durum (üretimdeyse istasyon adı), hat konumu (her istasyon bir hücre, dolu hücre şu anki istasyon).
  - **İş emri satırına tıklayınca** istasyon geçmişi açılır: istasyon, tarih-saat, okutan kullanıcı, o istasyonda geçen süre.
  - Silme işlemlerinden önce onay sorulur.

### İş Emri Okut — `/production/` (production, rep)

- **İstasyon:** Üretim kullanıcısında hesabındaki istasyon seçili gelir ve değiştirilemez; Ana menü bağlantısı gizlenir. Temsilci istasyonu buton grubundan seçer; seçim o tarayıcıda hatırlanır. İstasyon seçmeden okutma yapılmaz.
- **Barkod şimdilik elle yazılır;** Enter'a basılır veya **Okut** butonuna tıklanır. Barkodu yazıp Enter basan, klavye gibi çalışan bir okuyucu da değişiklik gerekmeden çalışır. Barkod kutusu sürekli odakta tutulur.
- **Sonuç paneli:** amber = istasyona alındı, yeşil = tamamlandı, kırmızı = reddedildi. Büyük punto MA kodu, altında PO, barkod, adet ve açıklama. Her sonuca farklı tonda bir bip sesi eşlik eder.
- **Son okutmalar** tablosu yalnızca o oturumda tutulur; sayfa yenilenince sıfırlanır. Kalıcı kayıt sunucudaki `scans` tablosundadır.

### PO Takip — `/report/` (rep)

- Genel durum: tüm iş emirlerinin konumlara göre dağılımı (pasta grafik) ve özet sayılar.
- Her PO için bir kart: konum dağılımı pastası, termin durumu, iş emirleri tablosu, **Excel'e aktar**.
- Arama (PO no, müşteri, MA kodu, barkod), "Tamamlanan PO'ları gizle" ve "Sadece geciken / termini yaklaşan" filtreleri.
- İş emri satırına tıklayınca istasyon geçmişi açılır.
- Grafikler kütüphanesiz, SVG ile çizilir.

### Üretim Analizi — `/analysis/` (rep)

- Dönem: **7 / 30 / 90 gün** (seçim hatırlanır).
- Özet: bugün ve dönemde tamamlanan, ortalama üretim süresi, ortalama ilk istasyona bekleme, şu an hatta, bekleyen.
- İstasyon tablosu: geçiş sayısı, ortalama ve en uzun süre, bekleyen iş emri, çubuk. Ortalaması en uzun istasyon **DARBOĞAZ** olarak işaretlenir.
- Günlük okutma ve tamamlanma grafiği.

### Atölye Panosu — `/board/` (board, rep)

- Uzaktan okunacak büyük punto: istasyon başına bekleyen iş, bugün okutulan ve tamamlanan, bekleyen iş emri, son okutmalar, geciken ve termini yaklaşan PO'lar.
- Canlı güncellenir (bkz. [9](#atölye-panosu)); tam ekran düğmesi var.
- `pano` kullanıcısında Ana menü bağlantısı gizlenir; başka sayfa açmaya çalışırsa panoya geri döner.

### İş Emri Taşıma — `/admin/` (admin)

- Tüm iş emirleri tek tabloda: PO, MA kodu, barkod, açıklama, adet, konum, son hareket. Arama ve durum filtresi (Tümü / Bekliyor / Hatta / Tamamlandı / İptal).
- Satıra tıklayınca taşıma paneli açılır: **Bekliyor**, her istasyon ve **İptal** butonları; şu anki konum işaretli. Butona basınca iş emri hemen taşınır.
- Panelin altında iş emrinin geçmişi görünür.
- Yöneticinin başka sayfası yoktur; başka bir sayfa açmaya çalışırsa bu sayfaya döner.

### Excel Şablonu — `/template/` (rep)

Şablonun durumu (varsayılan / özel, yüklenme zamanı, boyut), indir / yükle / varsayılana dön,
kurallar ve tıklayınca kopyalanan yer tutucu listesi (bkz. [10](#10-excele-aktarma-ve-şablon)).

### Ortak

- Tüm sayfalar aynı API istemcisini kullanır ([shared/api.js](frontend/shared/api.js)). Sunucunun hata kodu, mesajı ve `field` bilgisi `ApiError` nesnesine taşınır.
- **API adresi** sayfanın açıldığı adresten türetilir ([shared/api-base.js](frontend/shared/api-base.js)).

---

## 12. Test etme

### 12.1 Otomatik uçtan uca test

```bash
./scripts/test.sh
```

**Ne yapar** ([scripts/test.sh](scripts/test.sh)):

1. `mktemp` ile **geçici** bir klasörde yeni bir veritabanı oluşturur, tüm migration'ları uygular ve örnek veriyi ekler. Özel şablon yolu da geçici klasöre yönlendirilir.
2. API'yi `127.0.0.1:8799` adresinde geçici bir sunucuyla başlatır. Port `TEST_PORT=...` ile değiştirilebilir.
3. `mami`, `freze`, `cnc` ve `pano` hesaplarıyla gerçekten giriş yapar ve token alır.
4. Gerçek HTTP istekleri gönderir; her yanıtın **HTTP kodunu** ve **içeriğini** beklenenle karşılaştırır.
5. Bazı kontrolleri API'yi atlayıp doğrudan SQL ile yapar (veritabanı kısıtları, okutma kayıtları, bağlı kayıtların silinmesi).
6. Sunucu logunda PHP hatası (`fatal`, `uncaught`) var mı diye bakar.
7. Bitince sunucuyu durdurur ve geçici klasörü siler.

**Gerçek veritabanına (`backend/storage/app.sqlite`) ve yüklenmiş şablona dokunmaz.** `dev.sh`
açıkken de çalıştırılabilir. Hepsi geçerse çıkış kodu `0`, en az biri kalırsa `1`.

**Kapsam:**

| Bölüm | Kontrol | Doğruladığı |
|---|---|---|
| 1. Giriş ve rol yetkileri | 10 | Yanlış şifre, girişsiz ve geçersiz token 401; yanlış rol 403; `/me`; çıkıştan sonra token geçersiz |
| 2. Benzersizlik | 10 | PO/MA/barkod tekrarı (büyük/küçük harf ve farklı PO dahil); güncellemede çakışma; `/check` |
| 3. Veri doğrulama | 6 | Boş, geçersiz karakterli, 65 karakterlik değerler; adet 0; bozuk JSON; olmayan PO |
| 4. Okutma, raporlar, pano, Excel, taşıma | 47 | Okutma akışı ve tüm hata durumları; geçmiş; PO Takip; termin; analiz; pano verisi ve parmak izi; pano ve üretim kullanıcısının yetki sınırları; Excel dışa aktarma ve şablon yükleme/sıfırlama; üretim kullanıcısının istasyonunun sabit olması; okutan kullanıcının kaydı; yönetici taşıması (tamamlanmışı geri alma, Bekliyor'a alma, aynı konum, geçersiz hedef, geçmiş kaydı) ve yöneticinin/diğer rollerin yetki sınırları |
| 5. Silme | 3 | PO silinince iş emirleri ve okutmaları da silinir |
| 6. Yönlendirici | 2 | Olmayan uç 404, yanlış metod 405 |
| 7. Veritabanı katmanı | 6 | Doğrudan SQL ile UNIQUE/NOCASE/CHECK kısıtları; 7 migration'ın kaydı; migrate'in tekrar çalıştırılabilmesi |

<details>
<summary>Son çalıştırmanın tam çıktısı (2026-10-05): <b>84 geçti, 0 kaldı</b></summary>

```
1) Giriş ve rol yetkileri
  ✓ health girişsiz açık
  ✓ yanlış şifre reddedilir
  ✓ girişsiz istek reddedilir
  ✓ geçersiz token reddedilir
  ✓ üretim kullanıcısı PO listeleyemez
  ✓ üretim kullanıcısı PO oluşturamaz
  ✓ üretim kullanıcısı istasyonları görür
  ✓ /me kullanıcı ve istasyonu döndürür
  ✓ çıkış yapılır
  ✓ çıkıştan sonra token geçersiz
2) Benzersizlik (PO no, MA kodu, barkod)
  ✓ aynı PO no (küçük harfle) reddedilir
  ✓ yeni PO oluşturulur (id=2)
  ✓ başka PO'da aynı MA kodu reddedilir
  ✓ başka PO'da aynı barkod reddedilir
  ✓ benzersiz iş emri eklenir (id=3)
  ✓ kendi kodlarıyla güncelleme serbest
  ✓ güncellemede başkasının barkodu yasak
  ✓ PO'yu dolu numaraya çevirmek yasak
  ✓ /check: dolu barkod (küçük harf)
  ✓ /check: boş barkod
3) Veri doğrulama
  ✓ boş PO no reddedilir
  ✓ geçersiz karakterli MA kodu reddedilir
  ✓ 65 karakterlik barkod reddedilir
  ✓ adet 0 reddedilir
  ✓ bozuk JSON reddedilir
  ✓ olmayan PO'ya iş emri eklenemez
4) Okutma ve istasyon akışı
  ✓ istasyonsuz okutma reddedilir
  ✓ olmayan istasyon reddedilir
  ✓ bilinmeyen barkod
  ✓ Elektrik'te okut → üretimde
  ✓ aynı istasyonda tekrar okutma yasak
  ✓ küçük harf barkodla Freze'de okut
  ✓ PO detayında istasyon görünür
  ✓ Paketleme (son) → tamamlandı
  ✓ tamamlanmış iş emri okutulamaz
  ✓ PO listesinde tamamlanan sayısı
  ✓ iş emri iptal edilebilir
  ✓ iptal iş emri okutulamaz
  ✓ open'a alınınca istasyon temizlenir
  ✓ barkod sorgulama (lookup)
  ✓ her okutma istasyonuyla kaydedildi
  ✓ iş emri istasyon geçmişi (tarih + okutan)
  ✓ PO takip raporu (PO + iş emirleri)
  ✓ PO termin tarihiyle kaydedilir
  ✓ geçersiz termin tarihi reddedilir
  ✓ istasyon süre analizi
  ✓ pano verisi (geciken PO dahil)
  ✓ pano sürümü değişmezse aynı kalır
  ✓ veri değişince pano sürümü değişir
  ✓ pano kullanıcısı PO göremez
  ✓ pano kullanıcısı okutma yapamaz
  ✓ üretim kullanıcısı panoyu göremez
  ✓ PO Excel'e aktarılır (iş emirleri dolu, yer tutucu kalmaz)
  ✓ üretim kullanıcısı Excel alamaz
  ✓ şablon bilgisi ve yer tutucular
  ✓ geçersiz şablon reddedilir
  ✓ geçerli şablon yüklenir
  ✓ varsayılan şablona dönülür
  ✓ üretim kullanıcısı raporu göremez
  ✓ üretim kullanıcısı geçmişi göremez
  ✓ üretim kullanıcısının istasyonu sabit (gövde yok sayılır)
  ✓ okutmayı yapan kullanıcı kaydedildi
  ✓ yönetici iş emrini Paketleme'ye taşır → tamamlandı
  ✓ yönetici tamamlanmışı geri istasyona alır
  ✓ aynı konuma taşıma reddedilir
  ✓ yönetici Bekliyor'a alır
  ✓ geçersiz hedef reddedilir
  ✓ taşıma geçmişte yöneticiyle görünür
  ✓ temsilci taşıma yapamaz
  ✓ üretim kullanıcısı taşıma yapamaz
  ✓ yönetici PO oluşturamaz
  ✓ yönetici okutma yapamaz
  ✓ yönetici Excel şablonunu değiştiremez
5) Silme ve bağlı kayıtlar
  ✓ PO silinir
  ✓ silinen PO'nun iş emri de silindi
  ✓ silinen iş emrinin okutmaları da silindi
6) Yönlendirici
  ✓ olmayan uç
  ✓ desteklenmeyen metod
7) Veritabanı katmanı (API'yi atlayarak doğrudan SQL)
  ✓ UNIQUE: aynı barkod SQL ile de eklenemez
  ✓ NOCASE: küçük harf MA kodu da çakışır
  ✓ UNIQUE: aynı PO no SQL ile de eklenemez
  ✓ CHECK: geçersiz durum değeri eklenemez
  ✓ migration'lar kayıtlı
  ✓ migrate tekrar çalıştırılabilir (idempotent)

Sonuç: 84 geçti, 0 kaldı
```

</details>

### 12.2 Testin gerçekten hata yakaladığının kanıtı

Her zaman "geçti" diyen bir test hiçbir şey kanıtlamaz. Bu yüzden projenin **bir kopyasında**
kod kasıtlı olarak bozuldu ve test tekrar çalıştırıldı. Asıl kodda bu bozma yapılmadı.

> Bu deneme, giriş sistemi gelmeden önceki sürümde (50 kontrol) yapıldı. Bozulan iki kural
> (rol yetkisi ve benzersizlik ön kontrolü) mevcut kodda da aynı yerde duruyor.

**Yapılan iki bozma:**
1. `routes.php` içinde okutma ucuna yetkisiz bir rol eklendi.
2. `UniqueGuard::assertAvailable()` boşaltıldı; Katman 1 ön kontrolü devre dışı kaldı.

**Sonuç:** `46 geçti, 4 kaldı`. Yetki açığı (403 yerine 201), `/check` sonucu ve yetkisiz
okutmanın kayıtlarda görünmesi yakalandı.

**Önemli gözlem:** Katman 1 kapalıyken bile tekrar eden kayıt testlerinin hepsi `409` ve doğru
`field` ile **geçti**. Veritabanı kısıtı ve hata çevirisi (Katman 2) tek başına koruma sağlıyor.

### 12.3 Elle API testi (curl)

```bash
# Sağlık
curl http://localhost:8000/api/v1/health

# Giriş: yanıttaki "token" değerini alın
curl -X POST -H 'Content-Type: application/json' \
  -d '{"username":"mami","password":"123"}' http://localhost:8000/api/v1/auth/login

TOKEN=...   # yukarıdaki yanıttan

# PO listesi
curl -H "Authorization: Bearer $TOKEN" http://localhost:8000/api/v1/pos

# Yeni PO (termin tarihiyle)
curl -X POST -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"po_number":"PO-TEST-1","customer":"Deneme","due_date":"2026-12-31"}' http://localhost:8000/api/v1/pos

# Tekrar aynı PO → 409 DUPLICATE
curl -X POST -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"po_number":"po-test-1"}' http://localhost:8000/api/v1/pos

# Freze istasyonunda (id=10) okut (temsilci istasyonu kendisi seçer)
curl -X POST -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"barcode":"8690000000011","station_id":10}' http://localhost:8000/api/v1/production/scan

# PO'yu Excel'e aktar
curl -H "Authorization: Bearer $TOKEN" -o po.xlsx http://localhost:8000/api/v1/pos/1/export
```

> Bu komutlar `dev.sh`'ın kullandığı **gerçek** veritabanına yazar. Deneme verisi bırakmak
> istemiyorsanız `./scripts/test.sh` kullanın.

### 12.4 Arayüzden elle test senaryosu

`./scripts/dev.sh` açıkken:

| # | Adım | Beklenen |
|---|---|---|
| 1 | http://localhost:5173/ adresini aç | Giriş ekranı açılır |
| 2 | `mami` / `123` ile giriş yap | Ana menü açılır, sağ altta "Sunucu bağlı" yazar |
| 3 | `1` tuşuna bas, `N` tuşuna bas | PO Oluşturma açılır, yeni PO formu açılır |
| 4 | PO numarasına `po-2026-001` yaz ve alandan çık | Alanın altında "zaten kullanılıyor" yazar |
| 5 | `PO-DENEME` numaralı, termini dün olan bir PO oluştur | PO listede "1 gün gecikti" ile görünür |
| 6 | MA kodu `MA-D1`, barkod `D1` ile iş emri ekle | Tabloda durum "Bekliyor" |
| 7 | Başka bir tarayıcıda (veya gizli pencerede) `freze` / `123` ile giriş yap | Doğrudan İş Emri Okut açılır, Freze seçili ve değiştirilemez |
| 8 | `D1` yaz ve Enter'a bas | Amber ekranda `MA-D1 → Freze` |
| 9 | Aynı barkodu tekrar okut | Kırmızı ekranda "zaten Freze istasyonunda" |
| 10 | Temsilci ekranında PO'yu yenile ve iş emri satırına tıkla | Durum "Freze"; geçmişte Freze, saat ve `freze` kullanıcısı görünür |
| 11 | `paketleme` / `123` ile giriş yapıp `D1`'i okut | Yeşil ekranda "TAMAMLANDI" |
| 12 | Temsilci ekranında **Excel'e aktar**'a tıkla | `.xlsx` iner; iş emri, geçmiş ve dağılım sayfaları dolu |
| 13 | Başka bir pencerede `pano` / `123` ile giriş yap, sonra bir iş emri okut | Pano birkaç saniye içinde kendiliğinden güncellenir |

### 12.5 Testin kapsamadığı şeyler

- **Arayüzün otomatik testi yok.** Arayüz, geliştirme sırasında tarayıcıda elle denendi. Excel Şablonu sayfası ve Excel'e aktar butonları tarayıcıda denenmedi; aynı uçlar otomatik testte doğrulandı.
- **Microsoft Excel ile deneme yapılmadı;** Excel dosyaları LibreOffice ile doğrulandı.
- **Gerçek eşzamanlılık testi yok.** Bu koruma kod incelemesine ([bölüm 8](#8-istasyon-ve-okutma-akışı)) ve bozma denemesine ([12.2](#122-testin-gerçekten-hata-yakaladığının-kanıtı)) dayanıyor.
- **CORS başlıkları ve Apache `.htaccess` kurulumu test edilmedi.**
- **Yük ve performans testi yapılmadı.**

---

## 13. Migration (şema güncelleme) sistemi

[Database.php](backend/src/Database.php) içindeki `migrate()`:

1. Her veritabanı bağlantısında `database/migrations/*.sql` dosyaları isim sırasıyla taranır.
2. `schema_migrations` tablosunda kaydı olmayan dosyalar uygulanır.
3. Her dosya kendi transaction'ında çalışır. Hata olursa o dosyanın değişiklikleri geri alınır ve uygulama hata verir. Yarım kalmış bir şema oluşmaz.
4. İlk satırı `-- migrate:no-foreign-keys` olan dosyalar yabancı anahtarlar kapalıyken çalışır ([Database.php:70](backend/src/Database.php#L70)). SQLite'ta bir tablonun CHECK kısıtını değiştirmek için tabloyu yeniden kurmak gerekir; yabancı anahtarlar açıkken bu mümkün değil. Commit'ten önce `PRAGMA foreign_key_check` ile bütünlük doğrulanır; bozulmuşsa her şey geri alınır ([Database.php:78](backend/src/Database.php#L78)).

**Mevcut migration'lar:**

| Dosya | İçerik |
|---|---|
| `001_init.sql` | Temel tablolar: PO, iş emri, okutma |
| `002_stations.sql` | İstasyonlar tablosu; iş emrine `current_station_id`; eski "okutulan adet" sütunu kaldırıldı |
| `003_auth.sql` | Kullanıcılar ve oturumlar; `mami` ve istasyon hesapları; okutmalara `user_id` |
| `004_new_stations.sql` | Elektrik, Freze, CNC eklendi; hat sırası yeniden düzenlendi; `elektrik`, `freze`, `cnc` hesapları |
| `005_due_date_board.sql` | PO'ya termin tarihi; `board` rolü (users tablosu yeniden kuruldu); `pano` hesabı |
| `006_remove_old_stations.sql` | Eski istasyonlar ve onlara bağlı okutma, kullanıcı ve oturumlar silindi; iş emirleri yeniden konumlandı |
| `007_admin.sql` | `admin` rolü (users tablosu yeniden kuruldu) ve hesabı; hareket kayıtlarına `kind` (`scan` / `move`) ve `status` (hareketten sonraki durum) |

**Kural:** Uygulanmış bir migration dosyasını **değiştirmeyin**. O değişiklik, dosyanın zaten
uygulandığı veritabanlarına hiçbir zaman ulaşmaz. Yeni bir değişiklik için yeni numaralı bir dosya
ekleyin (ör. `008_xxx.sql`).

`004`, `005` ve `006` mevcut verisi olan veritabanının kopyası üzerinde denendi; PO'lar ve iş
emirleri korundu.

**Kanıt:** test bölüm 7, yedi migration'ın da kayıtlı olduğunu ve `migrate.php`'nin tekrar
çalıştırılınca hata vermediğini doğrular.

---

## 14. Yapılandırma

Backend ayarları ortam değişkenleriyle değiştirilebilir ([config.php](backend/config.php)):

| Değişken | Varsayılan | Açıklama |
|---|---|---|
| `BARKOD_DB_PATH` | `backend/storage/app.sqlite` | Veritabanı dosyası |
| `BARKOD_CORS_ORIGINS` | `*` | İzinli origin'ler, virgülle ayrılır |
| `BARKOD_TIMEZONE` | `Europe/Istanbul` | Excel'deki tarihler ve "bugün" hesapları için saat dilimi |
| `BARKOD_PO_TEMPLATE` | `backend/storage/templates/po.xlsx` | Yüklenen özel Excel şablonunun yazılacağı yer |
| `API_PORT`, `WEB_PORT` | `8000`, `5173` | `dev.sh` portları |

Varsayılan Excel şablonu `backend/templates/po.xlsx`. Yeniden üretmek için:

```bash
php backend/bin/build-po-template.php
```

### Başka bir bilgisayardan erişim

`dev.sh` sunucuları `0.0.0.0` adresinde dinler; ağdaki tüm cihazlar bağlanabilir.

API adresi sabit yazılı değildir; sayfanın açıldığı adresten türetilir
([api-base.js](frontend/shared/api-base.js)). `http://192.168.1.10:5173/` adresinden açılan sayfa
API'yi `http://192.168.1.10:8000`'de arar. Böylece ayar yapmadan hem sunucu bilgisayarda
(`localhost`) hem istasyon bilgisayarlarında çalışır.

Her istasyon bilgisayarında tarayıcıdan `http://<sunucu-ip>:5173/` açılır ve o istasyonun
hesabıyla giriş yapılır. Oturum 7 gün o tarayıcıda kalır.

Bağlanılamıyorsa:
1. O bilgisayardan `http://<sunucu-ip>:8000/api/v1/health` adresini açın.
2. Açılmıyorsa sunucu bilgisayarın güvenlik duvarı 8000 ve 5173 portlarını engelliyor olabilir.

API'nin portu değişirse [api-base.js](frontend/shared/api-base.js) içindeki `API_PORT` sabitini
değiştirin.

---

## 15. Bilinen sınırlamalar

| # | Sınırlama | Ayrıntı |
|---|---|---|
| 1 | **Güvenlik yerel ağ seviyesinde** | Bkz. [bölüm 16](#16-güvenlik-durumu) |
| 2 | **Kullanıcılar ve şifreler arayüzden yönetilemiyor** | Yeni kullanıcı veya şifre değişikliği için migration dosyası ya da doğrudan SQL gerekir |
| 3 | **İstasyonlar arayüzden yönetilemiyor** | Değiştirmek için yeni bir migration dosyası gerekir |
| 4 | **PO listesinde en fazla 200 PO** | Daha eskilerine arama ile ulaşılır. API sayfalamayı destekliyor (`limit` en fazla 200, `offset`; [PurchaseOrderController.php:22](backend/src/Controllers/PurchaseOrderController.php#L22)) |
| 5 | **Arayüzde iş emri düzenleme ve iptal yok** | API destekliyor (`PUT /work-orders/{id}`); arayüzde ekleme ve silme var |
| 6 | **Elle durum değişikliği denetlenmiyor** | `PUT` ile `done` olan bir iş emri tekrar `open` yapılabilir |
| 7 | **İstasyon sırası zorunlu değil** | İş emri istasyon atlayabilir veya geri dönebilir; sadece "aynı istasyonda iki kez" ve "tamamlandıktan sonra" engellenir |
| 8 | **Silme kalıcıdır** | PO silinince iş emirleri ve okutma geçmişi geri dönüşsüz silinir |
| 9 | **İstasyon süreleri bekleme dahil** | Bir istasyondaki süre, iki okutma arasındaki süredir; net işlem süresi değildir |
| 10 | **Excel şablonunda bazı yapılar kaydırılmaz** | Başka sayfaya işaret eden formüller, Excel Tablosu, yazdırma alanı ([10](#şablon-kuralları)) |
| 11 | **Pano anlık değil, en geç ~3 saniye gecikmeli** | Sunucudan itme (WebSocket/SSE) yok; sorgulama ile çalışır |
| 12 | **Migration kontrolü her istekte yapılıyor** | Bir dosya taraması ve bir sorgu; küçük bir yük |
| 13 | **`php -S` sadece geliştirme sunucusu** | Gerçek kullanım için Nginx/Apache + PHP-FPM gerekir ([.htaccess](backend/public/.htaccess) hazır; `Authorization` başlığını da PHP'ye iletir) |

---

## 16. Güvenlik durumu

**Mevcut korumalar** (test bölüm 1, 3, 4 ve 7 ile doğrulandı):
- **Kullanıcı adı / şifre ile giriş**, bcrypt ile saklanan şifreler.
- **Süreli oturum token'ı** (7 gün); veritabanında sadece özeti tutulur; çıkışta silinir.
- **Rol kontrolü sunucuda** ([Router.php:34](backend/src/Http/Router.php#L34)). Üretim kullanıcısı sadece kendi istasyonunda okutabilir; pano kullanıcısı sadece pano verisini görür.
- **Her okutmada kimin yaptığı kaydedilir.**
- **Girdi doğrulama** ([bölüm 7](#7-veri-doğrulama)), **parametreli sorgular**, **arayüzde HTML kaçışlama**.
- **500 hatalarında iç ayrıntı sızdırılmaz.**

**Açıklar.** Gerçek veriyle kullanmadan önce bunlar giderilmeli:

| Açık | Ayrıntı |
|---|---|
| **Tüm başlangıç şifreleri `123`** | Bu README'de ve migration dosyalarında yazıyor. Şifre değiştirme arayüzü yok |
| **Bağlantı şifresiz (HTTP)** | Ağı dinleyen biri şifreleri ve token'ları görebilir |
| **Deneme sayısı sınırı yok** | Şifre tahmin denemeleri engellenmiyor |
| **Sunucular tüm ağ arayüzlerinde dinliyor** | [dev.sh](scripts/dev.sh) |
| **CORS herkese açık (`*`)** | CORS zaten tarayıcı dışı istemcileri durdurmaz; token zorunluluğu asıl korumadır |
| **Token tarayıcının `localStorage`'ında** | Sayfada bir XSS açığı olursa token okunabilir (HTML kaçışlama bunu önlemek için var) |

**Önerilen sonraki adımlar:** şifreleri değiştirme (arayüz veya migration), deneme sınırı, HTTPS
(ör. Caddy ile), kullanıcı yönetimi ekranı.

O zamana kadar sistemi **sadece güvenilir yerel ağda** kullanın ve internete açmayın.

---

## 17. Geliştirme rehberi

### Yeni bir client (cihaz) eklemek

1. Uçlar ve izinli roller [bölüm 4](#uçlar-ve-izinli-roller)'te. Cevap biçimi `{"data":...}`, hata biçimi `{"error":{"code","message","field"}}`.
2. Cihaz `POST /api/v1/auth/login` ile giriş yapar, dönen `token`'ı her istekte `Authorization: Bearer <token>` başlığıyla gönderir. 401 alırsa tekrar giriş yapar.
3. Barkod okuma cihazı için istasyonun `production` hesabıyla giriş yapmak ve şu ucu çağırmak yeterli:
   - `POST /api/v1/production/scan` gövde: `{"barcode":"..."}` (istasyon hesaptan gelir)
4. Yeni bir rol gerekiyorsa `users.role` CHECK kısıtı için yeni bir migration yazın (bkz. `005`) ve [routes.php](backend/routes.php) içinde rolü ilgili uçlara ekleyin.

> [docs/API.md](docs/API.md) giriş sistemi öncesinden kalma; eski API anahtarı yöntemini anlatıyor.
> Güncel uç listesi bu README'dedir.

### Yeni bir sayfa eklemek

1. `frontend/<sayfa>/` altında ayrı bir sayfa oluşturun.
2. `requireSession([...roller])` ile oturum isteyin, API'ye `apiFor(session)` ile bağlanın ([shared/auth.js](frontend/shared/auth.js)).
3. Ana menüye ([frontend/index.html](frontend/index.html)) bir satır ekleyin.

### Şemayı değiştirmek

1. `backend/database/migrations/008_aciklama.sql` dosyasını oluşturun. Tablo yeniden kurmanız gerekiyorsa ilk satıra `-- migrate:no-foreign-keys` yazın.
2. Bir sonraki API isteğinde dosya otomatik uygulanır. Elle kontrol etmek için:

   ```bash
   php backend/bin/migrate.php
   ```

3. `scripts/test.sh` içindeki "migration'lar kayıtlı" kontrolüne yeni dosyayı ekleyin ve `./scripts/test.sh` çalıştırın.

### Yeni bir iş kuralı eklemek

1. Kuralı ilgili servise yazın (`backend/src/Services/`).
2. Hatalarda `HttpException` kullanın ve anlamlı bir `code` verin.
3. `scripts/test.sh` dosyasına, kuralın hem geçen hem reddedilen hâlini deneyen birer `check` satırı ekleyin.
