<x-filament-panels::page>
    @php
        $preview = $this->getPreview();
        $money = fn ($value) => 'Rp '.number_format((int) $value, 0, ',', '.');
    @endphp

    <style>
        .template-lab {
            --lab-surface: #ffffff;
            --lab-panel: #f8fafc;
            --lab-card: #ffffff;
            --lab-text: #0f172a;
            --lab-muted: #475569;
            --lab-border: #d8e0ea;
            --lab-chip: #eef2f7;
            --lab-primary: #0369a1;
            --lab-primary-soft: #e0f2fe;
            --lab-good: #15803d;
            --lab-good-soft: #dcfce7;
            --lab-warn: #92400e;
            --lab-warn-soft: #fef3c7;
            --lab-code-bg: #0b1020;
            --lab-code-text: #edf2ff;
            display: grid;
            gap: 1.25rem;
            max-width: 100%;
            min-width: 0;
            overflow: hidden;
        }

        .template-lab * {
            box-sizing: border-box;
            min-width: 0;
        }

        .dark .template-lab {
            --lab-surface: #101216;
            --lab-panel: #161a21;
            --lab-card: #1b2029;
            --lab-text: #f8fafc;
            --lab-muted: #d4dbe6;
            --lab-border: #3b4454;
            --lab-chip: #262d38;
            --lab-primary: #7dd3fc;
            --lab-primary-soft: #0c3448;
            --lab-good: #86efac;
            --lab-good-soft: #10351f;
            --lab-warn: #fcd34d;
            --lab-warn-soft: #3a2a0d;
            --lab-code-bg: #080b12;
            --lab-code-text: #f3f7ff;
        }

        .lab-panel {
            background: var(--lab-surface);
            border: 1px solid var(--lab-border);
            border-radius: 14px;
            box-shadow: 0 10px 28px rgba(15, 23, 42, 0.08);
            padding: 18px;
            max-width: 100%;
            min-width: 0;
            overflow: hidden;
        }

        .lab-header {
            display: flex;
            justify-content: space-between;
            gap: 14px;
            align-items: flex-start;
        }

        .lab-title,
        .lab-section-title,
        .lab-field-label {
            color: var(--lab-text);
            font-weight: 850;
            line-height: 1.25;
        }

        .lab-copy,
        .lab-muted {
            color: var(--lab-muted);
            font-size: 0.875rem;
            line-height: 1.65;
        }

        .lab-badge {
            display: inline-flex;
            border-radius: 999px;
            padding: 6px 10px;
            font-size: 0.75rem;
            font-weight: 900;
            white-space: nowrap;
        }

        .lab-badge.good {
            background: var(--lab-good-soft);
            color: var(--lab-good);
        }

        .lab-badge.warn {
            background: var(--lab-warn-soft);
            color: var(--lab-warn);
        }

        .lab-grid {
            display: grid;
            grid-template-columns: minmax(0, 0.95fr) minmax(0, 1.05fr);
            gap: 16px;
            align-items: start;
            max-width: 100%;
            min-width: 0;
        }

        .lab-preview-stack,
        .lab-field-list {
            display: grid;
            gap: 12px;
            min-width: 0;
        }

        .lab-meta-row {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 10px;
        }

        .lab-chip {
            display: inline-flex;
            align-items: center;
            border: 1px solid var(--lab-border);
            border-radius: 999px;
            background: var(--lab-chip);
            color: var(--lab-text);
            font-size: 0.75rem;
            font-weight: 800;
            padding: 7px 10px;
            max-width: 100%;
            white-space: normal;
            word-break: break-word;
        }

        .lab-field {
            border: 1px solid var(--lab-border);
            background: var(--lab-card);
            border-radius: 12px;
            padding: 13px;
        }

        .lab-field-top {
            display: flex;
            justify-content: space-between;
            gap: 10px;
            min-width: 0;
        }

        .lab-field-value {
            color: var(--lab-muted);
            font-size: 0.8rem;
            margin-top: 8px;
            word-break: break-word;
        }

        .lab-target {
            color: var(--lab-primary);
            font-size: 0.78rem;
            font-weight: 900;
            margin-top: 6px;
        }

        .lab-code {
            background: var(--lab-code-bg);
            border-radius: 12px;
            color: var(--lab-code-text);
            display: block;
            font-size: 0.76rem;
            line-height: 1.6;
            max-height: 360px;
            overflow-x: auto;
            overflow-y: auto;
            padding: 14px;
            white-space: pre;
            width: 100%;
        }

        .lab-reply {
            border: 1px solid var(--lab-border);
            border-radius: 12px;
            background: var(--lab-panel);
            color: var(--lab-text);
            font-size: 0.86rem;
            line-height: 1.65;
            max-height: 460px;
            overflow: auto;
            padding: 14px;
            white-space: pre-line;
            overflow-wrap: anywhere;
        }

        .lab-price-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(145px, 1fr));
            gap: 8px;
            max-width: 100%;
        }

        .lab-price {
            border: 1px solid var(--lab-border);
            border-radius: 12px;
            background: var(--lab-card);
            padding: 12px;
            min-width: 0;
        }

        .lab-price span {
            color: var(--lab-muted);
            display: block;
            font-size: 0.72rem;
            font-weight: 800;
            text-transform: uppercase;
        }

        .lab-price strong {
            color: var(--lab-text);
            display: block;
            font-size: 1rem;
            margin-top: 4px;
            overflow-wrap: anywhere;
        }

        @media (max-width: 1100px) {
            .lab-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 640px) {
            .lab-panel {
                padding: 14px;
            }

            .lab-header,
            .lab-field-top {
                flex-direction: column;
            }
        }
    </style>

    <div class="template-lab">
        <section class="lab-panel">
            <div class="lab-header">
                <div>
                    <h2 class="lab-title">Template Parser Lab</h2>
                    <p class="lab-copy">
                        Paste format order dari operasional untuk melihat apakah parser existing sudah bisa membaca field dan payload order. Halaman ini preview-only dan tidak menyimpan database.
                    </p>
                </div>
                <span @class(['lab-badge', 'good' => $preview['matched'], 'warn' => ! $preview['matched']])>
                    {{ $preview['matched'] ? 'Parser Match' : 'Preview Field Only' }}
                </span>
            </div>
        </section>

        <div class="lab-grid">
            <section class="lab-panel">
                {{ $this->form }}
            </section>

            <section class="lab-preview-stack">
                <div class="lab-panel">
                    <h3 class="lab-section-title">Status Parser</h3>
                    <p class="lab-copy">{{ $preview['message'] }}</p>
                    <div class="lab-meta-row">
                        <span class="lab-chip">Service: {{ $preview['service'] }}</span>
                        <span class="lab-chip">Field terbaca: {{ count($preview['fields']) }}</span>
                        @if ($preview['error'])
                            <span class="lab-chip">Error: {{ $preview['error'] }}</span>
                        @endif
                    </div>
                </div>

                <div class="lab-panel">
                    <h3 class="lab-section-title">Field Mapping Preview</h3>
                    <p class="lab-muted">Mapping ini membantu admin melihat field mana yang akan diarahkan ke parser JojoBot.</p>
                    <div class="lab-field-list">
                        @forelse ($preview['fields'] as $field)
                            <article class="lab-field">
                                <div class="lab-field-top">
                                    <div>
                                        <div class="lab-field-label">{{ $field['label'] }}</div>
                                        <div class="lab-target">{{ $field['target'] }}</div>
                                    </div>
                                    <span @class(['lab-badge', 'good' => $field['status'] === 'Known', 'warn' => $field['status'] !== 'Known'])>{{ $field['status'] }}</span>
                                </div>
                                <div class="lab-field-value">
                                    Section: {{ $field['section'] }}<br>
                                    Value: {{ $field['value'] !== '' ? $field['value'] : '(kosong)' }}
                                </div>
                            </article>
                        @empty
                            <p class="lab-muted">Belum ada field label yang terbaca.</p>
                        @endforelse
                    </div>
                </div>

                @if ($preview['quote'])
                    <div class="lab-panel">
                        <h3 class="lab-section-title">Preview Harga</h3>
                        <div class="lab-price-grid">
                            <div class="lab-price">
                                <span>Tarif</span>
                                <strong>{{ $money($preview['quote']['tarif'] ?? $preview['quote']['price'] ?? 0) }}</strong>
                            </div>
                            <div class="lab-price">
                                <span>Service Fee</span>
                                <strong>{{ $money($preview['quote']['service_fee'] ?? $preview['quote']['service_charge'] ?? 0) }}</strong>
                            </div>
                            <div class="lab-price">
                                <span>Total</span>
                                <strong>{{ $money($preview['quote']['total_price'] ?? $preview['quote']['final_price'] ?? 0) }}</strong>
                            </div>
                        </div>
                    </div>
                @endif

                @if ($preview['reply'])
                    <div class="lab-panel">
                        <h3 class="lab-section-title">Preview Reply JojoBot</h3>
                        <div class="lab-reply">{{ $preview['reply'] }}</div>
                    </div>
                @endif

                @if ($preview['payload'])
                    <div class="lab-panel">
                        <h3 class="lab-section-title">Order Payload</h3>
                        <pre class="lab-code"><code>{{ json_encode($preview['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</code></pre>
                    </div>
                @endif

                @if ($preview['parsed'])
                    <div class="lab-panel">
                        <h3 class="lab-section-title">Parsed Result</h3>
                        <pre class="lab-code"><code>{{ json_encode(collect($preview['parsed'])->except(['branch', 'payload'])->all(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</code></pre>
                    </div>
                @endif
            </section>
        </div>
    </div>
</x-filament-panels::page>
