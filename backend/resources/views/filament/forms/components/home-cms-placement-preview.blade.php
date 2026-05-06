@php
    $kind = $kind ?? 'item';
    $readState = isset($get) && is_callable($get) ? $get : fn (string $key): mixed => null;
    $title = $readState('title') ?: $readState('name') ?: match ($kind) {
        'banner' => 'Judul banner',
        'section' => 'Nama section',
        'announcement' => 'Judul announcement',
        default => 'Judul item',
    };
    $subtitle = $readState('subtitle') ?: match ($kind) {
        'banner' => 'Banner tampil sebagai promo slider di home customer.',
        'section' => 'Section tipe slider tampil sebagai slide. Section tipe promo tampil sebagai deretan promo.',
        'announcement' => 'Announcement tampil sebagai informasi/promo aktif.',
        default => 'Item tampil di grid sesuai section yang dipilih.',
    };
    $type = $readState('type') ?: 'grid';
@endphp

<style>
    .home-cms-form-preview {
        display: grid;
        grid-template-columns: minmax(0, 1fr) 240px;
        gap: 14px;
        align-items: stretch;
        padding: 14px;
        border: 1px solid rgba(148, 163, 184, .24);
        border-radius: 16px;
        background: rgba(15, 23, 42, .04);
    }

    .home-cms-form-preview-copy strong {
        display: block;
        color: rgb(15, 23, 42);
        font-size: 14px;
    }

    .dark .home-cms-form-preview-copy strong {
        color: #f8fafc;
    }

    .home-cms-form-preview-copy p {
        margin-top: 4px;
        color: rgb(100, 116, 139);
        font-size: 13px;
        line-height: 1.45;
    }

    .home-cms-mini-phone {
        padding: 10px;
        border-radius: 18px;
        background: #fffdf8;
        box-shadow: inset 0 0 0 1px #e2e8f0;
    }

    .home-cms-mini-card {
        min-height: 74px;
        padding: 12px;
        border-radius: 14px;
        background: linear-gradient(135deg, #e0f2fe, #bfdbfe);
        color: #0f172a;
    }

    .home-cms-mini-card.banner,
    .home-cms-mini-card.announcement {
        background: linear-gradient(135deg, #0ea5e9, #2563eb);
        color: #fff;
    }

    .home-cms-mini-card.item {
        border: 1px solid #bae6fd;
        background: #f0f9ff;
    }

    .home-cms-mini-card small {
        display: inline-block;
        margin-bottom: 8px;
        color: inherit;
        opacity: .76;
        font-weight: 800;
    }

    .home-cms-mini-card b {
        display: block;
        font-size: 14px;
        line-height: 1.2;
    }

    .home-cms-mini-card span {
        display: block;
        margin-top: 4px;
        font-size: 11px;
        line-height: 1.25;
        opacity: .82;
    }

    @media (max-width: 900px) {
        .home-cms-form-preview {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="home-cms-form-preview">
    <div class="home-cms-form-preview-copy">
        <strong>Preview penempatan di FE customer</strong>
        <p>
            @switch($kind)
                @case('banner')
                    Setelah disimpan dan aktif, banner ini masuk area promo/slider pada home customer.
                    @break
                @case('section')
                    Section ini menjadi grup tampilan. Gunakan tipe Slider untuk slide home, dan tipe Promo untuk deretan promo di bawahnya.
                    @break
                @case('announcement')
                    Announcement aktif pertama tampil sebagai kartu Promo Spesial di home customer.
                    @break
                @default
                    Item aktif tampil sebagai tile pada section yang dipilih, cocok untuk shortcut layanan atau layanan populer.
            @endswitch
        </p>
    </div>

    <div class="home-cms-mini-phone">
        <div class="home-cms-mini-card {{ $kind }}">
            <small>{{ $kind === 'section' ? strtoupper($type) : ucfirst($kind) }}</small>
            <b>{{ $title }}</b>
            <span>{{ $subtitle }}</span>
        </div>
    </div>
</div>
