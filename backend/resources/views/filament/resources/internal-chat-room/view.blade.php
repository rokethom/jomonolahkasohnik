<x-filament-panels::page>
    @php
        $room = $this->getRecord();
        $messages = $this->latestMessages();
    @endphp

    <div class="space-y-6">
        <x-filament::section>
            <x-slot name="heading">
                Room
            </x-slot>

            <div class="grid gap-4 md:grid-cols-3">
                <div class="rounded-2xl border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-900">
                    <div class="text-xs font-bold uppercase tracking-wide text-gray-500">Nama</div>
                    <div class="mt-2 text-base font-bold text-gray-950 dark:text-white">{{ $room->name }}</div>
                </div>
                <div class="rounded-2xl border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-900">
                    <div class="text-xs font-bold uppercase tracking-wide text-gray-500">Tipe</div>
                    <div class="mt-2">
                        <span class="rounded-full bg-primary-100 px-3 py-1 text-xs font-bold text-primary-700 dark:bg-primary-500/20 dark:text-primary-200">
                            {{ strtoupper($room->type) }}
                        </span>
                    </div>
                </div>
                <div class="rounded-2xl border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-900">
                    <div class="text-xs font-bold uppercase tracking-wide text-gray-500">Status</div>
                    <div class="mt-2 text-base font-bold {{ $room->is_active ? 'text-success-600' : 'text-danger-600' }}">
                        {{ $room->is_active ? 'Active' : 'Inactive' }}
                    </div>
                </div>
                <div class="rounded-2xl border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-900">
                    <div class="text-xs font-bold uppercase tracking-wide text-gray-500">Branch</div>
                    <div class="mt-2 text-base font-bold text-gray-950 dark:text-white">{{ $room->branch?->name ?? 'Global' }}</div>
                </div>
                <div class="rounded-2xl border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-900">
                    <div class="text-xs font-bold uppercase tracking-wide text-gray-500">Area</div>
                    <div class="mt-2 text-base font-bold text-gray-950 dark:text-white">{{ $room->branch?->area ?? '-' }}</div>
                </div>
                <div class="rounded-2xl border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-900">
                    <div class="text-xs font-bold uppercase tracking-wide text-gray-500">Update</div>
                    <div class="mt-2 text-base font-bold text-gray-950 dark:text-white">{{ $room->updated_at?->format('d M Y H:i') }}</div>
                </div>
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">
                Latest Messages
            </x-slot>

            @include('filament.resources.internal-chat-room.messages', ['messages' => $messages])
        </x-filament::section>
    </div>
</x-filament-panels::page>
