# System Control Center - SaaS Activation Flow

Dokumen ini adalah rancangan konsep. Belum ada perubahan kode aplikasi.

## Tujuan

System Control Center dapat diberi field `Activation API Key` atau `License Key` agar instalasi JojoApp hanya berjalan jika lisensi aktif.

Model ini mirip Envato/license SaaS:

- pemilik sistem membuat kode aktivasi dari web pusat
- admin memasukkan kode aktivasi di backend JojoApp
- aplikasi memvalidasi kode ke server lisensi
- jika valid, aplikasi berjalan normal
- jika tidak valid/expired, fitur penting bisa dibatasi

## Komponen

### 1. JojoApp Client

Ini adalah aplikasi yang terpasang di VPS customer/cabang.

Tugas:

- menyimpan activation key
- mengirim key ke license server
- menyimpan status lisensi terakhir
- memblokir fitur tertentu jika lisensi tidak valid

### 2. License Server / SaaS Portal

Ini web pusat yang nanti dibuat terpisah oleh pemilik aplikasi.

Tugas:

- membuat activation key
- mengatur paket/masa aktif
- mengikat lisensi ke domain atau VPS
- menerima request validasi dari JojoApp
- mengembalikan status lisensi

### 3. System Control Center

Menu khusus admin/superadmin di Filament.

Field yang disarankan:

- Activation Key
- License Server URL
- Status Lisensi
- Masa Aktif
- Domain Terdaftar
- Last Check
- Grace Period
- Tombol Check License
- Tombol Deactivate

## Flow Aktivasi Pertama

1. Admin membuka `System Control Center`.
2. Admin memasukkan `Activation Key`.
3. JojoApp mengirim request ke License Server.
4. License Server memeriksa:
   - key valid atau tidak
   - key belum expired
   - domain cocok
   - IP/server fingerprint cocok jika dipakai
   - paket fitur yang aktif
5. License Server mengembalikan response.
6. JojoApp menyimpan status lisensi lokal.
7. Jika valid, aplikasi berjalan normal.
8. Jika tidak valid, aplikasi masuk mode terbatas.

## Contoh Request Validasi

```json
{
  "activation_key": "JOJO-XXXX-XXXX-XXXX",
  "domain": "aplikasijoker.my.id",
  "server_ip": "103.253.244.230",
  "app_version": "1.0.0",
  "installation_id": "uuid-lokal"
}
```

## Contoh Response Valid

```json
{
  "valid": true,
  "license_status": "active",
  "plan": "enterprise",
  "expires_at": "2026-12-31",
  "features": [
    "jojobot",
    "dispatch",
    "pricing",
    "driver_management",
    "reports"
  ],
  "grace_until": "2027-01-07"
}
```

## Contoh Response Tidak Valid

```json
{
  "valid": false,
  "license_status": "expired",
  "message": "License expired",
  "grace_until": null
}
```

## Flow Validasi Berkala

Validasi sebaiknya tidak dilakukan setiap request karena akan memperlambat aplikasi.

Rekomendasi:

- cache status lisensi selama 6-24 jam
- jalankan scheduled command setiap hari
- lakukan validasi manual dari tombol `Check License`
- gunakan grace period jika server lisensi tidak bisa dihubungi

Flow:

1. Scheduler menjalankan `license:check`.
2. JojoApp menghubungi License Server.
3. Jika valid, update status lokal.
4. Jika gagal koneksi, gunakan status terakhir.
5. Jika melewati grace period, aktifkan mode terbatas.

## Grace Period

Grace period penting agar aplikasi tidak langsung mati jika:

- internet VPS bermasalah
- License Server maintenance
- DNS Cloudflare error
- timeout koneksi

Contoh:

- lisensi expired tanggal 31 Mei
- grace period 7 hari
- aplikasi masih berjalan sampai 7 Juni
- setelah itu fitur dikunci

## Mode Terbatas

Jika lisensi tidak valid, jangan langsung merusak data. Lebih aman membatasi fitur.

Yang tetap boleh jalan:

- login admin
- lihat data
- export data
- backup database
- halaman aktivasi

Yang bisa diblokir:

- membuat order baru
- dispatch driver
- JOJOBOT AI parser
- pricing engine
- tambah driver/customer
- generate report baru

## Penyimpanan Lokal

Data lisensi bisa disimpan di table `system_settings` atau table khusus `license_activations`.

Field minimum:

- activation_key terenkripsi
- license_status
- plan
- expires_at
- grace_until
- last_checked_at
- last_response
- installation_id

Activation key jangan disimpan plain text jika memungkinkan.

## Keamanan

Rekomendasi keamanan:

- gunakan HTTPS wajib
- simpan activation key terenkripsi
- response license server ditandatangani HMAC
- jangan percaya response tanpa signature
- batasi rate request validasi
- log semua aktivasi/deaktivasi
- ikat lisensi ke domain dan installation ID

## Anti Bypass

Tidak ada sistem lisensi yang 100% tidak bisa dibypass jika kode aplikasi ada di server customer.

Yang bisa dilakukan:

- validasi berkala ke server pusat
- signed response dari license server
- cache status terenkripsi
- fitur penting cek `LicenseGuard`
- audit log jika lisensi berubah
- deploy update hanya dari CI/CD resmi

## Flow Runtime Aplikasi

1. User membuka aplikasi.
2. Middleware membaca status lisensi lokal.
3. Jika valid, request lanjut.
4. Jika expired tapi masih grace, request lanjut dengan warning admin.
5. Jika expired dan grace habis:
   - API order ditolak
   - JOJOBOT tidak memproses order
   - dispatch tidak berjalan
   - admin diarahkan ke halaman aktivasi

## Rekomendasi Implementasi Laravel

Jika nanti diimplementasikan, struktur yang rapi:

```text
app/
├── Services/
│   └── Licensing/
│       ├── LicenseClient.php
│       ├── LicenseGuard.php
│       └── LicenseStatusService.php
├── Console/
│   └── Commands/
│       └── CheckLicenseCommand.php
├── Http/
│   └── Middleware/
│       └── EnsureLicenseIsActive.php
└── Filament/
    └── Pages/
        └── SystemControlCenterPage.php
```

## Kesimpulan

Konsep ini bisa diterapkan.

Model paling aman untuk JojoApp:

- field activation key di System Control Center
- License Server terpisah
- validasi berkala, bukan setiap request
- grace period supaya aplikasi tidak mudah mati
- mode terbatas, bukan hapus data
- semua status dan perubahan dicatat di audit log

