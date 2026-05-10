<x-filament-panels::page>
    <form wire:submit="testPricing" class="space-y-6">
        {{ $this->form }}

        <x-filament::button type="submit" icon="heroicon-o-calculator">
            Test zone pricing
        </x-filament::button>
    </form>

    @if($result)
        @php
            $quote = $result['quote'] ?? [];
            $pickup = $result['pickup'] ?? null;
            $destination = $result['destination'] ?? null;
        @endphp

        <div class="mt-6 grid gap-4 lg:grid-cols-3">
            <x-filament::section>
                <x-slot name="heading">Pickup</x-slot>
                <div class="space-y-2 text-sm">
                    <div><b>Zona:</b> {{ data_get($pickup, 'area.name') ?? 'Tidak masuk geofence' }}</div>
                    <div><b>Cabang:</b> {{ data_get($pickup, 'branch.display_name') ?? '-' }}</div>
                    <div><b>Jarak pusat:</b> {{ data_get($pickup, 'distance_meters') ? number_format(data_get($pickup, 'distance_meters'), 0, ',', '.') . ' m' : '-' }}</div>
                </div>
            </x-filament::section>

            <x-filament::section>
                <x-slot name="heading">Tujuan</x-slot>
                <div class="space-y-2 text-sm">
                    <div><b>Zona:</b> {{ data_get($destination, 'area.name') ?? 'Tidak masuk geofence' }}</div>
                    <div><b>Cabang:</b> {{ data_get($destination, 'branch.display_name') ?? '-' }}</div>
                    <div><b>Jarak pusat:</b> {{ data_get($destination, 'distance_meters') ? number_format(data_get($destination, 'distance_meters'), 0, ',', '.') . ' m' : '-' }}</div>
                </div>
            </x-filament::section>

            <x-filament::section>
                <x-slot name="heading">Hasil tarif</x-slot>
                <div class="space-y-2 text-sm">
                    <div><b>Cabang order:</b> {{ $result['branch_id'] ?? '-' }}</div>
                    <div><b>Jarak:</b> {{ data_get($quote, 'distance_km') }} KM</div>
                    <div><b>Tarif:</b> Rp {{ number_format((int) data_get($quote, 'tarif', 0), 0, ',', '.') }}</div>
                    <div><b>Service fee:</b> Rp {{ number_format((int) data_get($quote, 'service_fee', 0), 0, ',', '.') }}</div>
                    <div><b>Total:</b> Rp {{ number_format((int) data_get($quote, 'final_price', 0), 0, ',', '.') }}</div>
                    <div><b>Source:</b> {{ data_get($quote, 'tarif_source', 'default') }}</div>
                    <div><b>Rule zona:</b> {{ data_get($quote, 'zone_pricing_rule_name', '-') }}</div>
                </div>
            </x-filament::section>
        </div>
    @endif
</x-filament-panels::page>
