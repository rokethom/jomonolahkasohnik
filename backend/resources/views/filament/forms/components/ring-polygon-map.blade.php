@php
    $mapId = 'ring-polygon-map-'.\Illuminate\Support\Str::uuid();
    $statePolygon = is_callable($get ?? null) ? $get('polygon_coordinates') : null;
    $polygon = is_array($statePolygon) ? $statePolygon : (json_decode((string) $statePolygon, true) ?: []);
    $branchId = is_callable($get ?? null) ? $get('branch_id') : null;
    $branch = $branchId ? \App\Models\Branch::query()->find($branchId) : null;
    $lat = is_numeric($branch?->latitude) ? (float) $branch->latitude : -7.70630000;
    $lng = is_numeric($branch?->longitude) ? (float) $branch->longitude : 114.00980000;
    $googleMapsKey = app(\App\Services\SettingService::class)->get('google_maps_api_key');
@endphp

<div class="space-y-3">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="text-sm text-gray-600 dark:text-gray-300">
            Gambar batas Ring untuk cabang terpilih. Polygon Master Ring hanya berlaku untuk cabang tersebut.
        </div>
        <a
            id="{{ $mapId }}-open-google"
            href="https://www.google.com/maps?q={{ $lat }},{{ $lng }}"
            target="_blank"
            rel="noreferrer"
            class="rounded-lg bg-blue-600 px-3 py-2 text-sm font-semibold text-white shadow transition hover:bg-blue-700"
        >
            Buka Google Maps
        </a>
    </div>

    @if ($googleMapsKey)
        <div
            id="{{ $mapId }}"
            style="height: 420px; border-radius: 8px; overflow: hidden; border: 1px solid rgba(148, 163, 184, .35); display: grid; place-items: center; color: rgb(148, 163, 184); background: rgba(15, 23, 42, .22);"
        >
            Memuat Google Maps...
        </div>
    @else
        <div
            id="{{ $mapId }}"
            style="min-height: 170px; border-radius: 8px; border: 1px dashed rgba(148, 163, 184, .55); padding: 20px; display: grid; place-items: center; text-align: center; color: rgb(100, 116, 139);"
        >
            Google Maps API key belum aktif. Isi API key di System Settings, atau tempel JSON polygon manual di field koordinat.
        </div>
    @endif
</div>

