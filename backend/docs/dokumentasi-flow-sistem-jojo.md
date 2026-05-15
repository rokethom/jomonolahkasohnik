# Dokumentasi Flow Sistem JOJO

Dokumen ini adalah referensi admin untuk membaca alur JOJO, JOJOBOT, AI parser, pricing, GeoJSON, OSRM, dan monitoring.

## Ringkasan Sistem

JOJO terdiri dari:

- Customer FE
- Driver FE
- Admin FE
- Backend Laravel API
- Backend Filament CMS
- Database dan storage
- OSRM lokal untuk jarak rute jalan
- AI parser untuk membaca teks order bebas

Backend Laravel tetap menjadi sumber kebenaran untuk validasi order, pricing, branch, area, role, driver, dan report.

## AI Yang Digunakan Saat Ini

### 1. AI Order Parser / JOJOBOT Parser

Fungsi:

- membaca chat order customer
- mengambil nama customer
- mengambil nomor HP
- membaca alamat pickup
- membaca alamat tujuan
- membaca layanan
- membaca catatan dan keyword
- mengubah teks bebas menjadi JSON order yang bisa divalidasi backend

Konfigurasi aktif yang direkomendasikan:

- provider: `openrouter`
- model: `openrouter/auto`
- base URL: `https://openrouter.ai/api/v1`
- menu CMS: `System Settings > AI Assistant`

Catatan penting:

- AI parser tidak menentukan harga.
- AI parser tidak membuat order langsung tanpa validasi backend.
- Jika AI gagal, sistem kembali ke parser lokal, parser rule, POI/Alias, dan validasi manual.

### 2. AI Parser Memory

Fungsi:

- menyimpan pola order yang pernah sukses diparse AI
- memakai ulang hasil parser tanpa memanggil API AI lagi
- menghemat biaya OpenRouter
- mempercepat respons JOJOBOT

Menu terkait:

- `AI Parser Rules`
- `AI Monitoring`

### 3. AI Location Learning

Fungsi:

- menyimpan kandidat lokasi dari order manual
- membaca typo umum customer
- membaca nama lokal
- membaca histori order
- membantu membuat POI/Alias baru

Admin dapat approve suggestion agar lokasi menjadi data yang dipakai sistem.

Menu terkait:

- `AI Location Suggestions`
- `Master Location / POI Alias`
- `AI Alias Map`

### 4. AI Alias Map

Fungsi:

- membaca nama singkatan
- membaca typo
- membaca nama populer
- membaca nama historis
- memetakan input customer ke lokasi asli

Contoh:

- `kota` dapat diarahkan ke `Situbondo Kota`
- `mimbaan barat` dapat diarahkan ke `Kelurahan Mimbaan`
- `dekat panji` dapat diarahkan ke area Panji yang sesuai data POI/Alias

### 5. AI Monitoring

Fungsi:

- menampilkan provider AI aktif
- menampilkan model AI aktif
- menampilkan success, fallback, failed request
- membaca log `storage/logs/ai.log`
- membantu admin melihat apakah AI parser sedang sehat

AI Monitoring tidak menghitung harga.

### 6. Hermes Assistant

Fungsi:

- analisa error teknis
- analisa failed job
- laporan monitoring internal
- bantuan investigasi sistem

Hermes terpisah dari JOJOBOT parser.

### 7. Pricing Engine

Pricing Engine bukan LLM dan bukan AI generatif.

Pricing dihitung deterministic oleh Laravel dari:

- OSRM lokal untuk route distance
- Google Maps fallback jika API key aktif
- Master Ring aktif di CMS
- Pricing Keyword Rules
- service fee
- formula harga
- cross ring atau single ring jika rule aktif

## Flow Order JOJOBOT

1. Customer mengirim order dari chat customer atau admin membuat manual order.
2. Parser lokal membaca format order yang umum.
3. AI Parser Memory dicek untuk melihat apakah format pernah sukses sebelumnya.
4. Jika belum terbaca dan AI aktif, OpenRouter dipanggil.
5. Hasil AI parser divalidasi oleh backend.
6. Pickup dan tujuan dicocokkan ke POI/Alias/GeoJSON Regions.
7. Sistem mengambil koordinat pickup dan tujuan.
8. Sistem menghitung jarak rute jalan memakai OSRM lokal.
9. Jika OSRM gagal dan Google aktif, sistem memakai Google Maps fallback.
10. Pricing membaca Master Ring aktif berdasarkan jarak route.
11. Pricing menjalankan formula dari Master Ring.
12. Pricing menjalankan Pricing Keyword Rules, termasuk amount negatif.
13. Sistem menampilkan breakdown harga ke customer/admin.
14. Order baru dibuat hanya setelah data valid.

## Flow Pricing

Sumber harga:

- `Master Ring`
- `Pricing Keyword Rules`
- `Service Fee`
- `OSRM route distance`
- `Google fallback jika aktif`

GeoJSON bukan sumber harga.

GeoJSON hanya dipakai untuk:

- area detection
- branch detection
- coverage
- geofence
- referensi lat/lng
- membantu AI geocode memahami lokasi

## Flow Jarak

Prioritas jarak:

1. OSRM lokal
2. Google Maps jika switch aktif dan API key valid
3. fallback internal hanya jika kedua provider gagal

Catatan:

- Harga tidak memakai jarak lurus sebagai sumber utama.
- Target utama adalah route distance seperti Google Maps.
- Jika hasil beda jauh, cek koordinat pickup/tujuan, POI/Alias, dan region GeoJSON.

## Flow Data Lokasi

Sistem membaca lokasi dari beberapa sumber:

- Master Location / POI Alias
- AI Alias Map
- GeoJSON Regions
- AI Location Suggestions yang sudah di-approve
- histori order yang sudah dipelajari

Urutan ini dibuat agar customer bisa menulis alamat bebas, tetapi sistem tetap punya koordinat yang konsisten.

## Menu Admin Yang Berkaitan

- `System Settings > AI Assistant`: provider AI, model, base URL, OpenRouter API key.
- `AI Monitoring`: status AI parser, log, fallback, dan kesehatan model.
- `AI Location Suggestions`: kandidat lokasi dari order manual/customer.
- `Master Location / POI Alias`: master lokasi dan alias yang dipakai parser.
- `AI Alias Map`: typo, nama lokal, dan sinonim lokasi.
- `Master Data GeoJSON > GeoJSON Regions`: region, coverage, area, dan geofence.
- `Pricing Management > Master Ring`: kontrol range jarak, formula, harga jasa, service fee.
- `Pricing Keyword Rules`: tambahan atau pengurangan harga berbasis keyword.

## Aturan Penting

- AI parser boleh memakai OpenRouter.
- Pricing tidak boleh ditentukan langsung oleh AI generatif.
- Pricing tidak boleh ditentukan langsung oleh polygon.
- Master Ring adalah sumber kontrol harga.
- GeoJSON adalah sumber spatial intelligence.
- OSRM adalah sumber utama jarak rute jalan.
- Google Maps hanya fallback jika API key aktif.
