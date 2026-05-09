<x-filament-panels::page>
    @php
        $blocks = $this->getBlocks();
        $customerName = $this->data['preview_customer_name'] ?? 'Jomono';
        $blockIcon = [
            'hero_greeting' => 'HI',
            'cms_slider' => 'SL',
            'service_menu' => 'MN',
            'promo_section' => '%',
            'announcement' => '!',
            'quick_action' => '+',
            'history_shortcut' => 'HS',
            'profile_card' => 'PR',
            'logout_button' => 'X',
            'bottom_nav' => 'NV',
        ];
    @endphp

    <style>
        .customer-builder {
            --builder-bg: #f8fafc;
            --builder-card: #ffffff;
            --builder-text: #0f172a;
            --builder-muted: #64748b;
            --builder-border: #dbe3ef;
            --builder-blue: #0ea5e9;
            --builder-green: #10b981;
            --builder-danger: #ef4444;
            display: grid;
            grid-template-columns: minmax(0, 1.05fr) minmax(330px, 0.8fr);
            gap: 18px;
            align-items: start;
        }

        .dark .customer-builder {
            --builder-bg: #0f172a;
            --builder-card: #172033;
            --builder-text: #f8fafc;
            --builder-muted: #cbd5e1;
            --builder-border: #334155;
            --builder-blue: #38bdf8;
            --builder-green: #34d399;
            --builder-danger: #fb7185;
        }

        .customer-builder * {
            box-sizing: border-box;
            min-width: 0;
        }

        .builder-panel {
            background: var(--builder-card);
            border: 1px solid var(--builder-border);
            border-radius: 16px;
            box-shadow: 0 14px 35px rgba(15, 23, 42, .08);
            padding: 18px;
        }

        .builder-note {
            color: var(--builder-muted);
            font-size: .88rem;
            line-height: 1.6;
            margin-top: 6px;
        }

        .builder-preview-wrap {
            position: sticky;
            top: 92px;
        }

        .phone-frame {
            width: min(100%, 390px);
            margin: 0 auto;
            border: 10px solid #111827;
            border-radius: 34px;
            background: #eef7ff;
            overflow: hidden;
            box-shadow: 0 24px 60px rgba(2, 6, 23, .25);
        }

        .phone-top {
            background: #fff;
            color: #04142f;
            display: flex;
            gap: 10px;
            align-items: center;
            padding: 14px 16px;
            border-bottom: 1px solid #e2e8f0;
        }

        .phone-logo {
            width: 40px;
            height: 40px;
            display: grid;
            place-items: center;
            border-radius: 999px;
            background: linear-gradient(135deg, #06b6d4, #2563eb);
            color: #fff;
            font-weight: 900;
        }

        .phone-body {
            min-height: 610px;
            max-height: 680px;
            overflow: auto;
            padding: 16px;
            background:
                linear-gradient(115deg, rgba(14, 165, 233, .08), transparent 35%),
                repeating-linear-gradient(65deg, rgba(15, 23, 42, .05) 0 1px, transparent 1px 18px),
                #fbfaf3;
        }

        .preview-block {
            display: grid;
            gap: 7px;
            margin-bottom: 12px;
            border-radius: 16px;
            border: 1px solid rgba(15, 23, 42, .1);
            background: rgba(255, 255, 255, .86);
            color: #06132d;
            padding: 14px;
        }

        .preview-block.solid {
            background: linear-gradient(135deg, #0ea5e9, #2563eb);
            color: #fff;
            border-color: transparent;
        }

        .preview-block.outline {
            background: rgba(255, 255, 255, .62);
            border: 1px dashed rgba(14, 165, 233, .5);
        }

        .preview-block.danger {
            background: #fff1f2;
            color: #9f1239;
            border-color: #fecdd3;
        }

        .preview-block-head {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .preview-icon {
            width: 32px;
            height: 32px;
            display: grid;
            place-items: center;
            border-radius: 11px;
            background: rgba(14, 165, 233, .14);
            color: inherit;
            font-weight: 900;
        }

        .preview-block strong {
            font-size: .98rem;
            line-height: 1.2;
        }

        .preview-block p {
            margin: 0;
            font-size: .82rem;
            line-height: 1.45;
            opacity: .82;
        }

        .service-dots {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 8px;
            margin-top: 6px;
        }

        .service-dots span {
            border-radius: 12px;
            background: rgba(14, 165, 233, .12);
            color: #0369a1;
            font-size: .72rem;
            font-weight: 800;
            padding: 9px 6px;
            text-align: center;
        }

        .bottom-nav-preview {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 6px;
            margin-top: 4px;
        }

        .bottom-nav-preview span {
            border-radius: 12px;
            background: rgba(15, 23, 42, .08);
            padding: 8px 4px;
            text-align: center;
            font-size: .7rem;
            font-weight: 800;
        }

        @media (max-width: 1120px) {
            .customer-builder {
                grid-template-columns: 1fr;
            }

            .builder-preview-wrap {
                position: static;
            }
        }
    </style>

    <div class="customer-builder">
        <form wire:submit="save" class="builder-panel">
            <div>
                <h2 style="margin:0;color:var(--builder-text);font-size:1.25rem;font-weight:900">Block Builder Simulation</h2>
                <p class="builder-note">
                    Susun block seperti page builder, tetapi hasilnya hanya disimpan sebagai draft simulasi backend.
                    FE customer saat ini belum membaca setting ini.
                </p>
            </div>

            <div style="margin-top:16px">
                {{ $this->form }}
            </div>

            <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:16px">
                <x-filament::button type="submit">
                    Simpan Draft Simulasi
                </x-filament::button>
                <x-filament::button color="gray" type="button" wire:click="resetSimulation">
                    Reset Default
                </x-filament::button>
            </div>
        </form>

        <section class="builder-panel builder-preview-wrap">
            <div>
                <h2 style="margin:0;color:var(--builder-text);font-size:1.25rem;font-weight:900">Live Preview</h2>
                <p class="builder-note">{{ $this->getPreviewPageLabel() }} · mock mobile customer</p>
            </div>

            <div class="phone-frame" style="margin-top:14px">
                <div class="phone-top">
                    <div class="phone-logo">JO</div>
                    <div>
                        <strong>JOJO</strong>
                        <div style="font-size:.75rem;color:#475569">SI APLIKASI JOKER</div>
                    </div>
                </div>
                <div class="phone-body">
                    @forelse ($blocks as $block)
                        @php
                            $style = $block['style'] ?? 'soft';
                            $type = $block['type'] ?? 'quick_action';
                            $label = str_replace('{customer}', $customerName, $block['label'] ?? 'Block');
                            $subtitle = str_replace('{customer}', $customerName, $block['subtitle'] ?? '');
                        @endphp
                        <article class="preview-block {{ $style }}">
                            <div class="preview-block-head">
                                <span class="preview-icon">{{ $blockIcon[$type] ?? '•' }}</span>
                                <div>
                                    <strong>{{ $label }}</strong>
                                    @if ($subtitle !== '')
                                        <p>{{ $subtitle }}</p>
                                    @endif
                                </div>
                            </div>

                            @if ($type === 'service_menu')
                                <div class="service-dots">
                                    <span>Belanja</span>
                                    <span>Delivery</span>
                                    <span>Kurir</span>
                                    <span>Ojek</span>
                                    <span>Gift</span>
                                    <span>Mobil</span>
                                </div>
                            @elseif ($type === 'cms_slider')
                                <div style="height:76px;border-radius:14px;background:linear-gradient(135deg,#bae6fd,#38bdf8);display:grid;place-items:center;font-weight:900;color:#075985">
                                    Slide CMS
                                </div>
                            @elseif ($type === 'promo_section')
                                <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
                                    <span style="border-radius:12px;background:rgba(255,255,255,.35);padding:10px;font-weight:800">Promo 1</span>
                                    <span style="border-radius:12px;background:rgba(255,255,255,.35);padding:10px;font-weight:800">Promo 2</span>
                                </div>
                            @elseif ($type === 'bottom_nav')
                                <div class="bottom-nav-preview">
                                    <span>Home</span>
                                    <span>Order</span>
                                    <span>Chat</span>
                                    <span>Profile</span>
                                </div>
                            @elseif (($block['target'] ?? 'none') !== 'none')
                                <p>Action target: {{ $block['target'] }}</p>
                            @endif
                        </article>
                    @empty
                        <article class="preview-block outline">
                            <strong>Belum ada block visible</strong>
                            <p>Aktifkan minimal satu block untuk melihat simulasi.</p>
                        </article>
                    @endforelse
                </div>
            </div>
        </section>
    </div>
</x-filament-panels::page>
