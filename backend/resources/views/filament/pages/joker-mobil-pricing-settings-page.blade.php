<x-filament-panels::page>
    <div class="space-y-6">
        <div class="rounded-xl border border-sky-500/20 bg-slate-900/80 p-5 text-sm text-slate-200">
            <div class="text-lg font-bold text-white">CMS Tarif Joker Mobil</div>
            <p class="mt-2 text-slate-300">
                Rumus, setoran manajemen, jasa tunggu/malam/helper, dan titik hitung jasa Joker Mobil diatur dari halaman ini.
                Pricing tetap membaca jarak maps/OSRM dan memakai pembulatan koma 5 turun, koma 6 naik.
            </p>
        </div>

        <form wire:submit="save" class="space-y-6">
            {{ $this->form }}

            <div class="flex justify-end">
                <x-filament::button type="submit" icon="heroicon-o-check">
                    Simpan CMS Joker Mobil
                </x-filament::button>
            </div>
        </form>
    </div>
</x-filament-panels::page>
