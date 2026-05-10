<x-filament-widgets::widget>
    @once
        <style>
            .jojo-monitor-grid{display:grid;gap:1rem}.jojo-monitor-card{background:linear-gradient(180deg,rgba(15,23,42,.96),rgba(2,6,23,.94));border:1px solid rgba(148,163,184,.16);border-radius:14px;padding:1rem}.jojo-monitor-head{align-items:center;display:flex;gap:.65rem;justify-content:space-between}.jojo-monitor-label{color:#e2e8f0;font-size:.86rem;font-weight:750}.jojo-monitor-detail{color:#94a3b8;font-size:.74rem;margin-top:.15rem}.jojo-monitor-value{color:#f8fafc;font-size:1.05rem;font-weight:850}.jojo-status-dot{border-radius:999px;height:.65rem;width:.65rem;flex:0 0 auto}.jojo-status-normal{background:#22c55e;box-shadow:0 0 0 4px rgba(34,197,94,.12)}.jojo-status-warning{background:#f59e0b;box-shadow:0 0 0 4px rgba(245,158,11,.12)}.jojo-status-critical{background:#ef4444;box-shadow:0 0 0 4px rgba(239,68,68,.12)}.jojo-progress{background:rgba(148,163,184,.14);border-radius:999px;height:.45rem;margin-top:.8rem;overflow:hidden}.jojo-progress span{display:block;height:100%;border-radius:999px}.jojo-progress .normal{background:#22c55e}.jojo-progress .warning{background:#f59e0b}.jojo-progress .critical{background:#ef4444}.jojo-endpoint-grid{display:grid;gap:.85rem}.jojo-endpoint-card{border:1px solid rgba(148,163,184,.14);border-radius:14px;background:linear-gradient(135deg,rgba(15,23,42,.72),rgba(30,41,59,.38));padding:.9rem}.jojo-endpoint-top{display:grid;grid-template-columns:auto minmax(0,1fr) auto;align-items:center;gap:.7rem}.jojo-endpoint-title{min-width:0}.jojo-endpoint-title strong{display:block;color:#f8fafc;font-size:.9rem}.jojo-endpoint-title span{display:block;color:#94a3b8;font-size:.72rem;line-height:1.35;margin-top:.15rem}.jojo-endpoint-value{text-align:right;color:#f8fafc;font-size:1rem;font-weight:850}.jojo-endpoint-value small{display:block;margin-top:.2rem;color:#94a3b8;font-size:.66rem;text-transform:uppercase;letter-spacing:.06em}.jojo-endpoint-detail{display:grid;gap:.55rem;margin-top:.85rem;padding-top:.85rem;border-top:1px dashed rgba(148,163,184,.16)}.jojo-endpoint-detail p{margin:0;color:#cbd5e1;font-size:.76rem;line-height:1.45}.jojo-endpoint-detail b{color:#93c5fd}.jojo-endpoint-action{border-radius:10px;padding:.62rem .7rem;color:#dbeafe;background:rgba(14,165,233,.10);font-size:.74rem;line-height:1.4}.jojo-endpoint-card.warning{border-color:rgba(245,158,11,.28);background:linear-gradient(135deg,rgba(120,53,15,.28),rgba(30,41,59,.36))}.jojo-endpoint-card.critical{border-color:rgba(239,68,68,.34);background:linear-gradient(135deg,rgba(127,29,29,.34),rgba(30,41,59,.36))}.jojo-endpoint-card.critical .jojo-endpoint-action{color:#fee2e2;background:rgba(239,68,68,.12)}.jojo-endpoint-card.warning .jojo-endpoint-action{color:#fef3c7;background:rgba(245,158,11,.12)}@media(min-width:768px){.jojo-monitor-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.jojo-endpoint-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(min-width:1280px){.jojo-monitor-grid{grid-template-columns:repeat(4,minmax(0,1fr))}}
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
            <x-slot name="description">Ringkasan kesehatan API. Angka dibaca dari health check database dan potongan log Laravel terbaru.</x-slot>

            <div class="jojo-endpoint-grid">
                @foreach ($endpoints as $endpoint)
                    <div class="jojo-endpoint-card {{ $endpoint['status'] }}">
                        <div class="jojo-endpoint-top">
                            <span class="jojo-status-dot jojo-status-{{ $endpoint['status'] }}"></span>
                            <div class="jojo-endpoint-title">
                                <strong>{{ $endpoint['label'] }}</strong>
                                <span>{{ $endpoint['detail'] ?? '-' }}</span>
                            </div>
                            <div class="jojo-endpoint-value">
                                {{ $endpoint['value'] }}
                                <small>{{ $endpoint['status_label'] ?? $endpoint['status'] }}</small>
                            </div>
                        </div>
                        <div class="jojo-endpoint-detail">
                            <p><b>Batas baca:</b> {{ $endpoint['threshold'] ?? '-' }}</p>
                            <div class="jojo-endpoint-action">{{ $endpoint['action'] ?? '-' }}</div>
                        </div>
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    </div>
</x-filament-widgets::widget>
