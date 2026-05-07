<x-filament-panels::page>
    <style>
        .ai-monitor-wrap { display: grid; gap: 18px; }
        .ai-monitor-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; }
        .ai-monitor-card {
            border: 1px solid rgba(148, 163, 184, .22);
            border-radius: 18px;
            background: rgba(15, 23, 42, .04);
            padding: 16px;
        }
        .ai-monitor-card h3, .ai-monitor-section h2 { margin: 0; font-weight: 900; }
        .ai-monitor-card h3 { font-size: 13px; color: rgb(100, 116, 139); text-transform: uppercase; }
        .ai-monitor-card strong { display: block; margin-top: 8px; font-size: 24px; }
        .ai-monitor-card span { display: block; margin-top: 4px; color: rgb(100, 116, 139); font-size: 13px; }
        .ai-monitor-section {
            border: 1px solid rgba(148, 163, 184, .22);
            border-radius: 20px;
            background: rgba(15, 23, 42, .035);
            padding: 18px;
        }
        .ai-monitor-section p { margin: 4px 0 0; color: rgb(100, 116, 139); }
        .ai-model-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; margin-top: 14px; }
        .ai-model-card { border-top: 4px solid #22c55e; }
        .ai-model-card.warning { border-top-color: #f59e0b; }
        .ai-model-card.critical { border-top-color: #ef4444; }
        .ai-monitor-table { width: 100%; margin-top: 14px; border-collapse: collapse; }
        .ai-monitor-table th, .ai-monitor-table td {
            border-bottom: 1px solid rgba(148, 163, 184, .18);
            padding: 10px 8px;
            text-align: left;
            vertical-align: top;
        }
        .ai-monitor-table th { color: rgb(100, 116, 139); font-size: 12px; text-transform: uppercase; }
        .ai-pill {
            display: inline-flex;
            border-radius: 999px;
            background: rgba(14, 165, 233, .13);
            color: #0284c7;
            font-size: 12px;
            font-weight: 800;
            padding: 4px 8px;
        }
        .ai-pill.warning { background: rgba(245, 158, 11, .16); color: #b45309; }
        .ai-pill.critical { background: rgba(239, 68, 68, .15); color: #b91c1c; }
        @media (max-width: 1100px) {
            .ai-monitor-grid, .ai-model-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (max-width: 700px) {
            .ai-monitor-grid, .ai-model-grid { grid-template-columns: 1fr; }
        }
    </style>

    <div class="ai-monitor-wrap">
        <section class="ai-monitor-grid">
            <article class="ai-monitor-card">
                <h3>Active AI Status</h3>
                <strong>{{ $ai['active_status']['enabled'] ? 'Enabled' : 'Disabled' }}</strong>
                <span>{{ $ai['active_status']['provider'] }} · {{ $ai['active_status']['mode'] }}</span>
            </article>
            <article class="ai-monitor-card">
                <h3>AI Health</h3>
                <strong>{{ strtoupper($ai['analytics']['health']) }}</strong>
                <span>{{ $ai['health']['normal_models'] }} normal · {{ $ai['health']['slow_models'] }} slow</span>
            </article>
            <article class="ai-monitor-card">
                <h3>Fallback</h3>
                <strong>{{ number_format($ai['analytics']['fallback_count']) }}</strong>
                <span>{{ $ai['analytics']['recent_requests'] }} recent request</span>
            </article>
            <article class="ai-monitor-card">
                <h3>Security</h3>
                <strong>{{ strtoupper($security['summary']['status']) }}</strong>
                <span>{{ $security['summary']['login_anomalies'] }} login anomaly · {{ $security['summary']['temporary_blacklist'] }} blacklist</span>
            </article>
        </section>

        <section class="ai-monitor-section">
            <h2>AI Model Cards</h2>
            <p>OpenRouter free model priority. Model lambat diparkir 10 menit via cache Redis <code>slow_model:{model}</code>.</p>
            <div class="ai-model-grid">
                @foreach ($ai['model_cards'] as $model)
                    <article class="ai-monitor-card ai-model-card {{ $model['status'] }}">
                        <h3>Priority {{ $model['priority'] }}</h3>
                        <strong>{{ str($model['model'])->afterLast('/')->replace(':free', '') }}</strong>
                        <span>{{ $model['success_count'] }} success · {{ $model['fallback_count'] }} fallback · {{ $model['avg_latency_seconds'] ?: 0 }}s</span>
                    </article>
                @endforeach
            </div>
        </section>

        <section class="ai-monitor-section">
            <h2>AI Fallback History</h2>
            <p>Riwayat error, timeout, slow response, provider down, dan fallback dari <code>{{ $ai['health']['log_file'] }}</code>.</p>
            <table class="ai-monitor-table">
                <thead><tr><th>Time</th><th>Model</th><th>Event</th><th>Latency</th><th>Error</th></tr></thead>
                <tbody>
                    @forelse ($ai['fallback_history'] as $event)
                        <tr>
                            <td>{{ $event['time'] }}</td>
                            <td>{{ $event['model'] ? str($event['model'])->afterLast('/')->replace(':free', '') : '-' }}</td>
                            <td><span class="ai-pill {{ str_contains($event['event'], 'failed') ? 'critical' : 'warning' }}">{{ str($event['event'])->after('ai_order_parser.') }}</span></td>
                            <td>{{ $event['response_time_seconds'] }}s</td>
                            <td>{{ $event['error'] ?: '-' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5">Belum ada fallback/error pada log terbaru.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>

        <section class="ai-monitor-section">
            <h2>AI Security Monitoring</h2>
            <p>AI hanya analisa kandidat mencurigakan. Firewall utama tetap rule-based, throttling, auth, dan validasi backend.</p>
            <table class="ai-monitor-table">
                <thead><tr><th>Signal</th><th>Subject</th><th>Reason</th><th>Score</th></tr></thead>
                <tbody>
                    @php
                        $signals = collect($security['fake_order_candidates'])
                            ->merge(collect($security['login_anomalies'])->take(8)->map(fn ($item) => [
                                'type' => 'login_anomaly',
                                'customer' => $item['driver'],
                                'reason' => ($item['failed_attempts'] ?? 0).' gagal login · '.($item['ip'] ?? '-'),
                                'score' => min(100, ((int) ($item['failed_attempts'] ?? 0)) * 12),
                            ]));
                    @endphp
                    @forelse ($signals as $signal)
                        <tr>
                            <td><span class="ai-pill {{ ($signal['score'] ?? 0) >= 70 ? 'critical' : 'warning' }}">{{ str($signal['type'])->replace('_', ' ') }}</span></td>
                            <td>{{ $signal['customer'] ?? 'Unknown' }}</td>
                            <td>{{ $signal['reason'] ?? '-' }}</td>
                            <td>{{ $signal['score'] ?? 0 }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4">Security normal. Tidak ada kandidat spam, bot, fake order, atau login anomali.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>

        <section class="ai-monitor-section">
            <h2>Temporary Blacklist</h2>
            <p>Blacklist sementara dibuat dari repeated failure. Berlaku 15 menit dan hanya indikator monitoring.</p>
            <table class="ai-monitor-table">
                <thead><tr><th>IP</th><th>Reason</th><th>Score</th><th>Expires</th></tr></thead>
                <tbody>
                    @forelse ($security['temporary_blacklist'] as $item)
                        <tr>
                            <td>{{ $item['ip'] }}</td>
                            <td>{{ $item['reason'] }}</td>
                            <td>{{ $item['score'] }}</td>
                            <td>{{ $item['expires_in_minutes'] }} menit</td>
                        </tr>
                    @empty
                        <tr><td colspan="4">Belum ada temporary blacklist.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>
    </div>
</x-filament-panels::page>
