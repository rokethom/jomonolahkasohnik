@php
    $mapId = 'geofence-map-'.\Illuminate\Support\Str::uuid();
    $stateLat = is_callable($get ?? null) ? $get('center_latitude') : null;
    $stateLng = is_callable($get ?? null) ? $get('center_longitude') : null;
    $stateRadius = is_callable($get ?? null) ? $get('radius_meters') : null;
    $stateShape = is_callable($get ?? null) ? $get('shape_type') : 'circle';
    $statePolygon = is_callable($get ?? null) ? $get('polygon_coordinates') : null;
    $lat = is_numeric($stateLat) ? (float) $stateLat : -7.70630000;
    $lng = is_numeric($stateLng) ? (float) $stateLng : 114.00980000;
    $radius = is_numeric($stateRadius) ? max(1, (int) $stateRadius) : 5000;
    $polygon = is_array($statePolygon) ? $statePolygon : (json_decode((string) $statePolygon, true) ?: []);
    $googleMapsKey = app(\App\Services\SettingService::class)->get('google_maps_api_key');
@endphp

<div class="space-y-3">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="text-sm text-gray-600 dark:text-gray-300">
            Ambil titik pusat dan polygon dari Google Maps. Radius/polygon utama tetap disimpan di Geofence Area.
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
                class="rounded-lg bg-emerald-600 px-3 py-2 text-sm font-semibold text-white shadow transition hover:bg-emerald-700"
            >
                Gunakan lokasi saya
            </button>
        </div>
    </div>

    @if ($googleMapsKey)
        <div
            id="{{ $mapId }}"
            style="height: 380px; border-radius: 8px; overflow: hidden; border: 1px solid rgba(148, 163, 184, .35); display: grid; place-items: center; color: rgb(148, 163, 184); background: rgba(15, 23, 42, .22);"
        >
            Memuat Google Maps...
        </div>
    @else
        <div
            id="{{ $mapId }}"
            style="min-height: 170px; border-radius: 8px; border: 1px dashed rgba(148, 163, 184, .55); padding: 20px; display: grid; place-items: center; text-align: center; color: rgb(100, 116, 139);"
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
        const fallbackRadius = @json($radius);
        const fallbackShape = @json($stateShape ?: 'circle');
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

            const latInput = document.getElementById('geofence_center_latitude') || document.querySelector('input[name="data[center_latitude]"]');
            const lngInput = document.getElementById('geofence_center_longitude') || document.querySelector('input[name="data[center_longitude]"]');
            const radiusInput = document.getElementById('geofence_radius_meters') || document.querySelector('input[name="data[radius_meters]"]');
            const shapeInput = document.querySelector('select[name="data[shape_type]"], input[name="data[shape_type]"]');
            const polygonInput = document.getElementById('geofence_polygon_coordinates') || document.querySelector('textarea[name="data[polygon_coordinates]"]');
            const locationButton = document.getElementById(@json($mapId.'-current-location'));
            const googleLink = document.getElementById(@json($mapId.'-open-google'));
            const initialLat = parseFloat(latInput?.value || fallbackLat);
            const initialLng = parseFloat(lngInput?.value || fallbackLng);
            const initialRadius = parseInt(radiusInput?.value || fallbackRadius, 10);

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
            let circle = null;
            let polygon = null;
            let drawingManager = null;

            const sync = (lat, lng, center = false) => {
                setInputValue(latInput, lat.toFixed(8));
                setInputValue(lngInput, lng.toFixed(8));
                updateGoogleLink(lat, lng);

                if (marker) {
                    marker.setPosition({ lat, lng });
                }

                if (circle) {
                    circle.setCenter({ lat, lng });
                }

                if (map && center) {
                    map.setCenter({ lat, lng });
                    map.setZoom(Math.max(map.getZoom() || 14, 15));
                }
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

            const syncPolygonInput = () => {
                if (!polygon || !polygonInput) return;

                const points = polygon.getPath().getArray().map((point) => ({
                    lat: Number(point.lat().toFixed(8)),
                    lng: Number(point.lng().toFixed(8)),
                }));

                setInputValue(polygonInput, JSON.stringify(points, null, 2));

                if (points.length > 0) {
                    const avgLat = points.reduce((sum, point) => sum + point.lat, 0) / points.length;
                    const avgLng = points.reduce((sum, point) => sum + point.lng, 0) / points.length;
                    sync(avgLat, avgLng);
                }
            };

            const renderShape = () => {
                if (!map) return;

                const shape = shapeInput?.value || fallbackShape || 'circle';
                circle?.setMap(shape === 'polygon' ? null : map);
                polygon?.setMap(shape === 'polygon' ? map : null);
                drawingManager?.setMap(shape === 'polygon' ? map : null);
            };

            updateGoogleLink(initialLat, initialLng);

            locationButton?.addEventListener('click', () => {
                if (!navigator.geolocation) return;

                navigator.geolocation.getCurrentPosition((position) => {
                    sync(position.coords.latitude, position.coords.longitude, true);
                });
            });

            [latInput, lngInput].forEach((input) => {
                input?.addEventListener('input', () => {
                    const lat = parseFloat(latInput?.value);
                    const lng = parseFloat(lngInput?.value);

                    if (!Number.isFinite(lat) || !Number.isFinite(lng)) return;

                    updateGoogleLink(lat, lng);
                    marker?.setPosition({ lat, lng });
                    circle?.setCenter({ lat, lng });
                    map?.setCenter({ lat, lng });
                });
            });

            radiusInput?.addEventListener('input', () => {
                const value = parseInt(radiusInput.value || '1', 10);

                if (circle && Number.isFinite(value)) {
                    circle.setRadius(Math.max(1, value));
                }
            });

            shapeInput?.addEventListener('change', renderShape);

            if (!googleMapsKey) {
                mapElement.dataset.loaded = '1';
                return;
            }

            try {
                await window.jojoLoadGoogleMaps(googleMapsKey);
            } catch (error) {
                mapElement.dataset.loaded = '';
                mapElement.textContent = 'Google Maps gagal dimuat. Gunakan tombol Buka Google Maps untuk mengambil koordinat.';
                return;
            }

            if (!window.google?.maps) {
                mapElement.dataset.loaded = '';
                mapElement.textContent = 'Google Maps belum siap. Refresh halaman atau gunakan tombol Buka Google Maps.';
                return;
            }

            mapElement.innerHTML = '';
            mapElement.style.display = 'block';
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
                title: 'Titik pusat geofence',
            });
            circle = new window.google.maps.Circle({
                map,
                center: { lat: initialLat, lng: initialLng },
                radius: initialRadius,
                strokeColor: '#16a34a',
                strokeOpacity: 0.9,
                strokeWeight: 2,
                fillColor: '#22c55e',
                fillOpacity: 0.18,
            });
            const polygonPath = polygonPathFromInput();
            polygon = new window.google.maps.Polygon({
                paths: polygonPath,
                map,
                editable: true,
                draggable: true,
                strokeColor: '#f59e0b',
                strokeOpacity: 0.95,
                strokeWeight: 2,
                fillColor: '#f59e0b',
                fillOpacity: 0.2,
            });
            polygon.getPath().addListener('set_at', syncPolygonInput);
            polygon.getPath().addListener('insert_at', syncPolygonInput);
            polygon.getPath().addListener('remove_at', syncPolygonInput);

            if (window.google.maps.drawing) {
                drawingManager = new window.google.maps.drawing.DrawingManager({
                    drawingControl: true,
                    drawingMode: polygonPath.length >= 3 ? null : window.google.maps.drawing.OverlayType.POLYGON,
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
                window.google.maps.event.addListener(drawingManager, 'polygoncomplete', (newPolygon) => {
                    polygon?.setMap(null);
                    polygon = newPolygon;
                    polygon.getPath().addListener('set_at', syncPolygonInput);
                    polygon.getPath().addListener('insert_at', syncPolygonInput);
                    polygon.getPath().addListener('remove_at', syncPolygonInput);
                    drawingManager.setDrawingMode(null);
                    syncPolygonInput();
                    renderShape();
                });
            }

            if (polygonPath.length >= 3) {
                const bounds = new window.google.maps.LatLngBounds();
                polygonPath.forEach((point) => bounds.extend(point));
                map.fitBounds(bounds);
            } else {
                map.fitBounds(circle.getBounds());
            }
            renderShape();

            map.addListener('click', (event) => {
                if (!event.latLng) return;

                sync(event.latLng.lat(), event.latLng.lng());
            });

            marker.addListener('dragend', () => {
                const position = marker.getPosition();
                if (!position) return;

                sync(position.lat(), position.lng());
            });

            window.setTimeout(() => {
                window.google.maps.event.trigger(map, 'resize');
                map.setCenter({ lat: initialLat, lng: initialLng });
            }, 300);
        };

        document.addEventListener('livewire:navigated', init);
        document.addEventListener('DOMContentLoaded', init);
        [150, 600, 1500].forEach((delay) => setTimeout(init, delay));
    })();
</script>
