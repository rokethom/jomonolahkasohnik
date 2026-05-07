<x-filament-panels::page>
    @php
        $status = $this->getStatus();
        $commands = $this->getCommands();
        $reports = $this->getReports();
        $activeReport = $this->getActiveReport();
    @endphp

    <style>
        .hermes-wrap {
            --hermes-bg: #ffffff;
            --hermes-panel: #f8fafc;
            --hermes-card: #ffffff;
            --hermes-text: #0f172a;
            --hermes-muted: #526071;
            --hermes-border: #d7dee8;
            --hermes-primary: #2563eb;
            --hermes-good: #16a34a;
            --hermes-warn: #d97706;
            --hermes-bad: #dc2626;
            display: grid;
            gap: 16px;
        }

        .dark .hermes-wrap {
            --hermes-bg: #0c111d;
            --hermes-panel: #111827;
            --hermes-card: #172033;
            --hermes-text: #f8fafc;
            --hermes-muted: #b8c3d6;
            --hermes-border: #334155;
            --hermes-primary: #60a5fa;
            --hermes-good: #86efac;
            --hermes-warn: #fbbf24;
            --hermes-bad: #fca5a5;
        }

        .hermes-grid {
            display: grid;
            grid-template-columns: minmax(0, .82fr) minmax(0, 1.18fr);
            gap: 16px;
            align-items: start;
        }

        .hermes-card {
            background: linear-gradient(180deg, color-mix(in srgb, var(--hermes-card) 94%, transparent), var(--hermes-card));
            border: 1px solid var(--hermes-border);
            border-radius: 18px;
            box-shadow: 0 18px 40px rgba(15, 23, 42, .08);
            color: var(--hermes-text);
            padding: 18px;
            min-width: 0;
        }

        .hermes-hero {
            background:
                radial-gradient(circle at top right, rgba(37, 99, 235, .16), transparent 34%),
                linear-gradient(135deg, var(--hermes-bg), var(--hermes-panel));
        }

        .hermes-title {
            font-size: 1.2rem;
            font-weight: 900;
            margin: 0;
        }

        .hermes-copy {
            color: var(--hermes-muted);
            font-size: .9rem;
            line-height: 1.65;
            margin: 6px 0 0;
        }

        .hermes-stats {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 12px;
        }

        .hermes-stat strong {
            display: block;
            font-size: 1.4rem;
            font-weight: 950;
            margin-top: 5px;
        }

        .hermes-stat span {
            color: var(--hermes-muted);
            font-size: .76rem;
            font-weight: 800;
            text-transform: uppercase;
        }

        .hermes-pill {
            align-items: center;
            border: 1px solid var(--hermes-border);
            border-radius: 999px;
            display: inline-flex;
            font-size: .76rem;
            font-weight: 900;
            gap: 6px;
            padding: 6px 10px;
        }

        .hermes-pill.good { background: rgba(22, 163, 74, .12); color: var(--hermes-good); }
        .hermes-pill.warn { background: rgba(217, 119, 6, .13); color: var(--hermes-warn); }
        .hermes-pill.bad { background: rgba(220, 38, 38, .12); color: var(--hermes-bad); }

        .hermes-command-grid {
            display: grid;
            gap: 10px;
            margin-top: 12px;
        }

        .hermes-command {
            border: 1px solid var(--hermes-border);
            border-radius: 14px;
            padding: 12px;
            background: color-mix(in srgb, var(--hermes-panel) 72%, transparent);
        }

        .hermes-command strong {
            display: block;
            font-size: .9rem;
        }

        .hermes-command p {
            color: var(--hermes-muted);
            font-size: .8rem;
            line-height: 1.55;
            margin: 4px 0 0;
        }

        .hermes-report {
            background: #08111f;
            border-radius: 16px;
            color: #e5edf9;
            font-size: .9rem;
            line-height: 1.72;
            max-height: 680px;
            overflow: auto;
            padding: 18px;
            white-space: pre-wrap;
        }

        .hermes-history {
            display: grid;
            gap: 10px;
            margin-top: 12px;
        }

        .hermes-history button {
            background: transparent;
            border: 1px solid var(--hermes-border);
            border-radius: 14px;
            color: var(--hermes-text);
            cursor: pointer;
            padding: 12px;
            text-align: left;
            width: 100%;
        }

        .hermes-history button.active {
            border-color: var(--hermes-primary);
            box-shadow: 0 0 0 2px color-mix(in srgb, var(--hermes-primary) 18%, transparent);
        }

        .hermes-history small {
            color: var(--hermes-muted);
            display: block;
            margin-top: 3px;
        }

        .hermes-actions {
            display: flex;
            justify-content: flex-end;
            margin-top: 14px;
        }

        @media (max-width: 1100px) {
            .hermes-grid, .hermes-stats {
                grid-template-columns: 1fr;
            }
        }
    </style>

    <div class="hermes-wrap">
        <section class="hermes-card hermes-hero">
            <div style="display:flex;justify-content:space-between;gap:14px;align-items:flex-start;flex-wrap:wrap">
                <div>
                    <h2 class="hermes-title">AI Engineering Center</h2>
                    <p class="hermes-copy">
                        Hermes membaca konteks source code, route, schema, log, failed jobs, dan metrik server untuk menghasilkan laporan engineering. Mode saat ini read-only agar aman untuk production.
                    </p>
                </div>
                <span @class(['hermes-pill', 'good' => $status['enabled'], 'bad' => ! $status['enabled']])>
                    {{ $status['enabled'] ? 'Hermes Enabled' : 'Hermes Disabled' }}
                </span>
            </div>
        </section>

        <section class="hermes-stats">
            <article class="hermes-card hermes-stat">
                <span>Model</span>
                <strong style="font-size:1rem;overflow-wrap:anywhere">{{ $status['model'] ?: '-' }}</strong>
            </article>
            <article class="hermes-card hermes-stat">
                <span>Base URL</span>
                <strong style="font-size:1rem;overflow-wrap:anywhere">{{ $status['base_url'] }}</strong>
            </article>
            <article class="hermes-card hermes-stat">
                <span>Total Reports</span>
                <strong>{{ number_format($status['reports_count']) }}</strong>
            </article>
            <article class="hermes-card hermes-stat">
                <span>Latest</span>
                <strong style="font-size:1rem">{{ $status['latest_report']?->created_at?->diffForHumans() ?: '-' }}</strong>
            </article>
        </section>

        <div class="hermes-grid">
            <section class="hermes-card">
                <h3 class="hermes-title">Run Hermes Command</h3>
                <p class="hermes-copy">Pilih tugas analisa. Hermes hanya membaca snapshot terbatas dan menyimpan report ke database.</p>
                <div style="margin-top:14px">
                    {{ $this->form }}
                </div>
                <div class="hermes-actions">
                    <x-filament::button icon="heroicon-o-sparkles" wire:click="runHermes" wire:loading.attr="disabled">
                        Jalankan Hermes
                    </x-filament::button>
                </div>

                <div class="hermes-command-grid">
                    @foreach ($commands as $command)
                        <article class="hermes-command">
                            <strong>{{ $command['label'] }}</strong>
                            <p>{{ $command['description'] }}</p>
                        </article>
                    @endforeach
                </div>
            </section>

            <section class="hermes-card">
                <div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap">
                    <div>
                        <h3 class="hermes-title">Hermes Report</h3>
                        <p class="hermes-copy">
                            @if ($activeReport)
                                {{ $activeReport->command }} · {{ $activeReport->created_at?->format('d M Y H:i') }} · {{ $activeReport->duration_ms }}ms
                            @else
                                Belum ada report.
                            @endif
                        </p>
                    </div>
                    @if ($activeReport)
                        <span @class(['hermes-pill', 'good' => $activeReport->status === 'completed', 'bad' => $activeReport->status === 'failed', 'warn' => $activeReport->status === 'running'])>
                            {{ strtoupper($activeReport->status) }}
                        </span>
                    @endif
                </div>

                @if ($activeReport?->report)
                    <div class="hermes-report" style="margin-top:14px">{{ $activeReport->report }}</div>
                @elseif ($activeReport?->error_message)
                    <div class="hermes-report" style="margin-top:14px;color:#fecaca">{{ $activeReport->error_message }}</div>
                @else
                    <p class="hermes-copy" style="margin-top:14px">Jalankan command untuk membuat laporan AI engineering pertama.</p>
                @endif
            </section>
        </div>

        <section class="hermes-card">
            <h3 class="hermes-title">AI Reports History</h3>
            <p class="hermes-copy">Riwayat ini membantu audit: siapa menjalankan command, model yang dipakai, durasi, dan hasilnya.</p>
            <div class="hermes-history">
                @forelse ($reports as $report)
                    <button type="button" wire:click="showReport({{ $report->id }})" @class(['active' => $activeReport?->id === $report->id])>
                        <strong>{{ str($report->command)->replace('_', ' ')->title() }}</strong>
                        <small>
                            {{ $report->created_at?->format('d M Y H:i') }} · {{ $report->user?->name ?: 'System' }} · {{ $report->model ?: '-' }} · {{ $report->duration_ms }}ms
                        </small>
                    </button>
                @empty
                    <p class="hermes-copy">Belum ada history report.</p>
                @endforelse
            </div>
        </section>
    </div>
</x-filament-panels::page>
