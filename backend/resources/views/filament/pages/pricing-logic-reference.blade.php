<x-filament-panels::page>
    @php
        $rules = $this->getRules();
        $preview = $this->getPreview();
        $money = fn ($value) => 'Rp '.number_format((int) $value, 0, ',', '.');
    @endphp

    <style>
        .pricing-reference {
            --price-surface: #ffffff;
            --price-panel: #f8fafc;
            --price-card: #ffffff;
            --price-text: #0f172a;
            --price-muted: #475569;
            --price-border: #d8e0ea;
            --price-chip: #eef2f7;
            --price-primary: #0369a1;
            --price-primary-soft: #e0f2fe;
            --price-good: #15803d;
            --price-good-soft: #dcfce7;
            display: grid;
            gap: 1.25rem;
            min-width: 0;
            max-width: 100%;
        }

        .dark .pricing-reference {
            --price-surface: #101216;
            --price-panel: #161a21;
            --price-card: #1b2029;
            --price-text: #f8fafc;
            --price-muted: #d4dbe6;
            --price-border: #3b4454;
            --price-chip: #262d38;
            --price-primary: #7dd3fc;
            --price-primary-soft: #0c3448;
            --price-good: #86efac;
            --price-good-soft: #10351f;
        }

        .pricing-reference * {
            box-sizing: border-box;
            min-width: 0;
        }

        .price-panel {
            background: var(--price-surface);
            border: 1px solid var(--price-border);
            border-radius: 14px;
            box-shadow: 0 10px 28px rgba(15, 23, 42, 0.08);
            padding: 18px;
            overflow: hidden;
        }

        .price-title,
        .price-card-title {
            color: var(--price-text);
            font-weight: 850;
            line-height: 1.25;
        }

        .price-copy {
            color: var(--price-muted);
            font-size: 0.875rem;
            line-height: 1.65;
        }

        .price-grid {
            display: grid;
            grid-template-columns: minmax(0, 0.9fr) minmax(0, 1.1fr);
            gap: 16px;
            align-items: start;
        }

        .price-rule-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 12px;
        }

        .price-rule {
            background: var(--price-card);
            border: 1px solid var(--price-border);
            border-radius: 12px;
            padding: 14px;
        }

        .price-chip-row {
            display: flex;
            flex-wrap: wrap;
            gap: 7px;
            margin-top: 10px;
        }

        .price-chip,
        .price-badge {
            display: inline-flex;
            border-radius: 999px;
            font-size: 0.74rem;
            font-weight: 850;
            padding: 7px 10px;
            white-space: normal;
        }

        .price-chip {
            background: var(--price-chip);
            border: 1px solid var(--price-border);
            color: var(--price-text);
        }

        .price-badge {
            background: var(--price-primary-soft);
            color: var(--price-primary);
        }

        .price-badge.good {
            background: var(--price-good-soft);
            color: var(--price-good);
        }

        .price-flow {
            display: grid;
            gap: 10px;
            counter-reset: flow;
        }

        .price-flow li {
            list-style: none;
            border: 1px solid var(--price-border);
            background: var(--price-panel);
            border-radius: 12px;
            color: var(--price-text);
            padding: 12px 14px;
        }

        .price-flow li::before {
            counter-increment: flow;
            content: counter(flow);
            display: inline-grid;
            place-items: center;
            width: 24px;
            height: 24px;
            margin-right: 8px;
            border-radius: 999px;
            background: var(--price-primary-soft);
            color: var(--price-primary);
            font-size: 0.75rem;
            font-weight: 900;
        }

        .price-preview-total {
            border: 1px solid var(--price-border);
            border-radius: 12px;
            background: var(--price-panel);
            padding: 14px;
        }

        .price-preview-total span {
            color: var(--price-muted);
            display: block;
            font-size: 0.75rem;
            font-weight: 850;
            text-transform: uppercase;
        }

        .price-preview-total strong {
            color: var(--price-text);
            display: block;
            font-size: 1.35rem;
            margin-top: 4px;
        }

        @media (max-width: 1100px) {
            .price-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>

    <div class="pricing-reference">
        <section class="price-panel">
            <div style="display:flex;justify-content:space-between;gap:14px;align-items:flex-start;flex-wrap:wrap">
                <div>
                    <h2 class="price-title">Pricing Keyword Logic</h2>
                    <p class="price-copy">
                        Dokumentasi dan preview charge tambahan berbasis keyword. Rule aktif dari CMS akan dipakai sebagai logic real. Jika belum ada rule aktif, sistem fallback ke hardcoded rule lama.
                    </p>
                </div>
                <span class="price-badge good">{{ $rules['label'] }}</span>
            </div>
        </section>

        <div class="price-grid">
            <section class="price-panel">
                <h3 class="price-card-title">Preview Charge</h3>
                <p class="price-copy">Coba text yang biasa muncul di form order.</p>
                <div style="margin-top:12px">{{ $this->form }}</div>
                <div class="price-preview-total" style="margin-top:14px">
                    <span>Total Keyword Charge</span>
                    <strong>{{ $money($preview['amount']) }}</strong>
                </div>
                <div class="price-chip-row">
                    <span class="price-chip">Mode: {{ $preview['mode'] }}</span>
                    <span class="price-chip">Matches: {{ count($preview['matches']) }}</span>
                </div>
                <div class="price-chip-row">
                    @forelse ($preview['matches'] as $match)
                        <span class="price-badge">{{ $match['keyword'] }} + {{ $money($match['amount']) }}</span>
                    @empty
                        <span class="price-chip">Belum ada keyword match.</span>
                    @endforelse
                </div>
            </section>

            <section class="price-panel">
                <h3 class="price-card-title">Flow Logic</h3>
                <ol class="price-flow" style="margin-top:12px;padding:0">
                    <li>Order payload dihitung dari form/chat JojoBot.</li>
                    <li>Pricing membaca text dari destination_text, destination_address, notes, service_payload, dan items.</li>
                    <li>Jika ada rule aktif di table pricing_keyword_rules, sistem memakai rule database.</li>
                    <li>Jika table/rule aktif kosong, sistem memakai fallback hardcoded lama.</li>
                    <li>Charge masuk ke extra_charge, keyword_charge, service_charge, lalu total dibulatkan.</li>
                </ol>
            </section>
        </div>

        <section class="price-panel">
            <h3 class="price-card-title">Rules Yang Sedang Berlaku</h3>
            <p class="price-copy">Daftar ini menampilkan rule database aktif atau fallback hardcoded yang sedang dipakai.</p>
            <div class="price-rule-grid" style="margin-top:14px">
                @foreach ($rules['rules'] as $rule)
                    <article class="price-rule">
                        <div style="display:flex;justify-content:space-between;gap:10px;align-items:flex-start">
                            <div>
                                <div class="price-card-title">{{ $rule['name'] }}</div>
                                <p class="price-copy">{{ $rule['description'] }}</p>
                            </div>
                            <span class="price-badge">{{ $money($rule['amount']) }}</span>
                        </div>
                        <div class="price-chip-row">
                            @foreach ($rule['keywords'] as $keyword)
                                <span class="price-chip">{{ $keyword }}</span>
                            @endforeach
                        </div>
                        <div class="price-chip-row">
                            @foreach ($rule['service_scopes'] as $scope)
                                <span class="price-badge">{{ $scope }}</span>
                            @endforeach
                        </div>
                        <p class="price-copy" style="margin-top:10px">Dibaca dari: {{ implode(', ', $rule['source_fields']) }}</p>
                    </article>
                @endforeach
            </div>
        </section>
    </div>
</x-filament-panels::page>
