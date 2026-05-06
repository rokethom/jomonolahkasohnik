<x-filament-widgets::widget>
    @once
        <style>
            .jojo-timeline{display:grid;gap:.85rem}.jojo-timeline-item{align-items:flex-start;background:rgba(15,23,42,.78);border:1px solid rgba(148,163,184,.14);border-radius:14px;display:grid;gap:.8rem;grid-template-columns:auto 1fr auto;padding:.9rem 1rem}.jojo-timeline-dot{border-radius:999px;height:.75rem;margin-top:.35rem;width:.75rem}.jojo-timeline-title{color:#f8fafc;font-weight:800}.jojo-timeline-sub{color:#94a3b8;font-size:.78rem;margin-top:.12rem}.jojo-timeline-time{color:#cbd5e1;font-size:.78rem;font-weight:700;white-space:nowrap}
        </style>
    @endonce

    <x-filament::section>
        <x-slot name="heading">Modern Timeline</x-slot>
        <x-slot name="description">History login/admin action/reset auth/suspend/error dari audit log.</x-slot>

        <div class="jojo-timeline">
            @forelse ($items as $item)
                <div class="jojo-timeline-item">
                    <span class="jojo-timeline-dot jojo-status-{{ $item['status'] }}"></span>
                    <div>
                        <div class="jojo-timeline-title">{{ $item['title'] }}</div>
                        <div class="jojo-timeline-sub">{{ $item['subtitle'] ?: '-' }}</div>
                    </div>
                    <div class="jojo-timeline-time">{{ $item['time'] }}</div>
                </div>
            @empty
                <div class="rounded-xl border border-dashed border-slate-700 p-5 text-center text-sm text-slate-400">Belum ada history produksi.</div>
            @endforelse
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
