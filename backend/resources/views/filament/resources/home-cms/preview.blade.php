@php
    use App\Services\CmsHomeService;
    use Illuminate\Support\Str;

    $homePayload = app(CmsHomeService::class)->payload();
    $sections = collect($homePayload['sections'] ?? []);
    $announcements = collect($homePayload['announcements'] ?? [])->take(2);

    $sliderSection = $sections->first(fn ($section) => data_get($section, 'type') === 'slider' || Str::contains(Str::lower(data_get($section, 'name', '')), 'slider'));
    $promoSection = $sections->first(fn ($section) => data_get($section, 'type') === 'promo' || Str::contains(Str::lower(data_get($section, 'name', '')), 'promo'));

    $usingFallback = $sections->every(fn ($section) => collect(data_get($section, 'items', []))->isEmpty())
        && $announcements->isEmpty();

    $fallbackSliderItems = collect([
        ['title' => 'Belanja', 'subtitle' => 'Titip beli makanan, obat, atau kebutuhan harian'],
        ['title' => 'Delivery', 'subtitle' => 'Antar barang cepat dalam area layanan'],
        ['title' => 'Ojek', 'subtitle' => 'Jemput dan antar penumpang'],
        ['title' => 'Kurir', 'subtitle' => 'Kirim paket kecil dan dokumen'],
    ]);
    $fallbackPopularItems = collect([
        ['title' => 'Joker Mobil', 'subtitle' => 'Layanan mobil untuk perjalanan nyaman'],
        ['title' => 'Gift Order', 'subtitle' => 'Kirim hadiah untuk keluarga atau teman'],
        ['title' => 'Mie Gacoan', 'subtitle' => 'Contoh shortcut promo tenant'],
        ['title' => 'Bantuan CS', 'subtitle' => 'Hubungi admin saat butuh bantuan'],
    ]);
    $fallbackAnnouncements = collect([
        ['title' => 'Promo spesial area Situbondo', 'content' => 'Contoh announcement aktif. Ganti dengan pengumuman asli dari CMS.'],
        ['title' => 'Order malam mengikuti tarif area', 'content' => 'Contoh info operasional untuk customer.'],
    ]);

    $sliderItems = $sliderSection && collect(data_get($sliderSection, 'items', []))->isNotEmpty()
        ? collect(data_get($sliderSection, 'items', []))
        : $fallbackSliderItems;
    $promoItems = $promoSection && collect(data_get($promoSection, 'items', []))->isNotEmpty()
        ? collect(data_get($promoSection, 'items', []))
        : $fallbackPopularItems;
    $previewAnnouncements = $announcements->isNotEmpty() ? $announcements : $fallbackAnnouncements;
    $specialAnnouncement = $previewAnnouncements->first();
@endphp