<script>
    (() => {
        const googleMapsKey = @json($googleMapsKey);
        const fallbackLat = @json($lat);
        const fallbackLng = @json($lng);
        const fallbackPolygon = @json($polygon);

        window.jojoLoadGoogleMaps = window.jojoLoadGoogleMaps || ((apiKey) => {
            if (window.google?.maps?.Map) {
                return Promise.resolve(window.google.maps);
            }

            if (window.jojoGoogleMapsPromise) {
                return window.jojoGoogleMapsPromise;
            }

            const waitForGoogleMaps = (resolve, reject, startedAt = Date.now()) => {
                if (window.google?.maps?.Map) {
                    resolve(window.google.maps);
                    return;
                }

                if (Date.now() - startedAt > 12000) {
                    window.jojoGoogleMapsPromise = null;
                    reject(new Error('Google Maps timeout'));
                    return;
                }

                window.setTimeout(() => waitForGoogleMaps(resolve, reject, startedAt), 120);
            };

            window.jojoGoogleMapsPromise = new Promise((resolve, reject) => {
                const existingScript = document.getElementById('jojo-google-maps-sdk');

                if (existingScript) {
                    waitForGoogleMaps(resolve, reject);
                    return;
                }

                const script = document.createElement('script');
                script.id = 'jojo-google-maps-sdk';
                script.src = `https://maps.googleapis.com/maps/api/js?key=${encodeURIComponent(apiKey)}&libraries=drawing&loading=async`;
                script.async = true;
                script.defer = true;
                script.onload = () => waitForGoogleMaps(resolve, reject);
                script.onerror = () => {
                    window.jojoGoogleMapsPromise = null;
                    reject(new Error('Google Maps gagal dimuat'));
                };

                document.head.appendChild(script);
            });

            return window.jojoGoogleMapsPromise;
        });

        const init = async () => {
            const mapElement = document.getElementById(@json($mapId));

            if (!mapElement || mapElement.dataset.loaded === 'loading') {
                return;
            }

            if (mapElement.dataset.loaded === '1' && mapElement.querySelector('.gm-style')) {
                return;
            }

            mapElement.dataset.loaded = 'loading';

            const polygonInput = document.getElementById('ring_polygon_coordinates') || document.querySelector('textarea[name="data[polygon_coordinates]"]');
            const googleLink = document.getElementById(@json($mapId.'-open-google'));

            const setInputValue = (input, value) => {
                if (!input) return;

                input.value = value;
                input.dispatchEvent(new Event('input', { bubbles: true }));
                input.dispatchEvent(new Event('change', { bubbles: true }));
                input.dispatchEvent(new Event('blur', { bubbles: true }));
            };

            const polygonPathFromInput = () => {
                try {
                    const value = polygonInput?.value || JSON.stringify(fallbackPolygon || []);
                    const points = JSON.parse(value || '[]');

                    return Array.isArray(points)
                        ? points.map((point) => ({ lat: parseFloat(point.lat ?? point.latitude), lng: parseFloat(point.lng ?? point.longitude) })).filter((point) => Number.isFinite(point.lat) && Number.isFinite(point.lng))
                        : [];
                } catch (error) {
                    return [];
                }
            };

            if (!googleMapsKey) {
                mapElement.dataset.loaded = '1';
                return;
            }

            try {
                await window.jojoLoadGoogleMaps(googleMapsKey);
            } catch (error) {
                mapElement.dataset.loaded = '';
                mapElement.textContent = 'Google Maps gagal dimuat. Tempel JSON polygon manual atau cek API key.';
                return;
            }

            mapElement.innerHTML = '';
            mapElement.style.display = 'block';
            mapElement.dataset.loaded = '1';

            const map = new window.google.maps.Map(mapElement, {
                center: { lat: fallbackLat, lng: fallbackLng },
                zoom: 13,
                mapTypeControl: false,
                streetViewControl: false,
                fullscreenControl: true,
            });

            let polygon = new window.google.maps.Polygon({
                paths: polygonPathFromInput(),
                map,
                editable: true,
                draggable: true,
                strokeColor: '#f59e0b',
                strokeOpacity: 0.95,
                strokeWeight: 2,
                fillColor: '#f59e0b',
                fillOpacity: 0.2,
            });

            const syncPolygonInput = () => {
                if (!polygon || !polygonInput) return;

                const points = polygon.getPath().getArray().map((point) => ({
                    lat: Number(point.lat().toFixed(8)),
                    lng: Number(point.lng().toFixed(8)),
                }));

                setInputValue(polygonInput, JSON.stringify(points, null, 2));

                if (googleLink && points.length > 0) {
                    googleLink.href = `https://www.google.com/maps?q=${points[0].lat},${points[0].lng}`;
                }
            };

            const watchPolygon = () => {
                polygon.getPath().addListener('set_at', syncPolygonInput);
                polygon.getPath().addListener('insert_at', syncPolygonInput);
                polygon.getPath().addListener('remove_at', syncPolygonInput);
            };

            watchPolygon();

            if (window.google.maps.drawing) {
                const drawingManager = new window.google.maps.drawing.DrawingManager({
                    drawingControl: true,
                    drawingMode: polygon.getPath().getLength() >= 3 ? null : window.google.maps.drawing.OverlayType.POLYGON,
                    drawingControlOptions: {
                        position: window.google.maps.ControlPosition.TOP_CENTER,
                        drawingModes: [window.google.maps.drawing.OverlayType.POLYGON],
                    },
                    polygonOptions: {
                        editable: true,
                        draggable: true,
                        strokeColor: '#f59e0b',
                        fillColor: '#f59e0b',
                        fillOpacity: 0.2,
                    },
                });

                drawingManager.setMap(map);
                window.google.maps.event.addListener(drawingManager, 'polygoncomplete', (newPolygon) => {
                    polygon?.setMap(null);
                    polygon = newPolygon;
                    watchPolygon();
                    drawingManager.setDrawingMode(null);
                    syncPolygonInput();
                });
            }

            const path = polygonPathFromInput();
            if (path.length >= 3) {
                const bounds = new window.google.maps.LatLngBounds();
                path.forEach((point) => bounds.extend(point));
                map.fitBounds(bounds);
            }

            window.setTimeout(() => {
                window.google.maps.event.trigger(map, 'resize');
                map.setCenter(path[0] || { lat: fallbackLat, lng: fallbackLng });
            }, 300);
        };

        document.addEventListener('livewire:navigated', init);
        document.addEventListener('DOMContentLoaded', init);
        [150, 600, 1500].forEach((delay) => setTimeout(init, delay));
    })();
</script>
