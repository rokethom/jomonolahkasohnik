<div class="space-y-3">
    @forelse ($messages as $message)
        <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <div class="text-sm font-bold text-gray-950 dark:text-white">{{ $message['sender'] }}</div>
                    <div class="mt-1 text-xs font-semibold uppercase tracking-wide text-primary-600 dark:text-primary-400">{{ $message['role'] }}</div>
                </div>
                <div class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ $message['time'] }}</div>
            </div>
            <div class="mt-3 whitespace-pre-wrap text-sm leading-6 text-gray-700 dark:text-gray-200">{{ $message['message'] }}</div>
        </div>
    @empty
        <div class="rounded-2xl border border-dashed border-gray-300 bg-gray-50 p-6 text-center text-sm font-medium text-gray-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-400">
            Belum ada pesan internal di room ini.
        </div>
    @endforelse
</div>
