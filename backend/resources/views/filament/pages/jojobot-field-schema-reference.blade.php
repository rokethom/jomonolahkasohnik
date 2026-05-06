<x-filament-panels::page>
    <style>
        .jojobot-reference {
            --jojo-surface: #ffffff;
            --jojo-panel: #f8fafc;
            --jojo-card: #ffffff;
            --jojo-text: #0f172a;
            --jojo-muted: #475569;
            --jojo-soft: #64748b;
            --jojo-border: #d8e0ea;
            --jojo-chip: #eef2f7;
            --jojo-chip-text: #1f2937;
            --jojo-primary: #0369a1;
            --jojo-primary-soft: #e0f2fe;
            --jojo-code-bg: #0b1020;
            --jojo-code-text: #e5e7eb;
            display: grid;
            gap: 1.25rem;
        }

        .dark .jojobot-reference {
            --jojo-surface: #101216;
            --jojo-panel: #161a21;
            --jojo-card: #1b2029;
            --jojo-text: #f8fafc;
            --jojo-muted: #d4dbe6;
            --jojo-soft: #aab5c4;
            --jojo-border: #3b4454;
            --jojo-chip: #262d38;
            --jojo-chip-text: #eef2f7;
            --jojo-primary: #7dd3fc;
            --jojo-primary-soft: #0c3448;
            --jojo-code-bg: #080b12;
            --jojo-code-text: #f3f7ff;
        }

        .jojobot-reference * {
            box-sizing: border-box;
        }

        .jojo-panel,
        .jojo-group,
        .jojo-note {
            background: var(--jojo-surface);
            border: 1px solid var(--jojo-border);
            border-radius: 14px;
            box-shadow: 0 10px 28px rgba(15, 23, 42, 0.08);
        }

        .jojo-panel {
            padding: 20px;
        }

        .jojo-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
        }

        .jojo-title,
        .jojo-group-title,
        .jojo-field-title,
        .jojo-preset-title {
            color: var(--jojo-text);
            font-weight: 800;
            line-height: 1.25;
        }

        .jojo-title {
            font-size: 1.05rem;
        }

        .jojo-copy,
        .jojo-field-note,
        .jojo-section-copy {
            color: var(--jojo-muted);
            font-size: 0.875rem;
            line-height: 1.65;
        }

        .jojo-badge {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            background: var(--jojo-primary-soft);
            color: var(--jojo-primary);
            font-size: 0.75rem;
            font-weight: 800;
            padding: 6px 10px;
            white-space: nowrap;
        }

        .jojo-grid {
            display: grid;
            gap: 16px;
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .jojo-group {
            padding: 18px;
        }

        .jojo-group-head {
            margin-bottom: 14px;
        }

        .jojo-group-title-row {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .jojo-dot {
            width: 10px;
            height: 10px;
            border-radius: 999px;
            flex: 0 0 auto;
        }

        .tone-sky { background: #38bdf8; }
        .tone-amber { background: #f59e0b; }
        .tone-emerald { background: #10b981; }
        .tone-violet { background: #8b5cf6; }

        .jojo-field-list {
            display: grid;
            gap: 12px;
        }

        .jojo-field {
            background: var(--jojo-card);
            border: 1px solid var(--jojo-border);
            border-radius: 12px;
            padding: 14px;
        }

        .jojo-chip-row,
        .jojo-alias-row {
            display: flex;
            flex-wrap: wrap;
            gap: 7px;
        }

        .jojo-chip-row {
            margin-top: 8px;
        }

        .jojo-chip,
        .jojo-parser-chip,
        .jojo-alias {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            font-size: 0.72rem;
            font-weight: 800;
            line-height: 1;
            padding: 7px 9px;
        }

        .jojo-chip,
        .jojo-alias {
            background: var(--jojo-chip);
            color: var(--jojo-chip-text);
            border: 1px solid var(--jojo-border);
        }

        .jojo-parser-chip {
            background: var(--jojo-primary-soft);
            color: var(--jojo-primary);
            border: 1px solid color-mix(in srgb, var(--jojo-primary) 32%, transparent);
        }

        .jojo-label {
            color: var(--jojo-soft);
            font-size: 0.72rem;
            font-weight: 900;
            letter-spacing: 0.04em;
            margin-bottom: 6px;
            margin-top: 12px;
            text-transform: uppercase;
        }

        .jojo-presets {
            display: grid;
            gap: 14px;
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }

        .jojo-active-schemas {
            display: grid;
            gap: 14px;
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .jojo-flow-list {
            display: grid;
            gap: 12px;
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }

        .jojo-flow {
            background: var(--jojo-card);
            border: 1px solid var(--jojo-border);
            border-radius: 12px;
            padding: 14px;
        }

        .jojo-flow ol {
            color: var(--jojo-muted);
            font-size: 0.84rem;
            line-height: 1.65;
            margin: 10px 0 0;
            padding-left: 20px;
        }

        .jojo-preset {
            background: var(--jojo-card);
            border: 1px solid var(--jojo-border);
            border-radius: 12px;
            overflow: hidden;
        }

        .jojo-preset-head {
            background: var(--jojo-panel);
            border-bottom: 1px solid var(--jojo-border);
            padding: 13px 14px;
        }

        .jojo-service {
            color: var(--jojo-primary);
            font-size: 0.75rem;
            font-weight: 900;
            margin-top: 4px;
        }

        .jojo-schema-meta {
            align-items: center;
            display: flex;
            flex-wrap: wrap;
            gap: 7px;
            margin-top: 8px;
        }

        .jojo-code {
            background: var(--jojo-code-bg);
            color: var(--jojo-code-text);
            display: block;
            font-size: 0.76rem;
            line-height: 1.6;
            max-height: 340px;
            overflow: auto;
            padding: 14px;
            white-space: pre;
        }

        .jojo-note {
            background: #fff7ed;
            border-color: #fed7aa;
            color: #7c2d12;
            font-size: 0.875rem;
            line-height: 1.65;
            padding: 16px 18px;
        }

        .dark .jojo-note {
            background: #2b1909;
            border-color: #9a5b18;
            color: #fed7aa;
        }

        @media (max-width: 1100px) {
            .jojo-grid,
            .jojo-presets,
            .jojo-active-schemas,
            .jojo-flow-list {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 640px) {
            .jojo-header {
                flex-direction: column;
            }

            .jojo-panel,
            .jojo-group {
                padding: 14px;
            }
        }
    </style>

    <div class="jojobot-reference">
        <div class="jojo-panel">
            <div class="jojo-header">
                <div>
                    <h2 class="jojo-title">Referensi Field Schema JojoBot</h2>
                    <p class="jojo-copy">
                        Halaman ini hanya dokumentasi untuk field yang dikenali parser JojoBot. Tidak menyimpan data baru dan tidak mengubah flow parser.
                    </p>
                </div>
                <span class="jojo-badge">Preview Only</span>
            </div>
        </div>

        <div class="jojo-grid">
            @foreach ($this->getFieldGroups() as $group)
                <section class="jojo-group">
                    <div class="jojo-group-head">
                        <div class="jojo-group-title-row">
                            <span class="jojo-dot tone-{{ $group['tone'] }}"></span>
                            <h3 class="jojo-group-title">{{ $group['title'] }}</h3>
                        </div>
                        <p class="jojo-section-copy">{{ $group['description'] }}</p>
                    </div>

                    <div class="jojo-field-list">
                        @foreach ($group['fields'] as $field)
                            <article class="jojo-field">
                                <div class="jojo-field-title">{{ $field['label'] }}</div>

                                <div class="jojo-chip-row">
                                    <span class="jojo-chip">name: {{ $field['name'] }}</span>
                                    <span class="jojo-chip">type: {{ $field['type'] }}</span>
                                    <span class="jojo-parser-chip">{{ $field['parser'] }}</span>
                                </div>

                                <p class="jojo-field-note">{{ $field['notes'] }}</p>

                                <div class="jojo-label">Label dikenali</div>
                                <div class="jojo-alias-row">
                                    @foreach ($field['aliases'] as $alias)
                                        <span class="jojo-alias">{{ $alias }}</span>
                                    @endforeach
                                </div>
                            </article>
                        @endforeach
                    </div>
                </section>
            @endforeach
        </div>

        <section class="jojo-panel">
            <div class="jojo-group-head">
                <h3 class="jojo-group-title">Read Only Flow AI & Order</h3>
                <p class="jojo-section-copy">
                    Flow ini hanya referensi backend agar operator bisa membedakan alamat pembelian, alamat jemput, dan alamat tujuan.
                </p>
            </div>

            <div class="jojo-flow-list">
                @foreach ($this->getOrderFlows() as $flow)
                    <article class="jojo-flow">
                        <div class="jojo-preset-title">{{ $flow['service'] }}</div>
                        <ol>
                            @foreach ($flow['flow'] as $step)
                                <li>{{ $step }}</li>
                            @endforeach
                        </ol>
                        <p class="jojo-field-note">{{ $flow['note'] }}</p>
                    </article>
                @endforeach
            </div>
        </section>

        <section class="jojo-panel">
            <div class="jojo-group-head">
                <h3 class="jojo-group-title">Preset Schema Yang Disarankan</h3>
                <p class="jojo-section-copy">
                    Gunakan contoh ini saat mengisi Builder Form di menu Keyword & Form Builder.
                </p>
            </div>

            <div class="jojo-presets">
                @foreach ($this->getPresetExamples() as $preset)
                    <article class="jojo-preset">
                        <div class="jojo-preset-head">
                            <div class="jojo-preset-title">{{ $preset['title'] }}</div>
                            <div class="jojo-service">service_type: {{ $preset['service'] }}</div>
                        </div>
                        <pre class="jojo-code"><code>{{ json_encode($preset['schema'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</code></pre>
                    </article>
                @endforeach
            </div>
        </section>

        <section class="jojo-panel">
            <div class="jojo-group-head">
                <h3 class="jojo-group-title">Form Schema Aktif di Database</h3>
                <p class="jojo-section-copy">
                    Tampilan read-only dari Keyword & Form Builder. Schema ini yang dikirim ke FE customer saat keyword JojoBot terdeteksi.
                </p>
            </div>

            <div class="jojo-active-schemas">
                @forelse ($this->getActiveFormSchemas() as $parser)
                    <article class="jojo-preset">
                        <div class="jojo-preset-head">
                            <div class="jojo-preset-title">{{ $parser['keyword'] }}</div>
                            <div class="jojo-schema-meta">
                                <span class="jojo-parser-chip">service: {{ $parser['service_type'] }}</span>
                                <span class="jojo-chip">mode: {{ $parser['parser_type'] }}</span>
                                <span class="jojo-chip">priority: {{ $parser['priority'] }}</span>
                                <span class="jojo-chip">updated: {{ $parser['updated_at'] ?? '-' }}</span>
                            </div>
                        </div>
                        <pre class="jojo-code"><code>{{ json_encode($parser['schema'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</code></pre>
                    </article>
                @empty
                    <article class="jojo-field">
                        <div class="jojo-field-title">Belum ada form_schema aktif</div>
                        <p class="jojo-field-note">Tambahkan schema di menu Keyword & Form Builder agar FE customer bisa menampilkan form otomatis.</p>
                    </article>
                @endforelse
            </div>
        </section>

        <section class="jojo-note">
            <strong>Catatan pricing:</strong>
            jika form terstruktur tidak memberi titik/koordinat lengkap, JojoBot tetap membuat preview memakai harga dasar. Saat order dikirim, lokasi hidden system/GPS tetap dipakai untuk validasi.
        </section>
    </div>
</x-filament-panels::page>
