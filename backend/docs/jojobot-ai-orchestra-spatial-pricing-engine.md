# Jojobot AI Pricing Flow

Dokumen ini menjelaskan flow pricing yang dipakai setelah GeoJSON dipisahkan dari Master Ring.

Dokumentasi admin utama ada di:

- `Backend Filament > Dokumentasi > Flow Sistem`
- file: `backend/docs/dokumentasi-flow-sistem-jojo.md`

## Status Saat Ini

Pricing aktif tetap memakai engine Laravel yang sudah ada:

- endpoint customer lama: `POST /api/pricing/calculate`
- kontrol harga: `Master Ring`
- data spatial: `Master Data GeoJSON`
- keyword charge: `Pricing Keyword Rules`

GeoJSON tidak di-upload dari Master Ring lagi.

## AI Yang Dipakai Saat Ini

### AI Order Parser / JOJOBOT Parser

AI parser sudah mendukung OpenRouter dan konfigurasi terbaru mengarahkan parser ke:

- provider: `openrouter`
- model: `openrouter/auto`
- base URL: `https://openrouter.ai/api/v1`
- konfigurasi CMS: `System Settings > AI Assistant`

AI parser tidak menghitung harga. AI parser hanya membaca teks order bebas menjadi data terstruktur, misalnya nama customer, nomor HP, pickup, tujuan, layanan, catatan, dan keyword tambahan.

Jika OpenRouter API key belum aktif, JOJOBOT tetap memakai parser lokal, rule yang sudah pernah dipelajari, POI/Alias, GeoJSON, dan validasi backend.

### AI Location Learning

AI Location Learning dipakai untuk menyimpan kandidat lokasi dari order manual, typo customer, nama lokal, dan histori order. Data yang sudah di-approve dapat menjadi POI/Alias agar order berikutnya lebih mudah terbaca.

### AI Monitoring

AI Monitoring membaca `storage/logs/ai.log` untuk menampilkan provider aktif, model, success, fallback, failed request, dan indikasi model lambat. Halaman ini tidak menghitung harga.

### Hermes Assistant

Hermes adalah asisten analisa internal untuk monitoring error dan laporan teknis. Hermes terpisah dari JOJOBOT order parser.

### Pricing Engine

Pricing engine bukan LLM. Pricing tetap deterministic dari Laravel:

- route distance OSRM lokal
- Google Maps fallback jika diaktifkan dan API key valid
- titik nol pricing cabang/area dari CMS
- Master Ring aktif di CMS
- Pricing Keyword Rules
- service fee dan formula dari CMS

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
5. Sistem menentukan titik acuan jarak:
   - jika pickup masih berada di cabang/area pricing yang sama, jarak dihitung dari `Titik Nol Pricing` cabang/area ke destination.
   - jika pickup terdeteksi dari luar cabang/area pricing, jarak dihitung dari pickup asli ke destination.
6. Sistem menghitung route distance dengan OSRM lokal, lalu Google Distance jika diaktifkan.
7. Sistem membaca `Master Ring` berdasarkan jarak dan range KM yang aktif di CMS.
8. Sistem menjalankan formula harga dari Master Ring.
9. Sistem menjalankan keyword charge.
10. Sistem mengembalikan harga ke customer.

## Titik Nol Pricing

Titik nol pricing diisi dari CMS:

- `Backend Filament > Location > Branches > Edit > Titik Nol Pricing`
- jika `Lat/Lng titik nol` kosong, sistem fallback ke lat/lng cabang lama.

Contoh titik nol:

- STBKT: Alun-alun Situbondo
- STBBSK: Alun-alun Besuki
- STBASB: Taman Kota Asembagus
- Paiton: Pasar Paiton
- Kraksaan: Alun-alun Kraksaan
- Bondowoso: Alun-alun Bondowoso
- Banyuwangi: Alun-alun Banyuwangi
- Genteng: Pasar Genteng
- Rogojampi: Pasar Rogojampi
- Muncar: RTH Blambangan

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
