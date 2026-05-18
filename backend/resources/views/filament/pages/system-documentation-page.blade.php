<x-filament-panels::page>
    <style>
        .doc-wrap {
            display: grid;
            gap: 18px;
        }

        .doc-card {
            padding: 18px;
            border: 1px solid rgba(148, 163, 184, .24);
            border-radius: 18px;
            background: rgba(15, 23, 42, .035);
        }

        .doc-card h2 {
            margin: 0 0 8px;
            font-size: 20px;
            font-weight: 900;
        }

        .doc-card p {
            margin: 0;
            color: rgb(100, 116, 139);
            line-height: 1.55;
        }

        .doc-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 12px;
            margin-top: 14px;
        }

        .doc-step {
            padding: 14px;
            border: 1px solid rgba(59, 130, 246, .2);
            border-radius: 14px;
            background: rgba(239, 246, 255, .78);
        }

        .dark .doc-step {
            background: rgba(30, 41, 59, .72);
        }

        .doc-step b {
            display: block;
            margin-bottom: 6px;
            color: #0284c7;
            font-size: 13px;
        }

        .doc-step span,
        .doc-step li {
            color: rgb(71, 85, 105);
            font-size: 13px;
            line-height: 1.45;
        }

        .dark .doc-step span,
        .dark .doc-step li {
            color: #cbd5e1;
        }

        .doc-step ul {
            margin: 8px 0 0;
            padding-left: 18px;
        }

        .doc-flow {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 12px;
        }

        .doc-pill {
            padding: 8px 11px;
            border-radius: 999px;
            background: #e0f2fe;
            color: #075985;
            font-size: 12px;
            font-weight: 800;
        }

        .doc-menu {
            display: grid;
            gap: 8px;
            margin-top: 10px;
        }

        .doc-menu-row {
            display: grid;
            grid-template-columns: 150px minmax(0, 1fr);
            gap: 10px;
            padding: 10px;
            border-radius: 12px;
            background: rgba(15, 23, 42, .04);
        }

        .dark .doc-menu-row {
            background: rgba(15, 23, 42, .36);
        }

        .doc-menu-row strong {
            color: #0369a1;
            font-size: 13px;
        }

        .doc-menu-row span {
            color: rgb(71, 85, 105);
            font-size: 13px;
            line-height: 1.45;
        }

        .dark .doc-menu-row span {
            color: #cbd5e1;
        }

        @media (max-width: 1100px) {
            .doc-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>

    <div class="doc-wrap">
        <section class="doc-card">
            <h2>Gambaran Umum</h2>
            <p>
                JOJO terdiri dari aplikasi Customer, aplikasi Driver, dashboard Admin, dan backend Laravel.
                Backend menjadi pusat validasi order, harga, area, driver, chat, deposit, CMS, dan integrasi AI parser.
            </p>
            <div class="doc-flow">
                <span class="doc-pill">Customer FE</span>
                <span class="doc-pill">Driver FE</span>
                <span class="doc-pill">Admin FE / Filament BE</span>
                <span class="doc-pill">Laravel API</span>
                <span class="doc-pill">Database & Storage</span>
            </div>
        </section>

        <section class="doc-card">
            <h2>Struktur Menu Sistem</h2>
            <p>Struktur ini menjadi referensi readonly untuk admin, SPV, manager, dan GM saat mengecek fungsi setiap halaman.</p>
            <div class="doc-grid">
                <div class="doc-step">
                    <b>FE Admin - Operations</b>
                    <div class="doc-menu">
                        <div class="doc-menu-row"><strong>Dashboard</strong><span>Ringkasan user, driver, order aktif, order terbaru, dan status sistem.</span></div>
                        <div class="doc-menu-row"><strong>Orders</strong><span>Memantau order terbaru, order aktif, cancel request, dan oper handle.</span></div>
                        <div class="doc-menu-row"><strong>Manual Order</strong><span>Membuat order dari admin saat customer/order masuk dari kanal luar aplikasi.</span></div>
                        <div class="doc-menu-row"><strong>Request Order</strong><span>Melihat request order dari driver yang tetap masuk perhitungan setoran.</span></div>
                    </div>
                </div>
                <div class="doc-step">
                    <b>FE Admin - Management</b>
                    <div class="doc-menu">
                        <div class="doc-menu-row"><strong>Users</strong><span>Filter role, reset token customer, export data role aktif, dan kelola akun.</span></div>
                        <div class="doc-menu-row"><strong>Driver</strong><span>Status driver, kendaraan, layanan yang boleh diterima, bansos, BPJS/JHT, dan reset token.</span></div>
                        <div class="doc-menu-row"><strong>Branches</strong><span>Area layanan, branch, dan referensi lokasi operasional.</span></div>
                    </div>
                </div>
                <div class="doc-step">
                    <b>FE Admin - Area & System</b>
                    <div class="doc-menu">
                        <div class="doc-menu-row"><strong>Geofence</strong><span>Batas wilayah layanan dan validasi driver/customer.</span></div>
                        <div class="doc-menu-row"><strong>Pricing</strong><span>Tarif dasar, keyword charge, service fee, tarif malam, dan kebijakan harga.</span></div>
                        <div class="doc-menu-row"><strong>System Settings</strong><span>Multi order, jam operasional order, pesan popup close order, dan rule tarif malam.</span></div>
                    </div>
                </div>
                <div class="doc-step">
                    <b>Customer App</b>
                    <div class="doc-menu">
                        <div class="doc-menu-row"><strong>Home</strong><span>Hero, slider dari Home CMS, promo, announcement, order cepat, dan shortcut.</span></div>
                        <div class="doc-menu-row"><strong>Chat Order</strong><span>JOJOBOT, form schema, preview order, upload gambar, share lokasi, dan chat CS.</span></div>
                        <div class="doc-menu-row"><strong>History</strong><span>Riwayat per bulan, chat driver saat accepted, rating setelah completed.</span></div>
                    </div>
                </div>
                <div class="doc-step">
                    <b>Driver App</b>
                    <div class="doc-menu">
                        <div class="doc-menu-row"><strong>Home</strong><span>Order aktif terbaru, status driver, performa, dan ringkasan setoran.</span></div>
                        <div class="doc-menu-row"><strong>Order</strong><span>Order tersedia sesuai area, kendaraan, layanan driver, dan status aktif.</span></div>
                        <div class="doc-menu-row"><strong>Chat</strong><span>Chat customer/CS, share lokasi, gambar, kamera, dan attachment panel.</span></div>
                        <div class="doc-menu-row"><strong>Setoran</strong><span>Setoran hingga hari ini, cashback bulan sebelumnya, bansos, BPJS, dan JHT.</span></div>
                    </div>
                </div>
                <div class="doc-step">
                    <b>Backend Filament</b>
                    <div class="doc-menu">
                        <div class="doc-menu-row"><strong>System Settings</strong><span>API key, FCM, OAuth, AI provider, OpenRouter, map, multi order, dan operasional order.</span></div>
                        <div class="doc-menu-row"><strong>Documentation</strong><span>Halaman readonly struktur menu dan flow sistem untuk referensi operasional.</span></div>
                        <div class="doc-menu-row"><strong>Device Tokens</strong><span>Melihat token FCM user tanpa membuka database.</span></div>
                    </div>
                </div>
            </div>
        </section>

        <section class="doc-card">
            <h2>Flow Order Customer</h2>
            <div class="doc-grid">
                <div class="doc-step"><b>1. Input Customer</b><span>Customer memilih layanan/manual form atau chat JOJOBOT.</span></div>
                <div class="doc-step"><b>2. Parser</b><span>Keyword parser dan form schema diproses dulu. Jika belum cukup, AI parser menjadi fallback.</span></div>
                <div class="doc-step"><b>3. Validasi Backend</b><span>Backend cek profile, jam operasional, branch, area, limit order aktif, dan payload layanan.</span></div>
                <div class="doc-step"><b>4. Pricing</b><span>Harga dihitung oleh service Laravel: tarif dasar, service fee, tarif malam, tambahan jasa, dan aturan keyword.</span></div>
                <div class="doc-step"><b>5. Cari Driver</b><span>Sistem mencari driver aktif sesuai area, layanan yang diizinkan, kendaraan, dan arah multi-order.</span></div>
                <div class="doc-step"><b>6. Status Order</b><span>Order masuk searching driver, accepted, on going, completed, cancelled, atau oper handle sesuai aksi.</span></div>
            </div>
        </section>

        <section class="doc-card">
            <h2>Flow AI Parser</h2>
            <p>AI tidak menentukan harga dan tidak membuat order. AI hanya mengubah teks bebas menjadi JSON order yang bisa divalidasi backend.</p>
            <div class="doc-grid">
                <div class="doc-step"><b>Parser Lokal</b><span>Keyword, form schema, smart parser, dan template berjalan lebih dulu untuk menghemat biaya.</span></div>
                <div class="doc-step"><b>AI Parser Memory</b><span>Input yang pernah sukses diparse AI disimpan ke database agar bisa dipakai tanpa API key.</span></div>
                <div class="doc-step"><b>AI Fallback</b><span>Jika perlu, provider aktif dari CMS dipanggil. Konfigurasi terbaru diarahkan ke OpenRouter agar pilihan model lebih fleksibel.</span></div>
                <div class="doc-step"><b>OpenRouter Auto</b><span>Provider rekomendasi: <code>openrouter</code>, model <code>openrouter/auto</code>, base URL <code>https://openrouter.ai/api/v1</code>.</span></div>
                <div class="doc-step"><b>Validasi JSON</b><span>Backend memastikan service_type, alamat pembelian, alamat tujuan, item, dan field wajib tidak tertukar.</span></div>
                <div class="doc-step"><b>Fallback Aman</b><span>Jika AI gagal, sistem kembali ke parser lama atau form manual, bukan membuat order sembarang.</span></div>
            </div>
        </section>

        <section class="doc-card">
            <h2>AI Yang Dipakai Saat Ini</h2>
            <p>Daftar ini membedakan AI parser, AI learning, monitoring, dan pricing agar admin tidak salah membaca fungsi tiap menu.</p>
            <div class="doc-grid">
                <div class="doc-step">
                    <b>JOJOBOT AI Parser</b>
                    <span>Membaca chat order menjadi JSON order. Mendukung OpenRouter dari <code>System Settings &gt; AI Assistant</code>. AI ini tidak menghitung harga.</span>
                </div>
                <div class="doc-step">
                    <b>AI Parser Memory</b>
                    <span>Menyimpan format order yang pernah sukses agar order berikutnya bisa diparse lebih cepat tanpa selalu memanggil API AI.</span>
                </div>
                <div class="doc-step">
                    <b>AI Location Learning</b>
                    <span>Mengumpulkan kandidat lokasi, typo, nama lokal, dan histori order. Data yang di-approve bisa menjadi POI/Alias.</span>
                </div>
                <div class="doc-step">
                    <b>AI Alias Map</b>
                    <span>Membantu mapping input seperti <code>kota</code>, <code>dekat panji</code>, atau typo alamat ke lokasi asli yang punya koordinat.</span>
                </div>
                <div class="doc-step">
                    <b>AI Monitoring</b>
                    <span>Membaca <code>storage/logs/ai.log</code> untuk menampilkan provider, model, success, fallback, failed request, dan model lambat.</span>
                </div>
                <div class="doc-step">
                    <b>Pricing Engine</b>
                    <span>Bukan LLM. Harga dihitung deterministic dari OSRM, Google fallback jika aktif, Master Ring, service fee, dan Pricing Keyword Rules.</span>
                </div>
                <div class="doc-step">
                    <b>OSRM Lokal</b>
                    <span>Sumber utama jarak rute jalan untuk pricing. Ini bukan AI, tetapi routing engine agar harga tidak memakai jarak lurus.</span>
                </div>
                <div class="doc-step">
                    <b>Google Maps Fallback</b>
                    <span>Dipakai hanya jika switch Google aktif dan API key valid. Tujuannya fallback geocode/distance, bukan sumber utama.</span>
                </div>
            </div>
        </section>

        <section class="doc-card">
            <h2>Flow JOJOBOT & Pricing</h2>
            <div class="doc-flow">
                <span class="doc-pill">Customer / Manual Order</span>
                <span class="doc-pill">Parser Lokal</span>
                <span class="doc-pill">AI Parser Memory</span>
                <span class="doc-pill">OpenRouter jika perlu</span>
                <span class="doc-pill">POI & Alias</span>
                <span class="doc-pill">GeoJSON Regions</span>
                <span class="doc-pill">OSRM Distance</span>
                <span class="doc-pill">Master Ring</span>
                <span class="doc-pill">Keyword Rules</span>
                <span class="doc-pill">Breakdown Harga</span>
            </div>
            <p style="margin-top: 12px;">
                GeoJSON hanya membantu mengenali area, branch, coverage, geofence, dan referensi lat/lng.
                Harga tetap mengikuti jarak route, Master Ring, service fee, formula, dan keyword rule yang aktif di CMS.
            </p>
        </section>

        <section class="doc-card">
            <h2>Flow Driver</h2>
            <div class="doc-grid">
                <div class="doc-step"><b>Order Tersedia</b><span>Driver hanya melihat order aktif/terbaru yang sesuai area dan layanan driver.</span></div>
                <div class="doc-step"><b>Terima Order</b><span>Backend cek status driver, area, kendaraan, allowed services, GPS, dan multi-order searah.</span></div>
                <div class="doc-step"><b>Oper Handle</b><span>Driver bisa request oper handle. Admin/SPV memantau dan driver lain dapat mengambil sesuai aturan.</span></div>
                <div class="doc-step"><b>Chat</b><span>Customer, driver, dan CS memakai channel chat untuk konfirmasi, cancel request, serta share lokasi.</span></div>
                <div class="doc-step"><b>Selesai</b><span>Order selesai masuk history, chat driver disembunyikan dari customer dan diganti rating.</span></div>
                <div class="doc-step"><b>Setoran</b><span>Order customer dan request order driver dihitung ke setoran sesuai service fee dan aturan deposit.</span></div>
            </div>
        </section>

        <section class="doc-card">
            <h2>Flow Admin & CMS</h2>
            <div class="doc-grid">
                <div class="doc-step"><b>Home CMS</b><span>Section tipe slider tampil sebagai slide home. Section tipe promo tampil sebagai deretan promo. Announcement menjadi Promo Spesial.</span></div>
                <div class="doc-step"><b>Driver Management</b><span>Admin mengatur status driver, reset token, kendaraan, bansos/JHT/BPJS, serta pilihan layanan driver.</span></div>
                <div class="doc-step"><b>Order Operations</b><span>Admin memantau order terbaru, cancel request, oper handle, live chat, dan request order.</span></div>
                <div class="doc-step"><b>Pricing & Policy</b><span>Tarif dasar, keyword charge, tarif malam, dan jam tutup order diatur dari CMS.</span></div>
                <div class="doc-step"><b>Reports</b><span>Rekap setoran, deposit, order, dan export mengikuti data backend yang sudah divalidasi.</span></div>
                <div class="doc-step"><b>Security</b><span>Token dapat direset, login bisa dibatasi per perangkat, dan role menentukan akses menu.</span></div>
            </div>
        </section>

        <section class="doc-card">
            <h2>Jam Operasional & Tarif Malam</h2>
            <p>Setting ini tampil di FE Admin dan Backend Filament System Settings agar admin bisa mengubah jam tanpa deploy ulang.</p>
            <div class="doc-grid">
                <div class="doc-step"><b>Close Order</b><span>Default order ditutup otomatis pukul 01:00 dan dibuka lagi pukul 05:00. Saat customer order pada jam tutup, aplikasi menampilkan popup pesan dari CMS.</span></div>
                <div class="doc-step"><b>Pesan Popup</b><span>Template mendukung placeholder <code>{start}</code> dan <code>{end}</code>, misalnya: Maaf, sistem order sedang tutup. Order dibuka kembali pukul {end}.</span></div>
                <div class="doc-step"><b>Rule Global</b><span>22:00-00:00 tambah 30%, 00:01-04:00 tambah 50%, dan 04:01-06:00 tambah 30% dari tarif dasar.</span></div>
                <div class="doc-step"><b>Rule Area BWS</b><span>Area BWS/Bondowoso mulai 21:30-00:00 tambah 30%, lalu mengikuti rule global untuk jam berikutnya.</span></div>
                <div class="doc-step"><b>Urutan Harga</b><span>Backend menghitung tarif dasar dulu, lalu menerapkan tarif malam, service fee, tambahan jasa, dan total order.</span></div>
                <div class="doc-step"><b>Prioritas Rule</b><span>Rule area spesifik diproses lebih dulu. Rule area kosong berlaku untuk semua branch.</span></div>
            </div>
        </section>

        <section class="doc-card">
            <h2>Checklist Operasional</h2>
            <div class="doc-grid">
                <div class="doc-step">
                    <b>AI Hemat</b>
                    <ul>
                        <li>Aktifkan AI parser hanya sebagai fallback.</li>
                        <li>Pilih provider OpenRouter.</li>
                        <li>Pilih model <code>openrouter/auto</code> agar OpenRouter memilih model yang tersedia.</li>
                        <li>Batasi max token 500-700 untuk parser.</li>
                    </ul>
                </div>
                <div class="doc-step">
                    <b>Image CMS</b>
                    <ul>
                        <li>Gunakan gambar 16:9 untuk slider.</li>
                        <li>Isi tanggal tampil/sampai pada Home Item.</li>
                        <li>Item expired akan dihapus scheduler.</li>
                    </ul>
                </div>
                <div class="doc-step">
                    <b>Driver</b>
                    <ul>
                        <li>Pastikan branch dan area driver benar.</li>
                        <li>Checklist layanan driver sesuai kendaraan.</li>
                        <li>Reset token jika ada sesi nyangkut.</li>
                    </ul>
                </div>
            </div>
        </section>

        <section class="doc-card">
            <h2>Dokumentasi Readonly Update</h2>
            <p>
                Setiap flow baru sebaiknya dicatat di halaman Documentation ini bersamaan dengan perubahan fitur.
                Halaman ini readonly untuk operator, sehingga struktur menu dan fungsi sistem bisa dibaca tanpa risiko mengubah data produksi.
            </p>
            <div class="doc-flow">
                <span class="doc-pill">Update fitur</span>
                <span class="doc-pill">Tambahkan catatan flow</span>
                <span class="doc-pill">Operator membaca readonly</span>
                <span class="doc-pill">Tidak perlu buka kode/database</span>
            </div>
        </section>
    </div>
</x-filament-panels::page>
