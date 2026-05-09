@php
    $mapId = 'branch-map-'.\Illuminate\Support\Str::uuid();
    $lat = -7.70630000;
    $lng = 114.00980000;
    $googleMapsKey = app(\App\Services\SettingService::class)->get('google_maps_api_key');
@endphp

<div class="space-y-3">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="text-sm text-gray-600 dark:text-gray-300">
            Ambil latitude dan longitude hanya dari Google Maps. Klik map, geser marker, atau gunakan lokasi perangkat.
        </div>
        <div class="flex flex-wrap gap-2">
            <a
                id="{{ $mapId }}-open-google"
                href="https://www.google.com/maps?q={{ $lat }},{{ $lng }}"
                target="_blank"
                rel="noreferrer"
                class="rounded-lg bg-blue-600 px-3 py-2 text-sm font-semibold text-white shadow transition hover:bg-blue-700"
            >
                Buka Google Maps
            </a>
            <button
                type="button"
                id="{{ $mapId }}-current-location"
                class="rounded-lg bg-amber-500 px-3 py-2 text-sm font-semibold text-white shadow transition hover:bg-amber-600"
            >
                Gunakan lokasi saya
            </button>
        </div>
    </div>

    @if ($googleMapsKey)
        <div
            id="{{ $mapId }}"
            style="height: 360px; border-radius: 8px; overflow: hidden; border: 1px solid rgba(148, 163, 184, .35);"
        ></div>
    @else
        <div
            id="{{ $mapId }}"
            style="min-height: 160px; border-radius: 8px; border: 1px dashed rgba(148, 163, 184, .55); padding: 20px; display: grid; place-items: center; text-align: center; color: rgb(100, 116, 139);"
        >
            Google Maps API key belum aktif. Isi API key di System Settings untuk picker interaktif, atau ambil koordinat dari tombol Buka Google Maps.
        </div>
    @endif
</div>

<script>
    (() => {
        const googleMapsKey = @json($googleMapsKey);
        const fallbackLat = @json($lat);
        const fallbackLng = @json($lng);

        window.jojoLoadGoogleMaps = window.jojoLoadGoogleMaps || ((apiKey) => {
            if (window.google?.maps) {
                return Promise.resolve(window.google.maps);
            }

            if (window.jojoGoogleMapsPromise) {
                return window.jojoGoogleMapsPromise;
            }

            window.jojoGoogleMapsPromise = new Promise((resolve, reject) => {
                const script = document.createElement('script');
                script.src = `https://maps.googleapis.com/maps/api/js?key=${encodeURIComponent(apiKey)}`;
                script.async = true;
                script.defer = true;
                script.onload = () => resolve(window.google.maps);
                script.onerror = () => reject(new Error('Google Maps gagal dimuat'));
                document.head.appendChild(script);
            });

            return window.jojoGoogleMapsPromise;
        });

        const init = async () => {
            const mapElement = document.getElementById(@json($mapId));

            if (!mapElement || mapElement.dataset.loaded) {
                return;
            }

            mapElement.dataset.loaded = 'loading';

            const latInput = document.getElementById('branch_latitude') || document.querySelector('input[name="data[latitude]"]');
            const lngInput = document.getElementById('branch_longitude') || document.querySelector('input[name="data[longitude]"]');
            const locationButton = document.getElementById(@json($mapId.'-current-location'));
            const googleLink = document.getElementById(@json($mapId.'-open-google'));
            const initialLat = parseFloat(latInput?.value || fallbackLat);
            const initialLng = parseFloat(lngInput?.value || fallbackLng);

            const setInputValue = (input, value) => {
                if (!input) return;

                input.value = value;
                input.dispatchEvent(new Event('input', { bubbles: true }));
                input.dispatchEvent(new Event('change', { bubbles: true }));
                input.dispatchEvent(new Event('blur', { bubbles: true }));
            };

            const updateGoogleLink = (lat, lng) => {
                if (!googleLink) return;

                googleLink.href = `https://www.google.com/maps?q=${lat.toFixed(8)},${lng.toFixed(8)}`;
            };

            let map = null;
            let marker = null;

            const syncInputs = (lat, lng, center = false) => {
                const fixedLat = lat.toFixed(8);
                const fixedLng = lng.toFixed(8);

                setInputValue(latInput, fixedLat);
                setInputValue(lngInput, fixedLng);
                updateGoogleLink(lat, lng);

                if (marker) {
                    marker.setPosition({ lat, lng });
                }

                if (map && center) {
                    map.setCenter({ lat, lng });
                    map.setZoom(Math.max(map.getZoom() || 14, 15));
                }
            };

            updateGoogleLink(initialLat, initialLng);

            locationButton?.addEventListener('click', () => {
                if (!navigator.geolocation) return;

                navigator.geolocation.getCurrentPosition((position) => {
                    syncInputs(position.coords.latitude, position.coords.longitude, true);
                });
            });

            [latInput, lngInput].forEach((input) => {
                input?.addEventListener('input', () => {
                    const lat = parseFloat(latInput?.value);
                    const lng = parseFloat(lngInput?.value);

                    if (!Number.isFinite(lat) || !Number.isFinite(lng)) return;

                    updateGoogleLink(lat, lng);
                    marker?.setPosition({ lat, lng });
                    map?.setCenter({ lat, lng });
                });
            });

            if (!googleMapsKey) {
                mapElement.dataset.loaded = '1';
                return;
            }

            try {
                await window.jojoLoadGoogleMaps(googleMapsKey);
            } catch (error) {
                mapElement.textContent = 'Google Maps gagal dimuat. Gunakan tombol Buka Google Maps untuk mengambil koordinat.';
                return;
            }

            if (!window.google?.maps) {
                return;
            }

            mapElement.dataset.loaded = '1';
            map = new window.google.maps.Map(mapElement, {
                center: { lat: initialLat, lng: initialLng },
                zoom: 14,
                mapTypeControl: false,
                streetViewControl: false,
                fullscreenControl: true,
            });
            marker = new window.google.maps.Marker({
                position: { lat: initialLat, lng: initialLng },
                map,
                draggable: true,
                title: 'Titik cabang',
            });

            map.addListener('click', (event) => {
                if (!event.latLng) return;

                syncInputs(event.latLng.lat(), event.latLng.lng());
            });

            marker.addListener('dragend', () => {
                const position = marker.getPosition();
                if (!position) return;

                syncInputs(position.lat(), position.lng());
            });
        };

        document.addEventListener('livewire:navigated', init);
        document.addEventListener('DOMContentLoaded', init);
        setTimeout(init, 250);
    })();
</script>
