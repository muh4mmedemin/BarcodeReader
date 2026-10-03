# BarcodeReader — Barkod / İş Emri Takip Sistemi

Müşteri temsilcisi PO (satın alma siparişi) açar ve PO'lara iş emri ekler. Üretimde her istasyon,
iş emrinin barkodunu okutarak iş emrini kendi istasyonuna alır. Temsilci her iş emrinin şu an hangi
istasyonda olduğunu görür. Son istasyonda (Paketleme) okutulan iş emri tamamlanır.

Bu belge sistemin **nasıl çalıştığını, nasıl test edileceğini ve neyin henüz eksik olduğunu**
anlatır. Her iddianın yanında kodda nerede yapıldığını gösteren bir bağlantı (`dosya:satır`) vardır.
Davranışlarla ilgili iddiaların hepsi [otomatik test script'i](scripts/test.sh) ile doğrulanır
(bkz. [Test etme](#11-test-etme)).

## İçindekiler

1. [Hızlı başlangıç](#1-hızlı-başlangıç)
2. [Mimari](#2-mimari)
3. [Bir isteğin yolculuğu](#3-bir-isteğin-yolculuğu)
4. [Roller ve yetkiler](#4-roller-ve-yetkiler)
5. [Veri modeli](#5-veri-modeli)
6. [Benzersizlik kuralları](#6-benzersizlik-kuralları)
7. [Veri doğrulama](#7-veri-doğrulama)
8. [İstasyon ve okutma akışı](#8-istasyon-ve-okutma-akışı)
9. [Migration (şema güncelleme) sistemi](#9-migration-şema-güncelleme-sistemi)
10. [Arayüz (frontend)](#10-arayüz-frontend)
11. [Test etme](#11-test-etme)
12. [Yapılandırma](#12-yapılandırma)
13. [Bilinen sınırlamalar](#13-bilinen-sınırlamalar)
14. [Güvenlik durumu](#14-güvenlik-durumu)
15. [Geliştirme rehberi](#15-geliştirme-rehberi)

---

## 1. Hızlı başlangıç

### Gereksinimler

| Gereksinim | Neden | Kontrol |
|---|---|---|
| PHP 8.1+ (8.4 ile test edildi) | Backend (kod `readonly` özellikler ve first-class callable `fn(...)` sözdizimi kullanır; ikisi de 8.1 ile geldi) | `php -v` |
| `pdo_sqlite` eklentisi | Veritabanı bağlantısı. Yoksa backend açıkça hata verir ([Database.php:15-16](backend/src/Database.php#L15-L16)) | `php -m \| grep pdo_sqlite` |
| `curl` | Sadece test script'i için | `curl --version` |

Debian/Ubuntu'da SQLite eklentisini kurmak için:

```bash
sudo apt install php8.4-sqlite3
```

`mbstring` eklentisi **gerekmez**. Metin uzunluğu bilerek regex ile sayılıyor
([Validator.php:36](backend/src/Services/Validator.php#L36)).

### Çalıştırma

```bash
./scripts/dev.sh
```

Bu komut ([scripts/dev.sh](scripts/dev.sh)):

1. Veritabanını hazırlar ve örnek veri ekler. Örnek veri zaten varsa atlar ([dev.sh:11](scripts/dev.sh#L11), [migrate.php:26](backend/bin/migrate.php#L26)).
2. **API sunucusunu** `:8000` portunda başlatır ([dev.sh:13](scripts/dev.sh#L13)).
3. **Arayüz sunucusunu** `:5173` portunda, ayrı bir süreç olarak başlatır ([dev.sh:15](scripts/dev.sh#L15)).

| Adres | Ne var |
|---|---|
| http://localhost:5173/ | **Ana menü** |
| http://localhost:5173/rep/ | PO Oluşturma (temsilci) |
| http://localhost:5173/production/ | İş Emri Okut (üretim) |
| http://localhost:8000/api/v1/health | API sağlık kontrolü |

**Örnek veri:** `PO-2026-001` numaralı bir PO ve iki iş emri gelir:
`MA-0001` / `8690000000011` (3 adet) ve `MA-0002` / `8690000000028` (1 adet).

Veritabanı dosyası `backend/storage/app.sqlite`. Git'e eklenmez ([.gitignore](.gitignore)).

---

## 2. Mimari

```
 ┌───────────────────────┐   ┌───────────────────────┐   ┌─────────────────────────┐
 │ Ana menü  /           │   │                       │   │ İleride: senin ürettiğin│
 │  ├─ PO Oluşturma /rep │   │ İş Emri Okut          │   │ okuma cihazları, mobil  │
 │  │  (anahtar: rep)    │   │ /production           │   │ uygulama ...            │
 │  └─────────────────── │   │ (anahtar: production) │   │                         │
 └──────────┬────────────┘   └──────────┬────────────┘   └────────────┬────────────┘
            │        HTTP + JSON, her istekte X-API-Key başlığı        │
            └──────────────────────────┬──────────────────────────────┘
                              ┌────────▼─────────┐
                              │ Backend (PHP)    │  REST API: /api/v1/...
                              │ :8000            │  Sözleşme: docs/API.md
                              └────────┬─────────┘
                              ┌────────▼─────────┐
                              │ SQLite           │  backend/storage/app.sqlite
                              └──────────────────┘
```

### Temel kararlar

- **Backend ile arayüz birbirinden bağımsız.**
  - Backend hiç HTML üretmez, sadece JSON döner ([Response.php:17](backend/src/Http/Response.php#L17)).
  - İki ayrı sunucu, iki ayrı portta çalışır.
  - Arayüz, backend'e yalnızca HTTP üzerinden ulaşır ([api.js:23](frontend/shared/api.js#L23)).
  - Yeni bir cihaz için client yazarken backend'e dokunmak gerekmez; [docs/API.md](docs/API.md) sözleşmesine uymak yeterli.
- **Tüm iş kuralları backend'de.** Arayüz kural uygulamaz; sadece sorar ve gösterir. Arayüzün yaptığı anlık benzersizlik kontrolü bile backend'deki `/check` ucunu çağırır ([rep/app.js:53](frontend/rep/app.js#L53)).
- **Framework ve Composer yok.** Sınıflar 8 satırlık bir otomatik yükleyiciyle yüklenir ([bootstrap.php](backend/bootstrap.php)).
- **Arayüzde build adımı yok.** Saf HTML/CSS/JS ve tarayıcının yerel ES modülleri.

### Klasör yapısı

```
backend/
  public/index.php            Tek giriş noktası: CORS, kimlik, hata yakalama, bağımlılıkları kurma
  public/.htaccess            Apache ile yayınlarken tüm istekleri index.php'ye yönlendirir
  routes.php                  Tüm API uçları ve her uca hangi rolün erişebileceği
  config.php                  DB yolu, CORS, API anahtarları (ortam değişkeniyle ezilebilir)
  bootstrap.php               Otomatik yükleyici + config
  bin/migrate.php             Migration durumunu gösterir; --seed ile örnek veri ekler
  database/migrations/        Numaralı şema dosyaları (001_init.sql, 002_stations.sql)
  storage/                    SQLite dosyası (git'e girmez)
  src/
    Database.php              Bağlantı, PRAGMA ayarları, migration çalıştırıcı
    Http/                     Request, Response, Router, HttpException
    Services/                 İş kuralları: PO, iş emri, okutma, istasyon, benzersizlik, doğrulama
    Controllers/              HTTP isteğini servise bağlayan ince katman
frontend/
  index.html, menu.css/js     Ana menü
  rep/                        PO Oluşturma sayfası (temsilci)
  production/                 İş Emri Okut sayfası (üretim)
  shared/api.js               Tek API istemcisi (tüm sayfalar bunu kullanır)
  shared/base.css             Ortak stil, açık/koyu tema
docs/API.md                   API sözleşmesi (yeni client yazanlar için)
scripts/dev.sh                Geliştirme sunucularını başlatır
scripts/test.sh               Uçtan uca otomatik test (50 kontrol)
```

---

## 3. Bir isteğin yolculuğu

Örnek: üretim ekranında `B9` barkodu Kaynak istasyonunda okutuluyor.

```
Tarayıcı ──POST /api/v1/production/scan  {"barcode":"B9","station_id":3}──► index.php
```

| # | Adım | Kod |
|---|---|---|
| 1 | Sayfa isteği, kendi anahtarını `X-API-Key` başlığına koyarak gönderir | [api.js:27](frontend/shared/api.js#L27) |
| 2 | `index.php` isteği okur ve CORS başlıklarını ekler. Tarayıcının ön kontrol (`OPTIONS`) isteğine boş cevap verir | [index.php:21-27](backend/public/index.php#L21-L27) |
| 3 | Anahtardan rol bulunur. Anahtar var ama tanınmıyorsa **401 INVALID_API_KEY** döner | [index.php:31-35](backend/public/index.php#L31-L35) |
| 4 | Veritabanına bağlanılır; bekleyen migration varsa uygulanır | [index.php:37](backend/public/index.php#L37), [Database.php:34](backend/src/Database.php#L34) |
| 5 | Router yolu eşleştirir ve rol bu uç için izinli mi bakar. İzinsizse **403**, metod yanlışsa **405**, yol yoksa **404** döner | [Router.php:34-45](backend/src/Http/Router.php#L34-L45) |
| 6 | Controller girdiyi alır; `station_id` yoksa **422** döner | [ProductionController.php:41-45](backend/src/Controllers/ProductionController.php#L41-L45) |
| 7 | Servis iş kurallarını uygular (bkz. [bölüm 8](#8-istasyon-ve-okutma-akışı)) | [WorkOrderService.php:125](backend/src/Services/WorkOrderService.php#L125) |
| 8 | Cevap her zaman `{"data": ...}` zarfıyla döner | [Response.php:17](backend/src/Http/Response.php#L17) |
| 9 | Hatalar tek tip `{"error":{"code","message","field"}}` biçimine çevrilir. Anahtarsız istek 403 yerine **401 UNAUTHORIZED** alır. Beklenmeyen hatalar loglanır ve dışarıya ayrıntı verilmeden **500** döner | [index.php:52-60](backend/public/index.php#L52-L60), [HttpException.php:37](backend/src/Http/HttpException.php#L37) |

---

## 4. Roller ve yetkiler

Rol, istekle gelen API anahtarından belirlenir ([config.php:16-19](backend/config.php#L16-L19)):

| Anahtar (geliştirme) | Rol | Kullanan sayfa |
|---|---|---|
| `dev-rep-key` | `rep` | PO Oluşturma ([rep/config.js:4](frontend/rep/config.js#L4)) |
| `dev-production-key` | `production` | İş Emri Okut ([production/config.js:4](frontend/production/config.js#L4)) |

Her ucun izinli rolleri [routes.php](backend/routes.php) içinde tanımlı:

| Uç | rep | production | Satır |
|---|:-:|:-:|---|
| `GET /health` | herkese açık | herkese açık | [routes.php:25](backend/routes.php#L25) |
| `GET /me` | ✓ | ✓ | [routes.php:26](backend/routes.php#L26) |
| `GET /check` (anlık benzersizlik kontrolü) | ✓ | ✗ | [routes.php:27](backend/routes.php#L27) |
| PO uçları (listele, oluştur, gör, güncelle, sil) | ✓ | ✗ | [routes.php:30-34](backend/routes.php#L30-L34) |
| İş emri uçları (listele, ekle, gör, güncelle, sil) | ✓ | ✗ | [routes.php:37-41](backend/routes.php#L37-L41) |
| `GET /stations` | ✓ | ✓ | [routes.php:44](backend/routes.php#L44) |
| `GET /production/lookup/{barcode}` | ✓ | ✓ | [routes.php:45](backend/routes.php#L45) |
| `POST /production/scan` | ✗ | ✓ | [routes.php:46](backend/routes.php#L46) |

Yetki kontrolü sunucuda yapılır ([Router.php:34](backend/src/Http/Router.php#L34)). Arayüzde bir
butonu gizlemek yetki vermez, kaldırmak da yetki almaz.
**Kanıt:** test bölüm 1'deki 8 kontrol.

---

## 5. Veri modeli

Şema iki migration dosyasından oluşur:
[001_init.sql](backend/database/migrations/001_init.sql) ve [002_stations.sql](backend/database/migrations/002_stations.sql).

```
purchase_orders 1 ──── * work_orders * ──── 0..1 stations
                              │                     │
                              1                     1
                              │                     │
                              * scans * ────────────┘
```

| Tablo | Sütunlar | Önemli kısıtlar |
|---|---|---|
| `purchase_orders` | id, po_number, customer, note, created_at, updated_at | `po_number` UNIQUE + NOCASE ([001:9](backend/database/migrations/001_init.sql#L9)) |
| `work_orders` | id, po_id, ma_code, barcode, description, quantity, status, current_station_id, created_at, updated_at | `ma_code`, `barcode` UNIQUE + NOCASE ([001:20-21](backend/database/migrations/001_init.sql#L20-L21)); `quantity > 0` ([001:23](backend/database/migrations/001_init.sql#L23)); `status` yalnızca `open`, `in_progress`, `done`, `cancelled` olabilir ([001:26](backend/database/migrations/001_init.sql#L26)); PO silinince iş emri de silinir ([001:19](backend/database/migrations/001_init.sql#L19)) |
| `stations` | id, code, name, sort_order, is_final, active | `code` UNIQUE ([002:6](backend/database/migrations/002_stations.sql#L6)); 8 istasyon hazır gelir ([002:13-21](backend/database/migrations/002_stations.sql#L13-L21)) |
| `scans` | id, work_order_id, station_id, scanned_at | İş emri silinince okutma kayıtları da silinir ([001:36](backend/database/migrations/001_init.sql#L36)) |
| `schema_migrations` | version, applied_at | Hangi migration'ın uygulandığını tutar ([Database.php:48](backend/src/Database.php#L48)) |

**Durum (`status`) anlamları:**

| Değer | Arayüzde | Ne zaman olur |
|---|---|---|
| `open` | Bekliyor | İş emri eklendiğinde |
| `in_progress` | **İstasyonun adı** (ör. "Kaynak") | Son istasyon dışında bir istasyonda okutulunca |
| `done` | Tamamlandı | Son istasyonda (Paketleme) okutulunca |
| `cancelled` | İptal | Yalnızca API ile elle (`PUT /work-orders/{id}`) |

"İstasyonun adı" gösterimi [api.js:80](frontend/shared/api.js#L80) içindeki `statusLabel()`
fonksiyonundan gelir. İstasyon adı, iş emri sorgusuna JOIN ile eklenir
([WorkOrderService.php:15-18](backend/src/Services/WorkOrderService.php#L15-L18)).

**Veritabanı ayarları** ([Database.php:30-32](backend/src/Database.php#L30-L32)):
- `foreign_keys = ON`: SQLite'ta bu ayar varsayılan olarak kapalıdır. Açılmazsa bağlı kayıtları silme (CASCADE) çalışmaz.
- `journal_mode = WAL`: Bir cihaz yazarken diğerleri okumaya devam edebilir.
- `busy_timeout = 5000`: Veritabanı meşgulse hemen hata vermek yerine 5 saniyeye kadar bekler.

Zaman damgaları SQLite'ın `datetime('now')` fonksiyonuyla yazılır; bu yüzden **UTC**'dir.

---

## 6. Benzersizlik kuralları

| Değer | Kapsam | Büyük/küçük harf |
|---|---|---|
| PO numarası | Tüm sistem | Duyarsız (`po-1` = `PO-1`) |
| MA kodu | **Tüm sistem**: farklı PO'larda bile aynı MA kodu kullanılamaz | Duyarsız |
| Barkod | **Tüm sistem**: farklı PO'larda bile aynı barkod kullanılamaz | Duyarsız |

### İki katmanlı koruma

**Katman 1: Servis katmanında ön kontrol** ([UniqueGuard.php:34-44](backend/src/Services/UniqueGuard.php#L34-L44))

- Kaydetmeden önce aynı değer var mı diye bakılır.
- Varsa kullanıcıya hangi alanın ve hangi değerin çakıştığı söylenir. Örnek: `409`, `"field":"barcode"`, mesaj `Barkod "869..." zaten kullanılıyor.`
- Güncelleme yapılırken kaydın kendisi hariç tutulur (`id IS NOT :except`). Böylece bir kayıt kendi koduyla çakışmış sayılmaz.
- Kullanıldığı yerler:
  - PO: [PurchaseOrderService.php:73](backend/src/Services/PurchaseOrderService.php#L73), [:98](backend/src/Services/PurchaseOrderService.php#L98)
  - İş emri: [WorkOrderService.php:54-55](backend/src/Services/WorkOrderService.php#L54-L55), [:84-85](backend/src/Services/WorkOrderService.php#L84-L85)

**Katman 2: Veritabanı kısıtı** (`UNIQUE COLLATE NOCASE`, [001_init.sql:9,20,21](backend/database/migrations/001_init.sql#L9))

- İki cihaz aynı anda aynı kodu gönderirse ikisi de ön kontrolü geçebilir. Bu durumda veritabanı ikinci kaydı reddeder.
- Veritabanının hatası, aynı `409` biçimine çevrilir ([UniqueGuard.php:63-75](backend/src/Services/UniqueGuard.php#L63-L75)).
- Bu çeviri, her INSERT/UPDATE işleminin `catch` bloğunda yapılır ([PurchaseOrderService.php:85](backend/src/Services/PurchaseOrderService.php#L85), [WorkOrderService.php:70](backend/src/Services/WorkOrderService.php#L70)).

**Kanıt:**
- Test bölüm 2 (10 kontrol): API üzerinden tekrar eden kayıtlar reddediliyor.
- Test bölüm 7: API atlanıp doğrudan SQL ile tekrar eden kayıt eklenmeye çalışılınca veritabanı `UNIQUE constraint failed` hatasıyla reddediyor. Küçük harfle yazılan MA kodu da çakışıyor (NOCASE).
- **Bozma denemesi:** Projenin bir kopyasında Katman 1 tamamen kapatıldı. Tekrar eden kayıt testlerinin hepsi yine `409` ve doğru `field` ile geçti; Katman 2 tek başına koruyor. Sadece `/check` testi kaldı, çünkü o uç doğrudan Katman 1'i kullanıyor. Ayrıntı: [11.2](#112-testin-gerçekten-hata-yakaladığının-kanıtı).

**Fark edilmesi gereken küçük ayrıntı:** Çakışmayı Katman 2 yakalarsa mesajda değer yer almaz
(`Barkod zaten kullanılıyor.`). Bu yalnızca eşzamanlı yazma gibi nadir durumlarda olur.

---

## 7. Veri doğrulama

| Kural | Kod | Hata |
|---|---|---|
| PO no, MA kodu, barkod: zorunlu, 1–64 karakter, sadece harf, rakam ve `. _ - /` | [Validator.php:12-25](backend/src/Services/Validator.php#L12-L25) | 422 + `field` |
| Baştaki ve sondaki boşluklar silinir; sadece boşluktan oluşan değer "boş" sayılır | [Validator.php:16](backend/src/Services/Validator.php#L16) | 422 |
| Adet: pozitif tam sayı, varsayılan 1 | [Validator.php:42-49](backend/src/Services/Validator.php#L42-L49) | 422 |
| Müşteri en fazla 200, açıklama 500, not 2000 karakter | [Validator.php:29-40](backend/src/Services/Validator.php#L29-L40) | 422 |
| İstek gövdesi geçerli bir JSON nesnesi olmalı | [Request.php:54](backend/src/Http/Request.php#L54) | 400 |
| Okutmada istasyon zorunlu, var olmalı ve aktif olmalı | [ProductionController.php:41](backend/src/Controllers/ProductionController.php#L41), [StationService.php:23-30](backend/src/Services/StationService.php#L23-L30) | 422 |

**SQL injection koruması:**
- Kullanıcıdan gelen her değer sorguya parametre olarak bağlanır (`prepare` + `execute`).
- Sorgu metnine eklenen tablo ve sütun adları kullanıcıdan gelmez; sabit bir listeden gelir ([UniqueGuard.php:23](backend/src/Services/UniqueGuard.php#L23)).
- `->query()` ile çalıştırılan iki sorgunun ikisi de sabit metindir ([Database.php:53](backend/src/Database.php#L53), [StationService.php:19](backend/src/Services/StationService.php#L19)).

**XSS koruması:**
- Arayüz, sunucudan gelen veriyi sayfaya HTML olarak basmadan önce `escapeHtml()` fonksiyonundan geçirir ([rep/app.js:150-154](frontend/rep/app.js#L150-L154)).
- Biri açıklama alanına `<script>` yazsa bile bu kod çalışmaz, düz metin olarak görünür.

**Kanıt:** test bölüm 3 (6 kontrol).

---

## 8. İstasyon ve okutma akışı

### İstasyonlar

[002_stations.sql:13-21](backend/database/migrations/002_stations.sql#L13-L21):

| id | Kod | Ad | Son istasyon mu? |
|---|---|---|---|
| 1 | KESIM | Kesim | |
| 2 | BUKUM | Büküm | |
| 3 | KAYNAK | Kaynak | |
| 4 | TASLAMA | Taşlama | |
| 5 | BOYA | Boya | |
| 6 | MONTAJ | Montaj | |
| 7 | KALITE | Kalite Kontrol | |
| 8 | PAKET | Paketleme | ✓ (`is_final = 1`) |

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

Hepsi [WorkOrderService.php:125-168](backend/src/Services/WorkOrderService.php#L125-L168) içinde:

| Durum | Sonuç | Satır |
|---|---|---|
| Barkod sistemde yok | 404 `BARCODE_NOT_FOUND` | [:45](backend/src/Services/WorkOrderService.php#L45) |
| İş emri iptal edilmiş | 409 `WORK_ORDER_CANCELLED` | [:134](backend/src/Services/WorkOrderService.php#L134) |
| İş emri tamamlanmış | 409 `WORK_ORDER_DONE` | [:137](backend/src/Services/WorkOrderService.php#L137) |
| İş emri zaten bu istasyonda (çift okutmayı önler) | 409 `ALREADY_AT_STATION` | [:140](backend/src/Services/WorkOrderService.php#L140) |
| Başarılı, istasyon son istasyon değil | Durum `in_progress`, iş emri bu istasyona geçer | [:152](backend/src/Services/WorkOrderService.php#L152) |
| Başarılı, istasyon son istasyon (`is_final`) | Durum `done` | [:152](backend/src/Services/WorkOrderService.php#L152) |
| Her başarılı okutma | `scans` tablosuna istasyonuyla birlikte kaydedilir | [:158](backend/src/Services/WorkOrderService.php#L158) |

**Eşzamanlılık:**
- Güncelleme koşulludur: `WHERE id = :id AND status IN ('open','in_progress')` ([:146-147](backend/src/Services/WorkOrderService.php#L146-L147)).
- İki cihaz aynı iş emrini aynı anda Paketleme'de okutursa, ikincisinin güncellemesi hiçbir satırı etkilemez ve `409` alır ([:154-155](backend/src/Services/WorkOrderService.php#L154-L155)).
- Okutma kaydı ve durum güncellemesi tek bir transaction içinde yapılır ([:129](backend/src/Services/WorkOrderService.php#L129)). Biri başarısız olursa ikisi birden geri alınır.

**Elle durum değiştirme:** `PUT /work-orders/{id}` ile `status: "open"` verilirse iş emrinin
istasyon bilgisi de temizlenir ([WorkOrderService.php:93](backend/src/Services/WorkOrderService.php#L93)).

Okutulan barkodda da büyük/küçük harf fark etmez: `b9` ile okutmak `B9`'u bulur.

**Kanıt:** test bölüm 4 (15 kontrol). Okutmaların doğru istasyonlarla kaydedildiği doğrudan
veritabanından kontrol edilir.

---

## 9. Migration (şema güncelleme) sistemi

[Database.php:45-78](backend/src/Database.php#L45-L78):

1. Her veritabanı bağlantısında `database/migrations/*.sql` dosyaları isim sırasıyla taranır.
2. `schema_migrations` tablosunda kaydı olmayan dosyalar uygulanır.
3. Her dosya kendi transaction'ında çalışır. Hata olursa o dosyanın değişiklikleri geri alınır ve uygulama hata verir ([Database.php:65-76](backend/src/Database.php#L65-L76)).
4. Yarım kalmış bir şema oluşmaz.

**Mevcut migration'lar:**

| Dosya | İçerik |
|---|---|
| `001_init.sql` | Temel tablolar. Tüm ifadeler `IF NOT EXISTS` ile yazıldı; bu yüzden migration sistemi gelmeden önce oluşturulmuş veritabanlarında da güvenle çalışır |
| `002_stations.sql` | İstasyonlar tablosu ve 8 istasyon. `work_orders` tablosuna `current_station_id` eklendi, eski `scanned_qty` (okutulan adet sayacı) sütunu kaldırıldı. `scans` tablosunda serbest metin `station` alanı yerine `station_id` geldi |

**Kural:** Uygulanmış bir migration dosyasını **değiştirmeyin**. O değişiklik, dosyanın zaten
uygulandığı veritabanlarına hiçbir zaman ulaşmaz. Yeni bir değişiklik için yeni numaralı bir dosya
ekleyin (ör. `003_xxx.sql`).

`002`, eski verisi olan bir veritabanının kopyası üzerinde denendi: mevcut PO'lar ve iş emirleri
korundu.

**Kanıt:** test bölüm 7, iki migration'ın da kayıtlı olduğunu ve `migrate.php`'nin tekrar
çalıştırılınca hata vermediğini doğrular.

---

## 10. Arayüz (frontend)

Sayfalar ayrıdır ve ana menüden yönlendirilir.

### Ana menü — `/`

- İki kutucuk vardır: **PO Oluşturma** ([index.html:23](frontend/index.html#L23)) ve **İş Emri Okut** ([index.html:37](frontend/index.html#L37)).
- Klavye kısayolları: `1` ve `2` ([menu.js:9-13](frontend/menu.js#L9-L13)).
- Sunucu durumu `/health` ucuyla kontrol edilir ([menu.js:19](frontend/menu.js#L19)). Bu uç anahtar istemediği için ana menü hiçbir anahtar taşımaz.
- Alt sayfalarda sol üstte **← Ana menü** butonu bulunur ([rep/index.html:13](frontend/rep/index.html#L13), [production/index.html:13](frontend/production/index.html#L13)).

### PO Oluşturma — `/rep/` (anahtar: `rep`)

- **PO oluşturma ve arama.** Arama, PO numarası ve müşteri adı içinde yapılır.
- **PO'ya iş emri ekleme:** MA kodu, barkod, adet, açıklama.
- **Anlık benzersizlik kontrolü:** Bir alandan çıkıldığı anda `/check` sorulur ve çakışma varsa alanın altında yazar ([rep/app.js:47-60](frontend/rep/app.js#L47-L60)).
- **Kaydetme hataları** ilgili alanın altına yazılır ([rep/app.js:37-44](frontend/rep/app.js#L37-L44)).
- **İş emri tablosu:** MA kodu, barkod, açıklama, adet ve durum sütunları. Durum sütununda üretimdeyse istasyonun adı görünür ([rep/app.js:154](frontend/rep/app.js#L154)).
- **PO listesinde `tamamlanan/toplam` sayacı** (ör. `1/2`).
- **İş emri ve PO silme.** Silmeden önce onay sorulur.

### İş Emri Okut — `/production/` (anahtar: `production`)

- **İstasyon seçimi** zorunludur. Seçilmeden okutma yapılmaz ([production/app.js:57-62](frontend/production/app.js#L57-L62)).
- Seçilen istasyon o cihazın tarayıcısında (`localStorage`) hatırlanır ([production/app.js:11,21,27](frontend/production/app.js#L11)).
- **Barkod şimdilik elle yazılır;** Enter'a basılır veya **Okut** butonuna tıklanır ([production/index.html:31](frontend/production/index.html#L31)). İleride klavye gibi çalışan bir okuyucu da (barkodu yazıp Enter basan cihazlar) değişiklik gerekmeden çalışır.
- Barkod kutusu sürekli odakta tutulur. İstasyon listesi açıkken bu yapılmaz, yoksa liste kapanırdı ([production/app.js:43-47](frontend/production/app.js#L43-L47)).
- **Sonuç gösterimi:** Yeşil = tamamlandı, sarı = istasyona geçti, kırmızı = hata. Her sonuca farklı tonda bir bip sesi eşlik eder ([production/app.js:113](frontend/production/app.js#L113)).
- **"Son okutmalar"** listesi yalnızca o sayfada tutulur, sunucuya kaydedilmez. Sayfa yenilenince sıfırlanır ([production/app.js:97](frontend/production/app.js#L97)). Kalıcı kayıt sunucudaki `scans` tablosundadır.

### Ortak

- **Tüm sayfalar aynı API istemcisini kullanır** ([shared/api.js](frontend/shared/api.js)). Sunucunun hata kodu, mesajı ve `field` bilgisi `ApiError` nesnesine taşınır ([api.js:4-12](frontend/shared/api.js#L4-L12)).
- **Tema:** Açık ve koyu tema işletim sisteminin ayarını izler ([base.css](frontend/shared/base.css)).

---

## 11. Test etme

### 11.1 Otomatik uçtan uca test

```bash
./scripts/test.sh
```

**Ne yapar** ([scripts/test.sh](scripts/test.sh)):

1. `mktemp` ile **geçici** bir klasörde yeni bir veritabanı oluşturur ve örnek veriyi ekler.
2. API'yi `127.0.0.1:8799` adresinde geçici bir sunucuyla başlatır. Port `TEST_PORT=...` ile değiştirilebilir.
3. Gerçek HTTP istekleri gönderir. Her yanıtın **HTTP kodunu** ve **içeriğini** beklenenle karşılaştırır.
4. Bazı kontrolleri API'yi atlayıp doğrudan SQL ile yapar. Bunlar veritabanı kısıtlarını, okutma kayıtlarını ve bağlı kayıtların silinmesini doğrular.
5. Sunucu logunda PHP hatası (`fatal`, `uncaught`) var mı diye bakar.
6. Bitince sunucuyu durdurur ve geçici klasörü siler.

**Gerçek veritabanına (`backend/storage/app.sqlite`) dokunmaz.** `dev.sh` açıkken de
çalıştırılabilir. Hepsi geçerse çıkış kodu `0`, en az biri kalırsa `1` olur; bu yüzden ileride
CI'da da kullanılabilir.

**Kapsam:**

| Bölüm | Kontrol sayısı | Doğruladığı |
|---|---|---|
| 1. Kimlik ve rol yetkileri | 8 | Anahtarsız/yanlış anahtar 401; yanlış rol 403; `/health` açık |
| 2. Benzersizlik | 10 | PO/MA/barkod tekrarı (büyük/küçük harf ve farklı PO dahil); güncellemede çakışma; `/check` |
| 3. Veri doğrulama | 6 | Boş, geçersiz karakterli, 65 karakterlik değerler; adet 0; bozuk JSON; olmayan PO |
| 4. Okutma ve istasyon akışı | 15 | İstasyonsuz/geçersiz istasyon; bilinmeyen barkod; Kesim → Kaynak → Paketleme; çift okutma; tamamlanmış/iptal; `open`'a alınca istasyon temizlenir; okutma kayıtları |
| 5. Silme | 3 | PO silinince iş emirleri ve okutma kayıtları da silinir |
| 6. Yönlendirici | 2 | Olmayan uç 404, yanlış metod 405 |
| 7. Veritabanı katmanı | 6 | Doğrudan SQL ile UNIQUE/NOCASE/CHECK kısıtları; migration kayıtları; migrate'in tekrar çalıştırılabilmesi |

<details>
<summary>Son çalıştırmanın tam çıktısı (2026-10-03): <b>50 geçti, 0 kaldı</b></summary>

```
1) Kimlik ve rol yetkileri
  ✓ health anahtarsız açık
  ✓ anahtarsız istek reddedilir
  ✓ yanlış anahtar reddedilir
  ✓ üretim anahtarı PO listeleyemez
  ✓ üretim anahtarı PO oluşturamaz
  ✓ temsilci anahtarı okutma yapamaz
  ✓ üretim anahtarı istasyonları görür
  ✓ /me rolü döndürür
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
  ✓ Kesim'de okut → üretimde, Kesim
  ✓ aynı istasyonda tekrar okutma yasak
  ✓ küçük harf barkodla Kaynak'ta okut
  ✓ PO detayında istasyon görünür
  ✓ Paketleme (son) → tamamlandı
  ✓ tamamlanmış iş emri okutulamaz
  ✓ PO listesinde tamamlanan sayısı
  ✓ iş emri iptal edilebilir
  ✓ iptal iş emri okutulamaz
  ✓ open'a alınınca istasyon temizlenir
  ✓ barkod sorgulama (lookup)
  ✓ her okutma istasyonuyla kaydedildi
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

Sonuç: 50 geçti, 0 kaldı
```

</details>

### 11.2 Testin gerçekten hata yakaladığının kanıtı

Her zaman "geçti" diyen bir test hiçbir şey kanıtlamaz. Bu yüzden projenin **bir kopyasında**
kod kasıtlı olarak bozuldu ve test tekrar çalıştırıldı. Asıl kodda bu bozma yapılmadı.

**Yapılan iki bozma:**
1. `routes.php` içinde temsilci rolüne okutma yetkisi verildi.
2. `UniqueGuard::assertAvailable()` boşaltıldı; Katman 1 ön kontrolü devre dışı kaldı.

**Sonuç:** `46 geçti, 4 kaldı`

| Kalan test | Neden kaldı |
|---|---|
| temsilci anahtarı okutma yapamaz | 403 beklenirken 201 geldi: yetki açığı yakalandı |
| /check: dolu barkod | `available:false` beklenirken `true` geldi |
| her okutma istasyonuyla kaydedildi | Temsilcinin yaptığı fazladan okutma kayıtlarda göründü |
| silinen iş emrinin okutmaları da silindi | Aynı fazladan okutma yüzünden |

**Önemli gözlem:** Katman 1 kapalıyken bile tekrar eden kayıt testlerinin hepsi `409` ve doğru
`field` ile **geçti**. Bu, veritabanı kısıtı ve hata çevirisinin (Katman 2) tek başına koruma
sağladığını kanıtlıyor.

### 11.3 Elle API testi (curl)

```bash
# Sağlık
curl http://localhost:8000/api/v1/health

# PO listesi (temsilci)
curl -H 'X-API-Key: dev-rep-key' http://localhost:8000/api/v1/pos

# Yeni PO
curl -X POST -H 'X-API-Key: dev-rep-key' -H 'Content-Type: application/json' \
  -d '{"po_number":"PO-TEST-1","customer":"Deneme"}' http://localhost:8000/api/v1/pos

# Tekrar aynı PO → 409 DUPLICATE
curl -X POST -H 'X-API-Key: dev-rep-key' -H 'Content-Type: application/json' \
  -d '{"po_number":"po-test-1"}' http://localhost:8000/api/v1/pos

# İstasyonlar (üretim)
curl -H 'X-API-Key: dev-production-key' http://localhost:8000/api/v1/stations

# Kaynak istasyonunda (id=3) okut
curl -X POST -H 'X-API-Key: dev-production-key' -H 'Content-Type: application/json' \
  -d '{"barcode":"8690000000011","station_id":3}' http://localhost:8000/api/v1/production/scan
```

> Bu komutlar `dev.sh`'ın kullandığı **gerçek** veritabanına yazar. Deneme verisi bırakmak
> istemiyorsanız `./scripts/test.sh` kullanın.

### 11.4 Arayüzden elle test senaryosu

`./scripts/dev.sh` açıkken:

| # | Adım | Beklenen |
|---|---|---|
| 1 | http://localhost:5173/ adresini aç | Ana menü açılır, sağ üstte "Sunucu bağlı" yazar |
| 2 | `1` tuşuna bas | PO Oluşturma sayfası açılır |
| 3 | PO numarası alanına `po-2026-001` yaz ve alandan çık | Alanın altında "zaten kullanılıyor" yazar |
| 4 | `PO-DENEME` adıyla yeni bir PO oluştur | PO listede görünür ve açılır |
| 5 | MA kodu `MA-0001`, barkod `X1` ile iş emri eklemeyi dene | MA kodu alanının altında hata yazar |
| 6 | MA kodu `MA-D1`, barkod `D1` ile iş emri ekle | Tabloda Durum sütununda "Bekliyor" görünür |
| 7 | ← Ana menü'ye dön, ardından `2` tuşuna bas | İş Emri Okut sayfası açılır |
| 8 | İstasyon seçmeden `D1` yazıp Okut'a tıkla | Kırmızı "İSTASYON SEÇİN" uyarısı çıkar |
| 9 | "Kaynak"ı seç, `D1` yaz, Okut'a tıkla | Sarı ekranda `MA-D1 → Kaynak` yazar |
| 10 | Aynı barkodu tekrar okut | Kırmızı ekranda "zaten Kaynak istasyonunda" yazar |
| 11 | PO Oluşturma sayfasına dön ve PO'yu aç | Durum sütununda "Kaynak" yazar |
| 12 | "Paketleme"yi seç ve `D1`'i okut | Yeşil ekranda "TAMAMLANDI" yazar; PO listesinde sayaç `1/1` olur |
| 13 | Sayfayı yenile | Seçili istasyon hatırlanmış olur; "Son okutmalar" listesi boştur (bu beklenen davranış) |

### 11.5 Testin kapsamadığı şeyler

- **Arayüzün otomatik testi yok.** Arayüz, geliştirme sırasında tarayıcıda yukarıdaki senaryoya benzer adımlarla elle test edildi.
- **Gerçek eşzamanlılık testi yok.** İki cihazın aynı milisaniyede yazması otomatik testte denenmedi. Bu koruma kod incelemesine ([bölüm 8](#8-istasyon-ve-okutma-akışı)) ve Katman 2'nin tek başına çalıştığını gösteren bozma denemesine ([11.2](#112-testin-gerçekten-hata-yakaladığının-kanıtı)) dayanıyor.
- **CORS başlıkları ve Apache `.htaccess` kurulumu test edilmedi.**
- **Yük ve performans testi yapılmadı.**

---

## 12. Yapılandırma

Backend ayarları ortam değişkenleriyle değiştirilebilir ([config.php](backend/config.php)):

| Değişken | Varsayılan | Açıklama |
|---|---|---|
| `BARKOD_DB_PATH` | `backend/storage/app.sqlite` | Veritabanı dosyası |
| `BARKOD_CORS_ORIGINS` | `*` | İzinli origin'ler, virgülle ayrılır |
| `BARKOD_REP_KEY` | `dev-rep-key` | Temsilci anahtarı |
| `BARKOD_PRODUCTION_KEY` | `dev-production-key` | Üretim anahtarı |
| `API_PORT`, `WEB_PORT` | `8000`, `5173` | `dev.sh` portları |

Arayüz ayarları dosyalarda durur:
- Temsilci sayfası: [frontend/rep/config.js](frontend/rep/config.js)
- Üretim sayfası: [frontend/production/config.js](frontend/production/config.js)
- Ana menü: [frontend/menu.js:2](frontend/menu.js#L2)

Backend'de anahtarı değiştirirseniz ilgili `config.js` dosyasını da güncellemeniz gerekir.

### Başka bir cihazdan (tablet vb.) erişim

`dev.sh` sunucuları `0.0.0.0` adresinde dinler, yani ağdaki tüm cihazlar bağlanabilir. Ancak
**arayüz dosyalarında API adresi `http://localhost:8000` olarak yazılı**
([rep/config.js:3](frontend/rep/config.js#L3), [production/config.js:3](frontend/production/config.js#L3), [menu.js:2](frontend/menu.js#L2)).

Bir tablette `localhost`, tabletin kendisi demektir. Bu yüzden başka cihazdan kullanmadan önce bu
üç yerdeki adresi sunucu bilgisayarın IP adresiyle değiştirmek gerekir
(ör. `http://192.168.1.10:8000`).

---

## 13. Bilinen sınırlamalar

Bunlar bilinçli olarak sonraya bırakıldı veya henüz yapılmadı:

| # | Sınırlama | Ayrıntı |
|---|---|---|
| 1 | **Güvenlik geliştirme seviyesinde** | Bkz. [bölüm 14](#14-güvenlik-durumu) |
| 2 | **PO listesinde en fazla 50 PO görünür** | Sayfalama arayüzü yok ([rep/app.js:65](frontend/rep/app.js#L65), [api.js:50](frontend/shared/api.js#L50)). Daha eski PO'lara arama ile ulaşılabilir. API sayfalamayı destekliyor (`limit` en fazla 200, `offset`; [PurchaseOrderController.php:22](backend/src/Controllers/PurchaseOrderController.php#L22)) |
| 3 | **Arayüzde iş emri düzenleme ve iptal yok** | API destekliyor (`PUT /work-orders/{id}`); arayüzde sadece ekleme ve silme var |
| 4 | **Durum geçişleri elle değiştirilirken denetlenmiyor** | `PUT` ile `done` olan bir iş emri tekrar `open` yapılabilir. Sadece değerin geçerli bir durum olduğu kontrol ediliyor ([WorkOrderService.php:103](backend/src/Services/WorkOrderService.php#L103)) |
| 5 | **İstasyon sırası zorunlu değil** | İş emri istasyon atlayabilir veya geri dönebilir (Boya → Kesim). Sadece "aynı istasyonda iki kez" ve "tamamlandıktan sonra" engelleniyor |
| 6 | **İstasyonlar arayüzden yönetilemiyor** | Değiştirmek için yeni bir migration dosyası yazmak gerekiyor (`002`'yi değiştirmeyin). `active` sütunu var ama onu değiştiren bir arayüz yok |
| 7 | **İstasyon geçmişi hiçbir ekranda gösterilmiyor** | Veri `scans` tablosunda kayıtlı, ama bunu okuyan bir API ucu ya da ekran yok |
| 8 | **Silme kalıcıdır** | PO silinince iş emirleri ve okutma geçmişi de geri dönüşsüz silinir; "çöp kutusu" yok |
| 9 | **Kimin yaptığı kaydedilmiyor** | Kullanıcı girişi yok; okutmalarda sadece istasyon tutuluyor |
| 10 | **Zamanlar UTC olarak saklanıyor** | Arayüzde şu an zaman gösterilmiyor; ileride gösterilecekse yerel saate çevrilmeli |
| 11 | **Migration kontrolü her istekte yapılıyor** | Bir dosya taraması ve bir sorgu; küçük bir yük. Yüksek trafikte bir kurulum adımına taşınabilir |
| 12 | **`php -S` sadece geliştirme sunucusu** | Gerçek kullanım için Nginx/Apache + PHP-FPM gerekir ([.htaccess](backend/public/.htaccess) hazır) |

---

## 14. Güvenlik durumu

**Mevcut korumalar** (test bölüm 1, 3 ve 7 ile doğrulandı):
- **API anahtarı ve rol kontrolü** sunucu tarafında yapılır ([Router.php:34](backend/src/Http/Router.php#L34)).
- **Girdi doğrulama** ([bölüm 7](#7-veri-doğrulama)).
- **SQL injection'a karşı parametreli sorgular.**
- **Arayüzde HTML kaçışlama** (XSS'e karşı).
- **500 hatalarında iç ayrıntı sızdırılmaz** ([index.php:58-59](backend/public/index.php#L58-L59)).

**Açıklar.** Bu kurulum ağdaki birinin veri göndermesini **engellemez:**

| Açık | Kanıt |
|---|---|
| **Anahtarlar, ağa açık sunulan JS dosyalarında yazılı.** `http://<ip>:5173/rep/config.js` adresini açan herkes temsilci anahtarını okuyabilir | [rep/config.js:4](frontend/rep/config.js#L4) |
| **Anahtarlar varsayılan değerlerde**; bu README'de ve docs/API.md'de de yazıyor | [config.php:17-18](backend/config.php#L17-L18) |
| **Bağlantı şifresiz (HTTP).** Ağı dinleyen biri anahtarları görebilir | — |
| **Sunucular tüm ağ arayüzlerinde dinliyor** | [dev.sh:13-15](scripts/dev.sh#L13-L15) |
| **CORS herkese açık (`*`).** Not: CORS zaten tarayıcı dışı istemcileri durdurmaz | [config.php:10](backend/config.php#L10) |
| **Deneme sayısı sınırı yok** | — |

**Planlanan çözüm** (gerçek veriyle kullanmadan önce yapılmalı):
- Temsilciler için kullanıcı adı ve şifreyle giriş ve süresi dolan token.
- Üretim cihazları için panelden eşleştirme ve iptal edilebilir cihaz token'ı.
- Deneme sayısı sınırı.
- İşlem kayıtlarında kullanıcı ve cihaz bilgisi.
- HTTPS (ör. Caddy).

O zamana kadar sistemi **sadece güvenilir yerel ağda** kullanın ve internete açmayın.

---

## 15. Geliştirme rehberi

### Yeni bir client (cihaz) eklemek

1. [docs/API.md](docs/API.md) sözleşmesini okuyun. Cevap biçimi `{"data":...}`, hata biçimi `{"error":{"code","message","field"}}`.
2. Gerekirse [config.php](backend/config.php) içindeki `api_keys` listesine yeni bir anahtar ekleyin.
3. Yeni bir rol gerekiyorsa [routes.php](backend/routes.php) içinde, o rolün erişeceği uçlara rolü ekleyin.
4. Barkod okuma cihazı için gereken iki uç:
   - `GET /api/v1/stations`
   - `POST /api/v1/production/scan` gövde: `{"barcode":"...","station_id":N}`

### Yeni bir sayfa eklemek

1. `frontend/<sayfa>/` altında ayrı bir sayfa oluşturun.
2. API'ye [shared/api.js](frontend/shared/api.js) üzerinden bağlanın.
3. Ana menüye ([frontend/index.html](frontend/index.html)) bir kutucuk ekleyin.
4. Sayfaya "← Ana menü" bağlantısı koyun.

### Şemayı değiştirmek

1. `backend/database/migrations/003_aciklama.sql` dosyasını oluşturun.
2. Bir sonraki API isteğinde dosya otomatik uygulanır. Elle kontrol etmek için:

   ```bash
   php backend/bin/migrate.php
   ```

3. `./scripts/test.sh` ile her şeyin hâlâ çalıştığını doğrulayın.

### Yeni bir iş kuralı eklemek

1. Kuralı ilgili servise yazın (`backend/src/Services/`).
2. Hatalarda `HttpException` kullanın ve anlamlı bir `code` verin.
3. `scripts/test.sh` dosyasına, kuralın hem geçen hem reddedilen hâlini deneyen birer `check` satırı ekleyin.
