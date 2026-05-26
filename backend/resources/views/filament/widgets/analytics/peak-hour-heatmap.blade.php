<x-filament-widgets::widget>
    <x-filament::section heading="Heatmap Order per Jam">
        <div style="overflow-x:auto;">
            <div style="min-width:760px;display:grid;gap:.45rem;">
                <div style="display:grid;grid-template-columns:72px repeat(24,minmax(22px,1fr));gap:.3rem;color:#94a3b8;font-size:.68rem;">
                    <span></span>
                    @foreach (range(0, 23) as $hour)
                        <span>{{ str_pad((string) $hour, 2, '0', STR_PAD_LEFT) }}</span>
                    @endforeach
                </div>
                @forelse ($daily as $date => $hours)
                    <div style="display:grid;grid-template-columns:72px repeat(24,minmax(22px,1fr));gap:.3rem;align-items:center;">
                        <strong style="font-size:.72rem;color:#cbd5e1;">{{ $date }}</strong>
                        @foreach ($hours as $orders)
                            @php $opacity = max(.08, $orders / $max); @endphp
                            <span title="{{ $orders }} order" style="height:25px;border-radius:4px;background:rgba(56,189,248,{{ $opacity }});border:1px solid rgba(148,163,184,.12);"></span>
                        @endforeach
                    </div>
                @empty
                    <p style="color:#94a3b8;">Belum ada data agregasi pada periode ini.</p>
                @endforelse
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