<style>
    .jojo-home-preview-card {
        overflow: hidden;
        border: 1px solid rgba(148, 163, 184, .22);
        border-radius: 18px;
        background: #f8fafc;
        box-shadow: 0 24px 70px rgba(15, 23, 42, .16);
    }

    .jojo-home-preview-head {
        padding: 14px 16px;
        border-bottom: 1px solid rgba(148, 163, 184, .22);
        background: #0f172a;
        color: #fff;
    }

    .jojo-home-preview-head strong {
        display: block;
        font-size: 14px;
    }

    .jojo-home-preview-head span {
        color: #bfdbfe;
        font-size: 12px;
    }

    .jojo-preview-sample-badge {
        display: inline-flex;
        margin-top: 8px;
        padding: 4px 10px;
        border-radius: 999px;
        background: rgba(250, 204, 21, .16);
        color: #fde68a;
        font-size: 11px;
        font-weight: 800;
    }

    .jojo-phone-preview {
        width: min(100%, 360px);
        margin: 0 auto;
        padding: 14px;
        background: #fffdf8;
        color: #020617;
        font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    }

    .jojo-preview-hero {
        min-height: 112px;
        padding: 16px;
        border-radius: 18px;
        background: linear-gradient(135deg, #e0f2fe, #dbeafe);
        display: flex;
        align-items: end;
        justify-content: space-between;
        gap: 12px;
    }

    .jojo-preview-hero strong {
        display: block;
        font-size: 17px;
        line-height: 1.15;
    }

    .jojo-preview-hero span {
        display: block;
        margin-top: 4px;
        color: #475569;
        font-size: 12px;
    }

    .jojo-preview-section-title {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin: 20px 0 10px;
        font-weight: 800;
        font-size: 18px;
    }

    .jojo-preview-section-title small {
        color: #0284c7;
        font-size: 12px;
        font-weight: 800;
    }

    .jojo-preview-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 8px;
    }

    .jojo-preview-hero + .jojo-preview-grid {
        margin-top: 14px;
    }

    .jojo-preview-grid + .jojo-preview-grid {
        margin-top: 10px;
    }

    .jojo-preview-tile {
        min-height: 66px;
        padding: 10px;
        border: 1px solid #bae6fd;
        border-radius: 12px;
        background: #f0f9ff;
        display: grid;
        align-content: center;
        gap: 4px;
    }

    .jojo-preview-tile b {
        color: #0369a1;
        font-size: 13px;
    }

    .jojo-preview-tile span {
        color: #64748b;
        font-size: 11px;
        line-height: 1.25;
    }

    .jojo-preview-empty {
        padding: 16px;
        border: 1px dashed #bae6fd;
        border-radius: 12px;
        color: #64748b;
        text-align: center;
        font-size: 13px;
        font-weight: 700;
    }

    .jojo-preview-promo {
        min-height: 96px;
        margin-top: 20px;
        padding: 16px;
        border-radius: 18px;
        background: linear-gradient(135deg, #0ea5e9, #2563eb);
        color: #fff;
        position: relative;
        overflow: hidden;
    }

    .jojo-preview-promo::after {
        content: "%";
        position: absolute;
        right: 20px;
        top: 22px;
        width: 62px;
        height: 62px;
        border-radius: 16px;
        background: #facc15;
        color: #fff;
        display: grid;
        place-items: center;
        font-size: 26px;
        font-weight: 900;
        transform: rotate(-14deg);
    }

    .jojo-preview-promo small {
        display: inline-flex;
        padding: 5px 12px;
        border-radius: 999px;
        background: rgba(2, 132, 199, .78);
        font-weight: 800;
        font-size: 11px;
    }

    .jojo-preview-promo b {
        display: block;
        margin-top: 12px;
        max-width: 210px;
        font-size: 18px;
    }

    .jojo-preview-promo span {
        display: block;
        max-width: 230px;
        font-size: 12px;
    }

    .jojo-preview-note {
        margin-top: 12px;
        padding: 11px 12px;
        border: 1px solid #c7d2fe;
        border-radius: 14px;
        background: #eef2ff;
        color: #3730a3;
        font-size: 12px;
        line-height: 1.35;
    }
</style>

<div class="jojo-home-preview-card">
    <div class="jojo-home-preview-head">
        <strong>Customer Home Preview</strong>
        <span>Susunan aktif yang akan terbaca di FE customer.</span>
        @if($usingFallback)
            <div class="jojo-preview-sample-badge">Contoh tampilan, belum dari data aktif</div>
        @endif
    </div>

    <div class="jojo-phone-preview">
        <div class="jojo-preview-hero">
            <div>
                <strong>Hai Customer,<br>Selamat Datang!</strong>
                <span>Pesan berbagai layanan cepat, aman dan terpercaya lewat JOJO si Aplikasi Joker.</span>
            </div>
        </div>

        <div class="jojo-preview-grid">
            @foreach($sliderItems->take(4) as $item)
                <div class="jojo-preview-tile">
                    <b>{{ data_get($item, 'title') }}</b>
                    <span>{{ data_get($item, 'subtitle') ?: data_get($sliderSection, 'name') }}</span>
                </div>
            @endforeach
        </div>

        <div class="jojo-preview-grid">
            @foreach($promoItems->take(4) as $item)
                <div class="jojo-preview-tile">
                    <b>{{ data_get($item, 'title') }}</b>
                    <span>{{ data_get($item, 'subtitle') ?: data_get($promoSection, 'name') }}</span>
                </div>
            @endforeach
        </div>

        <div class="jojo-preview-promo">
            <small>Promo Spesial</small>
            <b>{{ data_get($specialAnnouncement, 'title') }}</b>
            <span>{{ Str::limit(strip_tags(data_get($specialAnnouncement, 'content')), 70) }}</span>
        </div>

        @foreach($previewAnnouncements->skip(1) as $announcement)
            <div class="jojo-preview-note">
                <strong>{{ data_get($announcement, 'title') }}</strong><br>
                {{ Str::limit(strip_tags(data_get($announcement, 'content')), 90) }}
            </div>
        @endforeach
    </div>
</div>
