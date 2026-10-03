# API Sözleşmesi (v1)

Yeni bir client (mobil uygulama, el terminali, masaüstü vb.) yazarken tek referans bu dosyadır.
Backend, hangi client'ın konuştuğunu bilmez; yalnızca bu sözleşmeye uyar.

## Genel

- Temel adres: `http://<sunucu>:8000/api/v1`
- Gövde ve yanıtlar: `application/json; charset=utf-8`
- Kimlik: her istekte `X-API-Key` başlığı. Anahtar, client'ın **rolünü** belirler.

| Rol          | Ne yapabilir                                        | Geliştirme anahtarı   |
|--------------|-----------------------------------------------------|-----------------------|
| `rep`        | PO ve iş emri oluşturma/düzenleme/silme, sorgulama  | `dev-rep-key`         |
| `production` | Sadece barkod sorgulama ve okutma                   | `dev-production-key`  |

Anahtarlar `backend/config.php` içinde; üretimde `BARKOD_REP_KEY` / `BARKOD_PRODUCTION_KEY`
ortam değişkenleriyle değiştirilmelidir.

### Başarılı yanıt

```json
{ "data": { ... }, "meta": { ... } }
```

`meta` sadece listelerde gelir. `DELETE` işlemleri `204 No Content` döner.

### Hata yanıtı

```json
{ "error": { "code": "DUPLICATE", "message": "Barkod \"869...\" zaten kullanılıyor.", "field": "barcode" } }
```

| HTTP | code                                   | Anlamı                                  |
|------|----------------------------------------|-----------------------------------------|
| 400  | `INVALID_JSON`                         | Gövde çözümlenemedi                     |
| 401  | `UNAUTHORIZED`, `INVALID_API_KEY`      | Anahtar yok / geçersiz                  |
| 403  | `FORBIDDEN`                            | Rolün bu uca yetkisi yok                |
| 404  | `NOT_FOUND`, `BARCODE_NOT_FOUND`, `ROUTE_NOT_FOUND` | Kayıt / uç yok             |
| 409  | `DUPLICATE`                            | Benzersizlik ihlali (`field` alanına bakın) |
| 409  | `WORK_ORDER_DONE`, `WORK_ORDER_CANCELLED`, `ALREADY_AT_STATION` | Okutulamaz durumda iş emri |
| 422  | `VALIDATION_ERROR`                     | Geçersiz alan (`field` alanına bakın)   |

## Benzersizlik kuralları

- `po_number` — tüm sistemde benzersiz
- `ma_code` — tüm iş emirleri arasında benzersiz
- `barcode` — tüm iş emirleri arasında benzersiz

Karşılaştırma büyük/küçük harf duyarsızdır (`ma-1` = `MA-1`). Kodlar en fazla 64 karakter;
harf, rakam ve `. _ - /` içerebilir. Kurallar veritabanında `UNIQUE` kısıtı ile de korunur,
bu yüzden iki cihaz aynı anda aynı kodu girse bile biri `409 DUPLICATE` alır.

## Uçlar

### Sistem

| Metod | Yol | Rol | Açıklama |
|---|---|---|---|
| GET | `/health` | herkes | Sunucu ayakta mı |
| GET | `/me` | rep, production | Anahtarın rolü |
| GET | `/check?field=barcode&value=X[&except_id=5]` | rep | `{available: bool}` — form doldururken anlık kontrol |

### PO (rep)

| Metod | Yol | Gövde |
|---|---|---|
| GET | `/pos?search=&limit=50&offset=0` | — |
| POST | `/pos` | `{ po_number*, customer, note }` |
| GET | `/pos/{id}` | — (yanıtta `work_orders` dizisi de gelir) |
| PUT | `/pos/{id}` | Değişecek alanlar |
| DELETE | `/pos/{id}` | — (iş emirleri de silinir) |

### İş emri (rep)

| Metod | Yol | Gövde |
|---|---|---|
| GET | `/pos/{poId}/work-orders` | — |
| POST | `/pos/{poId}/work-orders` | `{ ma_code*, barcode*, quantity (1), description }` |
| GET | `/work-orders/{id}` | — |
| PUT | `/work-orders/{id}` | Değişecek alanlar; `status`: `open` `in_progress` `done` `cancelled` (`open` yapılırsa istasyon temizlenir) |
| DELETE | `/work-orders/{id}` | — |

İş emri nesnesi:

```json
{
  "id": 1, "po_id": 1, "po_number": "PO-2026-001", "customer": "…",
  "ma_code": "MA-0001", "barcode": "8690000000011", "description": "Gövde",
  "quantity": 3, "status": "in_progress",
  "current_station_id": 3, "current_station_name": "Kaynak",
  "created_at": "2026-10-01 10:00:00", "updated_at": "2026-10-01 10:05:00"
}
```

### Üretim

| Metod | Yol | Rol | Gövde |
|---|---|---|---|
| GET | `/stations` | production, rep | — (aktif istasyonlar, üretim sırasına göre) |
| GET | `/production/lookup/{barcode}` | production, rep | — (iş emri nesnesi) |
| POST | `/production/scan` | production | `{ barcode*, station_id* }` |

İstasyon nesnesi: `{ "id": 3, "code": "KAYNAK", "name": "Kaynak", "sort_order": 30, "is_final": false }`

Okutma davranışı:
- İş emri okutulan istasyona taşınır (`current_station_id`), durum `in_progress` olur.
  Client'lar `in_progress` durumunda durum yerine `current_station_name` gösterir.
- `is_final` istasyonda (Paketleme) okutulursa durum `done` olur.
- Her okutma istasyonuyla birlikte `scans` tablosuna kaydedilir (geçmiş).
- Hatalar: zaten o istasyonda → `409 ALREADY_AT_STATION`; tamamlanmış → `409 WORK_ORDER_DONE`;
  iptal → `409 WORK_ORDER_CANCELLED`; istasyon yok/geçersiz → `422` (`field: station_id`).

Yanıt: `{ "data": { "scan_id": 12, "work_order": { ... } } }`

## Örnek (curl)

```bash
curl -X POST http://localhost:8000/api/v1/production/scan \
  -H 'X-API-Key: dev-production-key' -H 'Content-Type: application/json' \
  -d '{"barcode":"8690000000011","station_id":3}'
```
