<x-filament-widgets::widget>
    @once
        <style>
            .jojo-monitor-grid{display:grid;gap:1rem}.jojo-monitor-card{background:linear-gradient(180deg,rgba(15,23,42,.96),rgba(2,6,23,.94));border:1px solid rgba(148,163,184,.16);border-radius:14px;padding:1rem}.jojo-monitor-head{align-items:center;display:flex;gap:.65rem;justify-content:space-between}.jojo-monitor-label{color:#e2e8f0;font-size:.86rem;font-weight:750}.jojo-monitor-detail{color:#94a3b8;font-size:.74rem;margin-top:.15rem}.jojo-monitor-value{color:#f8fafc;font-size:1.05rem;font-weight:850}.jojo-status-dot{border-radius:999px;height:.65rem;width:.65rem}.jojo-status-normal{background:#22c55e;box-shadow:0 0 0 4px rgba(34,197,94,.12)}.jojo-status-warning{background:#f59e0b;box-shadow:0 0 0 4px rgba(245,158,11,.12)}.jojo-status-critical{background:#ef4444;box-shadow:0 0 0 4px rgba(239,68,68,.12)}.jojo-progress{background:rgba(148,163,184,.14);border-radius:999px;height:.45rem;margin-top:.8rem;overflow:hidden}.jojo-progress span{display:block;height:100%;border-radius:999px}.jojo-progress .normal{background:#22c55e}.jojo-progress .warning{background:#f59e0b}.jojo-progress .critical{background:#ef4444}.jojo-endpoint-row{align-items:center;border-top:1px solid rgba(148,163,184,.12);display:flex;justify-content:space-between;padding:.72rem 0}.jojo-endpoint-row:first-child{border-top:0}@media(min-width:768px){.jojo-monitor-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(min-width:1280px){.jojo-monitor-grid{grid-template-columns:repeat(4,minmax(0,1fr))}}
        </style>
    @endonce

    <div class="space-y-4">
        <x-filament::section>
            <x-slot name="heading">Production Monitoring</x-slot>
            <x-slot name="description">Realtime refresh, progress bar, dan color indicator untuk VPS.</x-slot>

            <div class="jojo-monitor-grid">
                @foreach ($metrics as $metric)
                    <div class="jojo-monitor-card">
                        <div class="jojo-monitor-head">
                            <div>
                                <div class="jojo-monitor-label">{{ $metric['label'] }}</div>
                                <div class="jojo-monitor-detail">{{ $metric['detail'] }}</div>
                            </div>
                            <span class="jojo-status-dot jojo-status-{{ $metric['status'] }}"></span>
                        </div>
                        <div class="jojo-monitor-value mt-3">{{ $metric['value'] ?? '-' }}{{ $metric['suffix'] }}</div>
                        <div class="jojo-progress"><span class="{{ $metric['status'] }}" style="width: {{ is_numeric($metric['value']) ? min(100, max(0, (int) $metric['value'])) : 0 }}%"></span></div>
                    </div>
                @endforeach
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Monitor Endpoint</x-slot>
            <x-slot name="description">API response time, uptime, failed request, 4xx, dan 5xx count.</x-slot>

            <div>
                @foreach ($endpoints as $endpoint)
                    <div class="jojo-endpoint-row">
                        <div class="flex items-center gap-3">
                            <span class="jojo-status-dot jojo-status-{{ $endpoint['status'] }}"></span>
                            <span class="text-sm font-semibold text-slate-100">{{ $endpoint['label'] }}</span>
                        </div>
                        <span class="text-sm font-bold text-white">{{ $endpoint['value'] }}</span>
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    </div>
</x-filament-widgets::widget>
