<x-filament-panels::page>
    <div class="space-y-6">
        <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            {{ $this->form }}
        </div>

        <div class="flex gap-3">
            <x-filament::button wire:click="exportCsv" icon="heroicon-o-arrow-down-tray">
                Export CSV
            </x-filament::button>
            <x-filament::button wire:click="exportExcel" color="success" icon="heroicon-o-table-cells">
                Export Excel
            </x-filament::button>
        </div>

        <div class="overflow-x-auto rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <table class="min-w-[1420px] w-full border-collapse text-xs">
                <thead>
                    <tr>
                        @foreach ($this->headers() as $index => $header)
                            <th @class([
                                'border border-black px-2 py-2 text-center font-bold text-black',
                                'bg-[#ffc000]' => $index === 8,
                                'bg-[#ffff00]' => in_array($index, [11, 13], true),
                                'bg-[#c6d9f1]' => ! in_array($index, [8, 11, 13], true),
                            ])>
                                {{ $header }}
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->rows() as $row)
                        <tr>
                            <td class="border border-black bg-white px-2 py-1 text-black">{{ $row['driver'] }}</td>
                            <td class="border border-black bg-white px-2 py-1 text-black">{{ $row['area'] }}</td>
                            <td class="border border-black bg-white px-2 py-1 text-right text-black">{{ $row['orders_count'] }}</td>
                            <td class="border border-black bg-white px-2 py-1 text-right text-black">{{ number_format($row['base_service_omset'], 0, '.', ',') }}</td>
                            <td class="border border-black bg-white px-2 py-1 text-right text-black">{{ number_format($row['base_service_deposit'], 0, '.', ',') }}</td>
                            <td class="border border-black bg-white px-2 py-1 text-right text-black">{{ $row['previous_bill'] ? number_format($row['previous_bill'], 0, '.', ',') : '-' }}</td>
                            <td class="border border-black bg-white px-2 py-1 text-right text-black">{{ $row['bpjs_jht'] ? number_format($row['bpjs_jht'], 0, '.', ',') : '-' }}</td>
                            <td class="border border-black bg-white px-2 py-1 text-right text-black">{{ $row['bpjs'] ? number_format($row['bpjs'], 0, '.', ',') : '-' }}</td>
                            <td class="border border-black bg-white px-2 py-1 text-right text-black">{{ $row['previous_cashback_reward'] ? number_format($row['previous_cashback_reward'], 0, '.', ',') : '-' }}</td>
                            <td class="border border-black bg-white px-2 py-1 text-right text-black">{{ number_format($row['bill_before_bansos'], 0, '.', ',') }}</td>
                            <td class="border border-black bg-white px-2 py-1 text-right text-black">{{ $row['bansos'] ? number_format($row['bansos'], 0, '.', ',') : '-' }}</td>
                            <td class="border border-black bg-[#ffff00] px-2 py-1 text-right text-black">{{ number_format($row['total_bill'], 0, '.', ',') }}</td>
                            <td class="border border-black bg-white px-2 py-1 text-right text-black">{{ $row['paid_amount'] ? number_format($row['paid_amount'], 0, '.', ',') : '-' }}</td>
                            <td class="border border-black bg-[#ffff00] px-2 py-1 text-right text-black">{{ $row['remaining_bill'] ? number_format($row['remaining_bill'], 0, '.', ',') : '-' }}</td>
                            <td class="border border-black bg-white px-2 py-1 text-black">{{ $row['paid_at'] }}</td>
                            <td class="border border-black bg-white px-2 py-1 text-right text-black">{{ number_format($row['next_cashback'], 0, '.', ',') }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="16" class="border border-black px-4 py-8 text-center text-gray-500">Belum ada data setoran pada periode ini.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-filament-panels::page>
