@php
    $mapId = 'ring-polygon-map-'.\Illuminate\Support\Str::uuid();
    $statePolygon = is_callable($get ?? null) ? $get('polygon_coordinates') : null;
    $polygon = is_array($statePolygon) ? $statePolygon : (json_decode((string) $statePolygon, true) ?: []);
    $branchId = is_callable($get ?? null) ? $get('branch_id') : null;
    $branch = $branchId ? \App\Models\Branch::query()->find($branchId) : null;
    $lat = is_numeric($branch?->latitude) ? (float) $branch->latitude : -7.70630000;
    $lng = is_numeric($branch?->longitude) ? (float) $branch->longitude : 114.00980000;
@endphp

@once
    <link
        rel="stylesheet"
        href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
        integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY="
        crossorigin=""
    />
    <script
        src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo="
        crossorigin=""
    ></script>
@endonce

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
        style="height: 420px; border-radius: 8px; overflow: hidden; border: 1px solid rgba(148, 163, 184, .35); background: rgba(15, 23, 42, .22); display: grid; place-items: center; color: rgb(148, 163, 184);"
    >Memuat Leaflet map...</div>
</div>

<script>
    (() => {
        const mapId = @json($mapId);
        const fallbackLat = @json($lat);
        const fallbackLng = @json($lng);
        const fallbackPolygon = @json($polygon);

        const extractPolygonPoints = (value) => {
            if (!value || typeof value !== 'object') return { geometry: null, points: [] };

            if (value.type === 'FeatureCollection') {
                const linePoints = [];
                for (const feature of value.features || []) {
                    const extracted = extractPolygonPoints(feature);
                    if (extracted.geometry === 'polygon' && extracted.points.length) return extracted;
                    if (extracted.geometry === 'line') linePoints.push(...extracted.points);
                }
                return { geometry: linePoints.length ? 'line' : null, points: linePoints };
            }

            if (value.type === 'Feature') {
                return extractPolygonPoints(value.geometry);
            }

            if (value.type === 'GeometryCollection') {
                const linePoints = [];
                for (const geometry of value.geometries || []) {
                    const extracted = extractPolygonPoints(geometry);
                    if (extracted.geometry === 'polygon' && extracted.points.length) return extracted;
                    if (extracted.geometry === 'line') linePoints.push(...extracted.points);
                }
                return { geometry: linePoints.length ? 'line' : null, points: linePoints };
            }

            if (value.type === 'Polygon') {
                return { geometry: 'polygon', points: Array.isArray(value.coordinates?.[0]) ? value.coordinates[0] : [] };
            }

            if (value.type === 'MultiPolygon') {
                return { geometry: 'polygon', points: Array.isArray(value.coordinates?.[0]?.[0]) ? value.coordinates[0][0] : [] };
            }

            if (value.type === 'LineString') {
                return { geometry: 'line', points: Array.isArray(value.coordinates) ? value.coordinates : [] };
            }

            if (value.type === 'MultiLineString') {
                return { geometry: 'line', points: Array.isArray(value.coordinates) ? value.coordinates.flat() : [] };
            }

            return { geometry: 'polygon', points: Array.isArray(value) ? value : [] };
        };

        const normalizePoint = (point) => {
            if (!point || typeof point !== 'object') return null;

            let lat = point.lat ?? point.latitude;
            let lng = point.lng ?? point.longitude;

            if ((!Number.isFinite(Number(lat)) || !Number.isFinite(Number(lng))) && Array.isArray(point) && point.length >= 2) {
                lng = point[0];
                lat = point[1];
            }

            lat = Number(lat);
            lng = Number(lng);

            return Number.isFinite(lat) && Number.isFinite(lng) ? { lat, lng } : null;
        };

        window.jojoLoadLeaflet = window.jojoLoadLeaflet || (() => {
            if (window.L?.map) {
                return Promise.resolve(window.L);
            }

            if (window.jojoLeafletPromise) {
                return window.jojoLeafletPromise;
            }

            window.jojoLeafletPromise = new Promise((resolve, reject) => {
                const existingScript = document.getElementById('jojo-leaflet-js');
                const finish = () => window.L?.map ? resolve(window.L) : reject(new Error('Leaflet tidak siap'));

                const cdnReadyWait = (startedAt = Date.now()) => {
                    if (window.L?.map) {
                        finish();
                        return;
                    }

                    if (Date.now() - startedAt > 8000) {
                        if (existingScript) {
                            existingScript.addEventListener('load', finish, { once: true });
                            existingScript.addEventListener('error', () => reject(new Error('Leaflet gagal dimuat')), { once: true });
                            return;
                        }

                        const script = document.createElement('script');
                        script.id = 'jojo-leaflet-js';
                        script.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
                        script.integrity = 'sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=';
                        script.crossOrigin = '';
                        script.async = true;
                        script.onload = finish;
                        script.onerror = () => reject(new Error('Leaflet gagal dimuat'));
                        document.head.appendChild(script);
                        return;
                    }

                    window.setTimeout(() => cdnReadyWait(startedAt), 120);
                };

                cdnReadyWait();
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
                    const raw = JSON.parse(value || '[]');
                    const extracted = extractPolygonPoints(raw);
                    const points = extracted.points || [];
                    const normalized = Array.isArray(points)
                        ? points.map(normalizePoint).filter(Boolean)
                        : [];

                    return extracted.geometry === 'line' ? convexHull(normalized) : normalized;
                } catch (error) {
                    return [];
                }
            };

            const convexHull = (rawPoints) => {
                const unique = [...new Map(rawPoints.map((point) => [`${point.lng},${point.lat}`, point])).values()]
                    .sort((a, b) => a.lng === b.lng ? a.lat - b.lat : a.lng - b.lng);

                if (unique.length <= 3) return unique;

                const cross = (origin, a, b) => ((a.lng - origin.lng) * (b.lat - origin.lat)) - ((a.lat - origin.lat) * (b.lng - origin.lng));
                const lower = [];
                for (const point of unique) {
                    while (lower.length >= 2 && cross(lower[lower.length - 2], lower[lower.length - 1], point) <= 0) lower.pop();
                    lower.push(point);
                }

                const upper = [];
                for (const point of [...unique].reverse()) {
                    while (upper.length >= 2 && cross(upper[upper.length - 2], upper[upper.length - 1], point) <= 0) upper.pop();
                    upper.push(point);
                }

                lower.pop();
                upper.pop();

                return [...lower, ...upper];
            };

            mapElement.dataset.loaded = '1';
            mapElement.innerHTML = '';
            mapElement.style.display = 'block';
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
