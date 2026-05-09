@php
    $mapId = 'geofence-map-'.\Illuminate\Support\Str::uuid();
    $lat = -7.70630000;
    $lng = 114.00980000;
    $radius = 5000;
@endphp

@once
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
@endonce

<div class="space-y-3">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="text-sm text-gray-600 dark:text-gray-300">
            Klik map untuk mengambil titik pusat geofence. Radius mengikuti input meter.
        </div>
        <button
            type="button"
            id="{{ $mapId }}-current-location"
            class="rounded-lg bg-emerald-600 px-3 py-2 text-sm font-semibold text-white shadow transition hover:bg-emerald-700"
        >
            Gunakan lokasi saya
        </button>
    </div>

    <div
        id="{{ $mapId }}"
        style="height: 380px; border-radius: 8px; overflow: hidden; border: 1px solid rgba(148, 163, 184, .35);"
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

            const latInput = document.getElementById('geofence_center_latitude') || document.querySelector('input[name="data[center_latitude]"]');
            const lngInput = document.getElementById('geofence_center_longitude') || document.querySelector('input[name="data[center_longitude]"]');
            const radiusInput = document.getElementById('geofence_radius_meters') || document.querySelector('input[name="data[radius_meters]"]');
            const locationButton = document.getElementById(@json($mapId.'-current-location'));
            const initialLat = parseFloat(latInput?.value || @json($lat));
            const initialLng = parseFloat(lngInput?.value || @json($lng));
            const initialRadius = parseInt(radiusInput?.value || @json($radius), 10);
            const map = L.map(mapElement).setView([initialLat, initialLng], 14);

            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; OpenStreetMap',
            }).addTo(map);

            const marker = L.marker([initialLat, initialLng], { draggable: true }).addTo(map);
            const circle = L.circle([initialLat, initialLng], {
                radius: initialRadius,
                color: '#16a34a',
                fillColor: '#22c55e',
                fillOpacity: 0.18,
            }).addTo(map);

            const setInputValue = (input, value) => {
                if (!input) return;

                input.value = value;
                input.dispatchEvent(new Event('input', { bubbles: true }));
                input.dispatchEvent(new Event('change', { bubbles: true }));
                input.dispatchEvent(new Event('blur', { bubbles: true }));
            };

            const sync = (lat, lng, center = false) => {
                marker.setLatLng([lat, lng]);
                circle.setLatLng([lat, lng]);

                setInputValue(latInput, lat.toFixed(8));
                setInputValue(lngInput, lng.toFixed(8));

                if (center) {
                    map.setView([lat, lng], Math.max(map.getZoom(), 15));
                }
            };

            marker.on('dragend', () => {
                const point = marker.getLatLng();
                sync(point.lat, point.lng);
            });

            map.on('click', (event) => sync(event.latlng.lat, event.latlng.lng));

            const refreshFromInputs = (center = false) => {
                const lat = parseFloat(latInput?.value);
                const lng = parseFloat(lngInput?.value);

                if (Number.isFinite(lat) && Number.isFinite(lng)) {
                    marker.setLatLng([lat, lng]);
                    circle.setLatLng([lat, lng]);
                    map.setView([lat, lng], center ? Math.max(map.getZoom(), 15) : map.getZoom());
                }
            };

            [latInput, lngInput].forEach((input) => {
                input?.addEventListener('input', () => refreshFromInputs(false));
                input?.addEventListener('change', () => refreshFromInputs(false));
                input?.addEventListener('blur', () => {
                    const lat = parseFloat(latInput?.value);
                    const lng = parseFloat(lngInput?.value);

                    if (Number.isFinite(lat) && Number.isFinite(lng)) {
                        refreshFromInputs(true);
                    }
                });
            });

            radiusInput?.addEventListener('input', () => {
                circle.setRadius(parseInt(radiusInput.value || '1', 10));
            });

            locationButton?.addEventListener('click', () => {
                if (!navigator.geolocation) return;

                navigator.geolocation.getCurrentPosition((position) => {
                    sync(position.coords.latitude, position.coords.longitude, true);
                });
            });

            setTimeout(() => {
                map.invalidateSize();
                map.fitBounds(circle.getBounds(), { padding: [24, 24], maxZoom: 16 });
            }, 250);
        };

        document.addEventListener('livewire:navigated', init);
        document.addEventListener('DOMContentLoaded', init);
        setTimeout(init, 250);
    })();
</script>
