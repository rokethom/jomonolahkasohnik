@php
    $mapId = 'branch-map-'.\Illuminate\Support\Str::uuid();
    $lat = -6.20000000;
    $lng = 106.81666600;
@endphp

@once
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
@endonce

<div class="space-y-3">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="text-sm text-gray-600 dark:text-gray-300">
            Klik map atau geser marker untuk mengambil latitude dan longitude.
        </div>
        <button
            type="button"
            id="{{ $mapId }}-current-location"
            class="rounded-lg bg-amber-500 px-3 py-2 text-sm font-semibold text-white shadow transition hover:bg-amber-600"
        >
            Gunakan lokasi saya
        </button>
    </div>

    <div
        id="{{ $mapId }}"
        style="height: 360px; border-radius: 8px; overflow: hidden; border: 1px solid rgba(148, 163, 184, .35);"
    ></div>
</div>

<script>
    (() => {
        const init = () => {
            const mapElement = document.getElementById(@json($mapId));

            if (!mapElement || mapElement.dataset.loaded === '1' || typeof L === 'undefined') {
                return;
            }

            mapElement.dataset.loaded = '1';

            const latInput = document.getElementById('branch_latitude') || document.querySelector('input[name="data[latitude]"]');
            const lngInput = document.getElementById('branch_longitude') || document.querySelector('input[name="data[longitude]"]');
            const locationButton = document.getElementById(@json($mapId.'-current-location'));
            const initialLat = parseFloat(latInput?.value || @json($lat));
            const initialLng = parseFloat(lngInput?.value || @json($lng));
            const map = L.map(mapElement).setView([initialLat, initialLng], 14);

            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; OpenStreetMap',
            }).addTo(map);

            const marker = L.marker([initialLat, initialLng], { draggable: true }).addTo(map);

            const setInputValue = (input, value) => {
                if (!input) return;

                input.value = value;
                input.dispatchEvent(new Event('input', { bubbles: true }));
                input.dispatchEvent(new Event('change', { bubbles: true }));
                input.dispatchEvent(new Event('blur', { bubbles: true }));
            };

            const syncInputs = (lat, lng, center = false) => {
                const fixedLat = lat.toFixed(8);
                const fixedLng = lng.toFixed(8);

                setInputValue(latInput, fixedLat);
                setInputValue(lngInput, fixedLng);
                marker.setLatLng([lat, lng]);

                if (center) {
                    map.setView([lat, lng], Math.max(map.getZoom(), 15));
                }
            };

            marker.on('dragend', () => {
                const point = marker.getLatLng();
                syncInputs(point.lat, point.lng);
            });

            map.on('click', (event) => syncInputs(event.latlng.lat, event.latlng.lng));

            [latInput, lngInput].forEach((input) => {
                input?.addEventListener('change', () => {
                    const lat = parseFloat(latInput?.value);
                    const lng = parseFloat(lngInput?.value);

                    if (Number.isFinite(lat) && Number.isFinite(lng)) {
                        marker.setLatLng([lat, lng]);
                        map.setView([lat, lng], map.getZoom());
                    }
                });
            });

            locationButton?.addEventListener('click', () => {
                if (!navigator.geolocation) return;

                navigator.geolocation.getCurrentPosition((position) => {
                    syncInputs(position.coords.latitude, position.coords.longitude, true);
                });
            });

            setTimeout(() => map.invalidateSize(), 250);
        };

        document.addEventListener('livewire:navigated', init);
        document.addEventListener('DOMContentLoaded', init);
        setTimeout(init, 250);
    })();
</script>
