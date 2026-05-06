<div class="space-y-3">
    @forelse ($suspensions as $suspension)
        <div class="rounded-xl border border-gray-200 p-3 dark:border-gray-700">
            <div class="font-semibold">{{ $suspension->reason }}</div>
            <div class="text-sm text-gray-500">
                {{ $suspension->duration }} jam · {{ $suspension->status }} ·
                {{ optional($suspension->start_at)->format('d M Y H:i') }} -
                {{ optional($suspension->end_at)->format('d M Y H:i') }}
            </div>
        </div>
    @empty
        <div class="text-sm text-gray-500">Belum ada histori suspend.</div>
    @endforelse
</div>
