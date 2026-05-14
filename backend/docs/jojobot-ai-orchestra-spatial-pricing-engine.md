# Jojobot AI Pricing Flow

Dokumen ini menjelaskan flow pricing yang dipakai setelah GeoJSON dipisahkan dari Master Ring.

## Status Saat Ini

Pricing aktif tetap memakai engine Laravel yang sudah ada:

- endpoint customer lama: `POST /api/pricing/calculate`
- kontrol harga: `Master Ring`
- data spatial: `Master Data GeoJSON`
- keyword charge: `Pricing Keyword Rules`

GeoJSON tidak di-upload dari Master Ring lagi.

## Peran Setiap Menu

### Master Ring

Master Ring adalah kontrol harga.

Di Master Ring admin mengatur:

- ring
- min KM
- max KM
- mode harga
- harga jasa
- service fee
- rate per KM
- pengurang formula
- priority
- aktif/nonaktif

Master Ring tidak menyimpan polygon dan tidak menerima upload GeoJSON.

### Master Data GeoJSON

GeoJSON ditempatkan di menu terpisah:

- `Master Data GeoJSON > GeoJSON Regions`
- `Master Data GeoJSON > Area Layanan`

GeoJSON dipakai untuk membaca:

- cabang
- area
- coverage
- lat/lng polygon
- region detection
- geofence

GeoJSON bukan sumber harga.

## Flow Pricing

1. User membuat order.
2. AI Parser membaca alamat, koordinat, layanan, dan keyword.
3. AI Pricing menerima pickup lat/lng dan destination lat/lng.
4. AI Pricing membaca `Master Data GeoJSON` untuk mencocokkan destination ke area/cabang.
5. Sistem menghitung jarak pickup ke destination dengan route distance OSRM, lalu Google Distance jika diaktifkan.
6. Sistem membaca `Master Ring` berdasarkan jarak dan range KM yang aktif di CMS.
7. Sistem menjalankan formula harga dari Master Ring.
8. Sistem menjalankan keyword charge.
9. Sistem mengembalikan harga ke customer.

## Contoh

User order dari Patokan ke Wonokoyo.

Jika hasil route distance `5 KM` dan Master Ring aktif memakai range `0-5 KM`:

- Ring 1 cocok karena range aktif di CMS mencakup `5 KM`
- harga jasa `6000`
- service fee `1000`
- total `7000`

Jika hasil route distance masuk range Ring 3:

- Ring 3 cocok
- formula mengikuti konfigurasi Master Ring, contoh default: `(jarak * 1900) - 7000`

Contoh `12 KM`:

```text
(12 * 1900) - 7000 = 15800
```

## Catatan Penting

- Master Ring adalah master kontrol harga.
- GeoJSON adalah master data spatial.
- Route distance OSRM/Google adalah sumber jarak pricing.
- Polygon tidak boleh menentukan harga secara langsung.
- GeoJSON hanya membantu sistem mengenali cabang dan area dari lat/lng.
