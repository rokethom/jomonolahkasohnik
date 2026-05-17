<x-filament-panels::page>
    <form wire:submit="save" class="space-y-6">
        {{ $this->form }}

        <x-filament::button type="submit" icon="heroicon-o-check">
            Simpan Live Edit Harga
        </x-filament::button>
    </form>

    <section class="mt-8 rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
        <div class="flex flex-col gap-3 border-b border-gray-200 p-4 dark:border-gray-800 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 class="text-lg font-bold text-gray-950 dark:text-white">History Audit Live Edit Harga</h2>
                <p class="text-sm text-gray-500 dark:text-gray-400">Riwayat koreksi harga untuk evaluasi performa operator dan eksekutor.</p>
            </div>
            <x-filament::button wire:click="downloadAuditXls" type="button" icon="heroicon-o-arrow-down-tray" color="gray">
                Download XLS
            </x-filament::button>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-800">
                <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:bg-gray-950 dark:text-gray-400">
                    <tr>
                        @foreach ($this->auditHeaders() as $header)
                            <th class="whitespace-nowrap px-4 py-3">{{ $header }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($this->auditRows as $row)
                        <tr class="text-gray-700 dark:text-gray-200">
                            @foreach ($this->auditRow($row) as $cell)
                                <td class="whitespace-nowrap px-4 py-3">{{ is_numeric($cell) ? number_format((int) $cell, 0, ',', '.') : $cell }}</td>
                            @endforeach
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ count($this->auditHeaders()) }}" class="px-4 py-8 text-center text-gray-500 dark:text-gray-400">
                                Belum ada audit live edit harga.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</x-filament-panels::page>
