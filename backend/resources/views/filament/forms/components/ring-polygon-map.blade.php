@php
    $mapId = 'ring-polygon-map-'.\Illuminate\Support\Str::uuid();
    $statePolygon = is_callable($get ?? null) ? $get('polygon_coordinates') : null;
    $polygon = is_array($statePolygon) ? $statePolygon : (json_decode((string) $statePolygon, true) ?: []);
    $branchId = is_callable($get ?? null) ? $get('branch_id') : null;
    $branch = $branchId ? \App\Models\Branch::query()->find($branchId) : null;
    $lat = is_numeric($branch?->latitude) ? (float) $branch->latitude : -7.70630000;
    $lng = is_numeric($branch?->longitude) ? (float) $branch->longitude : 114.00980000;
@endphp

<div class="space-y-3">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="text-sm text-gray-600 dark:text-gray-300">
            Gambar batas Ring untuk cabang terpilih. Klik map untuk menambah titik, geser marker untuk mengubah, klik marker untuk menghapus.
        </div>
        <div class="flex flex-wrap gap-2">
            <button
                type="button"
                id="{{ $mapId }}-clear"
                class="rounded-lg bg-rose-600 px-3 py-2 text-sm font-semibold text-white shadow transition hover:bg-rose-700"
            >
                Reset Polygon
            </button>
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
    </div>

    <div
        id="{{ $mapId }}"
        style="height: 420px; border-radius: 8px; overflow: hidden; border: 1px solid rgba(148, 163, 184, .35); background: rgba(15, 23, 42, .22);"
    ></div>
</div>

<script>
    (() => {
        const mapId = @json($mapId);
        const fallbackLat = @json($lat);
        const fallbackLng = @json($lng);
        const fallbackPolygon = @json($polygon);

        window.jojoLoadLeaflet = window.jojoLoadLeaflet || (() => {
            if (window.L?.map) {
                return Promise.resolve(window.L);
            }

            if (window.jojoLeafletPromise) {
                return window.jojoLeafletPromise;
            }

            window.jojoLeafletPromise = new Promise((resolve, reject) => {
                const cssId = 'jojo-leaflet-css';
                if (!document.getElementById(cssId)) {
                    const link = document.createElement('link');
                    link.id = cssId;
                    link.rel = 'stylesheet';
                    link.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
                    document.head.appendChild(link);
                }

                const existingScript = document.getElementById('jojo-leaflet-js');
                const finish = () => window.L?.map ? resolve(window.L) : reject(new Error('Leaflet tidak siap'));

                if (existingScript) {
                    if (window.L?.map) {
                        finish();
                    } else {
                        existingScript.addEventListener('load', finish, { once: true });
                        existingScript.addEventListener('error', () => reject(new Error('Leaflet gagal dimuat')), { once: true });
                    }
                    return;
                }

                const script = document.createElement('script');
                script.id = 'jojo-leaflet-js';
                script.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
                script.async = true;
                script.onload = finish;
                script.onerror = () => reject(new Error('Leaflet gagal dimuat'));
                document.head.appendChild(script);
            });

            return window.jojoLeafletPromise;
        });

        const init = async () => {
            const mapElement = document.getElementById(mapId);
            if (!mapElement || mapElement.dataset.loaded === '1' || mapElement.dataset.loaded === 'loading') {
                return;
            }

            mapElement.dataset.loaded = 'loading';

            let L = null;
            try {
                L = await window.jojoLoadLeaflet();
            } catch (error) {
                mapElement.dataset.loaded = '';
                mapElement.style.display = 'grid';
                mapElement.style.placeItems = 'center';
                mapElement.style.color = 'rgb(148, 163, 184)';
                mapElement.textContent = 'Leaflet map gagal dimuat. Cek koneksi CDN atau tempel JSON polygon manual.';
                return;
            }

            const polygonInput = document.getElementById('ring_polygon_coordinates') || document.querySelector('textarea[name="data[polygon_coordinates]"]');
            const clearButton = document.getElementById(`${mapId}-clear`);
            const googleLink = document.getElementById(`${mapId}-open-google`);
            const center = [fallbackLat, fallbackLng];

            const setInputValue = (input, value) => {
                if (!input) return;

                input.value = value;
                input.dispatchEvent(new Event('input', { bubbles: true }));
                input.dispatchEvent(new Event('change', { bubbles: true }));
                input.dispatchEvent(new Event('blur', { bubbles: true }));
            };

            const pointsFromInput = () => {
                try {
                    const value = polygonInput?.value || JSON.stringify(fallbackPolygon || []);
                    const points = JSON.parse(value || '[]');

                    return Array.isArray(points)
                        ? points.map((point) => ({
                            lat: parseFloat(point.lat ?? point.latitude),
                            lng: parseFloat(point.lng ?? point.longitude),
                        })).filter((point) => Number.isFinite(point.lat) && Number.isFinite(point.lng))
                        : [];
                } catch (error) {
                    return [];
                }
            };

            mapElement.dataset.loaded = '1';
            const map = L.map(mapElement, { scrollWheelZoom: true }).setView(center, 13);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; OpenStreetMap',
            }).addTo(map);

            let points = pointsFromInput();
            let polygonLayer = null;
            let markers = [];

            const sync = () => {
                setInputValue(polygonInput, JSON.stringify(points.map((point) => ({
                    lat: Number(point.lat.toFixed(8)),
                    lng: Number(point.lng.toFixed(8)),
                })), null, 2));

                if (googleLink && points.length > 0) {
                    googleLink.href = `https://www.google.com/maps?q=${points[0].lat},${points[0].lng}`;
                }
            };

            const redraw = () => {
                markers.forEach((marker) => marker.remove());
                markers = [];

                if (polygonLayer) {
                    polygonLayer.remove();
                    polygonLayer = null;
                }

                points.forEach((point, index) => {
                    const marker = L.marker([point.lat, point.lng], { draggable: true }).addTo(map);
                    marker.bindTooltip(`Titik ${index + 1}. Klik untuk hapus.`, { direction: 'top' });
                    marker.on('dragend', () => {
                        const next = marker.getLatLng();
                        points[index] = { lat: next.lat, lng: next.lng };
                        sync();
                        redraw();
                    });
                    marker.on('click', () => {
                        points.splice(index, 1);
                        sync();
                        redraw();
                    });
                    markers.push(marker);
                });

                if (points.length >= 2) {
                    polygonLayer = L.polygon(points.map((point) => [point.lat, point.lng]), {
                        color: '#f59e0b',
                        fillColor: '#f59e0b',
                        fillOpacity: 0.22,
                        weight: 2,
                    }).addTo(map);
                }
            };

            map.on('click', (event) => {
                points.push({ lat: event.latlng.lat, lng: event.latlng.lng });
                sync();
                redraw();
            });

            clearButton?.addEventListener('click', () => {
                points = [];
                sync();
                redraw();
            });

            redraw();
            sync();

            if (points.length >= 3) {
                map.fitBounds(L.latLngBounds(points.map((point) => [point.lat, point.lng])), { padding: [28, 28] });
            }

            window.setTimeout(() => map.invalidateSize(), 250);
            window.setTimeout(() => map.invalidateSize(), 900);
        };

        document.addEventListener('livewire:navigated', init);
        document.addEventListener('DOMContentLoaded', init);
        [150, 600, 1500].forEach((delay) => setTimeout(init, delay));
    })();
</script>
