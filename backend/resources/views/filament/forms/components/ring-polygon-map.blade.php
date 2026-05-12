@php
    $mapId = 'ring-polygon-map-'.\Illuminate\Support\Str::uuid();
    $statePolygon = is_callable($get ?? null) ? $get('polygon_coordinates') : null;
    $polygon = is_array($statePolygon) ? $statePolygon : (json_decode((string) $statePolygon, true) ?: []);
    $branchId = is_callable($get ?? null) ? $get('branch_id') : null;
    $branch = $branchId ? \App\Models\Branch::query()->find($branchId) : null;
    $lat = is_numeric($branch?->latitude) ? (float) $branch->latitude : -7.70630000;
    $lng = is_numeric($branch?->longitude) ? (float) $branch->longitude : 114.00980000;
    $mapboxToken = app(\App\Services\SettingService::class)->get('mapbox_api_key');
@endphp

@once
    <link href="https://api.mapbox.com/mapbox-gl-js/v3.9.4/mapbox-gl.css" rel="stylesheet">
    <link href="https://api.mapbox.com/mapbox-gl-js/plugins/mapbox-gl-draw/v1.5.0/mapbox-gl-draw.css" rel="stylesheet">
    <script src="https://api.mapbox.com/mapbox-gl-js/v3.9.4/mapbox-gl.js"></script>
    <script src="https://api.mapbox.com/mapbox-gl-js/plugins/mapbox-gl-draw/v1.5.0/mapbox-gl-draw.js"></script>
@endonce

<div class="space-y-3">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="text-sm text-gray-600 dark:text-gray-300">
            Gambar polygon ring seperti di geojson.io. Bisa juga paste GeoJSON, lalu klik Muat JSON ke Map.
        </div>
        <div class="flex flex-wrap gap-2">
            <button
                type="button"
                id="{{ $mapId }}-load-json"
                class="rounded-lg bg-emerald-600 px-3 py-2 text-sm font-semibold text-white shadow transition hover:bg-emerald-700"
            >
                Muat JSON ke Map
            </button>
            <button
                type="button"
                id="{{ $mapId }}-clear"
                class="rounded-lg bg-rose-600 px-3 py-2 text-sm font-semibold text-white shadow transition hover:bg-rose-700"
            >
                Reset Polygon
            </button>
            <a
                href="https://geojson.io/"
                target="_blank"
                rel="noreferrer"
                class="rounded-lg bg-sky-600 px-3 py-2 text-sm font-semibold text-white shadow transition hover:bg-sky-700"
            >
                Buka geojson.io
            </a>
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
        style="height: 460px; border-radius: 8px; overflow: hidden; border: 1px solid rgba(148, 163, 184, .35); background: rgba(15, 23, 42, .22); display: grid; place-items: center; color: rgb(148, 163, 184);"
    >Memuat Mapbox Draw...</div>

    @unless($mapboxToken)
        <div class="rounded-lg border border-amber-500/40 bg-amber-500/10 p-3 text-sm text-amber-700 dark:text-amber-200">
            Mapbox API key belum aktif. Isi dan aktifkan <b>mapbox_api_key</b> di System Settings agar map drawing tampil. Paste GeoJSON tetap bisa disimpan lewat field koordinat.
        </div>
    @endunless
</div>

<script>
    (() => {
        const mapId = @json($mapId);
        const fallbackLat = @json($lat);
        const fallbackLng = @json($lng);
        const fallbackPolygon = @json($polygon);
        const mapboxToken = @json($mapboxToken);

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

            if (value.type === 'Feature') return extractPolygonPoints(value.geometry);

            if (value.type === 'GeometryCollection') {
                const linePoints = [];
                for (const geometry of value.geometries || []) {
                    const extracted = extractPolygonPoints(geometry);
                    if (extracted.geometry === 'polygon' && extracted.points.length) return extracted;
                    if (extracted.geometry === 'line') linePoints.push(...extracted.points);
                }
                return { geometry: linePoints.length ? 'line' : null, points: linePoints };
            }

            if (value.type === 'Polygon') return { geometry: 'polygon', points: Array.isArray(value.coordinates?.[0]) ? value.coordinates[0] : [] };
            if (value.type === 'MultiPolygon') return { geometry: 'polygon', points: Array.isArray(value.coordinates?.[0]?.[0]) ? value.coordinates[0][0] : [] };
            if (value.type === 'LineString') return { geometry: 'line', points: Array.isArray(value.coordinates) ? value.coordinates : [] };
            if (value.type === 'MultiLineString') return { geometry: 'line', points: Array.isArray(value.coordinates) ? value.coordinates.flat() : [] };

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

        const parsePoints = (value) => {
            try {
                const raw = typeof value === 'string' ? JSON.parse(value || '[]') : value;
                const extracted = extractPolygonPoints(raw);
                const normalized = (extracted.points || []).map(normalizePoint).filter(Boolean);

                return extracted.geometry === 'line' ? convexHull(normalized) : normalized;
            } catch (error) {
                return [];
            }
        };

        const waitForMapbox = (startedAt = Date.now()) => new Promise((resolve, reject) => {
            const tick = () => {
                if (window.mapboxgl?.Map && window.MapboxDraw) {
                    resolve({ mapboxgl: window.mapboxgl, MapboxDraw: window.MapboxDraw });
                    return;
                }

                if (Date.now() - startedAt > 12000) {
                    reject(new Error('Mapbox GL JS tidak siap'));
                    return;
                }

                window.setTimeout(tick, 120);
            };

            tick();
        });

        const init = async () => {
            const mapElement = document.getElementById(mapId);
            if (!mapElement || mapElement.dataset.loaded === '1' || mapElement.dataset.loaded === 'loading') return;

            if (!mapboxToken) {
                mapElement.textContent = 'Mapbox API key belum aktif. Paste GeoJSON di field koordinat, atau aktifkan Mapbox di System Settings.';
                return;
            }

            mapElement.dataset.loaded = 'loading';

            let mapboxgl = null;
            let MapboxDraw = null;
            try {
                ({ mapboxgl, MapboxDraw } = await waitForMapbox());
            } catch (error) {
                mapElement.dataset.loaded = '';
                mapElement.textContent = 'Mapbox Draw gagal dimuat. Paste GeoJSON di field koordinat atau cek koneksi CDN.';
                return;
            }

            const polygonInput = document.getElementById('ring_polygon_coordinates') || document.querySelector('textarea[name="data[polygon_coordinates]"]');
            const clearButton = document.getElementById(`${mapId}-clear`);
            const loadJsonButton = document.getElementById(`${mapId}-load-json`);
            const googleLink = document.getElementById(`${mapId}-open-google`);

            const setInputValue = (input, value) => {
                if (!input) return;
                input.value = value;
                input.dispatchEvent(new Event('input', { bubbles: true }));
                input.dispatchEvent(new Event('change', { bubbles: true }));
                input.dispatchEvent(new Event('blur', { bubbles: true }));
            };

            const pointsToFeature = (points) => {
                if (points.length < 3) return null;
                const coordinates = points.map((point) => [point.lng, point.lat]);
                const first = coordinates[0];
                const last = coordinates[coordinates.length - 1];
                if (first[0] !== last[0] || first[1] !== last[1]) coordinates.push(first);

                return {
                    type: 'Feature',
                    properties: {},
                    geometry: {
                        type: 'Polygon',
                        coordinates: [coordinates],
                    },
                };
            };

            const syncFromDraw = () => {
                const feature = draw.getAll().features.find((item) => item.geometry?.type === 'Polygon');
                if (!feature) {
                    setInputValue(polygonInput, '[]');
                    return;
                }

                const ring = feature.geometry.coordinates?.[0] || [];
                const points = ring
                    .slice(0, -1)
                    .map((coordinate) => ({ lng: Number(coordinate[0]), lat: Number(coordinate[1]) }))
                    .filter((point) => Number.isFinite(point.lat) && Number.isFinite(point.lng));

                setInputValue(polygonInput, JSON.stringify(points.map((point) => ({
                    lat: Number(point.lat.toFixed(8)),
                    lng: Number(point.lng.toFixed(8)),
                })), null, 2));

                if (googleLink && points.length > 0) {
                    googleLink.href = `https://www.google.com/maps?q=${points[0].lat},${points[0].lng}`;
                }
            };

            const loadInputToDraw = () => {
                const points = parsePoints(polygonInput?.value || JSON.stringify(fallbackPolygon || []));
                const feature = pointsToFeature(points);
                draw.deleteAll();

                if (!feature) return;

                draw.add(feature);
                const bounds = points.reduce(
                    (bounds, point) => bounds.extend([point.lng, point.lat]),
                    new mapboxgl.LngLatBounds([points[0].lng, points[0].lat], [points[0].lng, points[0].lat]),
                );
                map.fitBounds(bounds, { padding: 42, duration: 0 });
                syncFromDraw();
            };

            mapElement.dataset.loaded = '1';
            mapElement.innerHTML = '';
            mapElement.style.display = 'block';

            mapboxgl.accessToken = mapboxToken;
            const map = new mapboxgl.Map({
                container: mapElement,
                style: 'mapbox://styles/mapbox/streets-v12',
                center: [fallbackLng, fallbackLat],
                zoom: 13,
            });
            const draw = new MapboxDraw({
                displayControlsDefault: false,
                controls: {
                    polygon: true,
                    trash: true,
                },
                defaultMode: 'draw_polygon',
            });

            map.addControl(new mapboxgl.NavigationControl(), 'top-right');
            map.addControl(draw, 'top-left');
            map.on('draw.create', syncFromDraw);
            map.on('draw.update', syncFromDraw);
            map.on('draw.delete', syncFromDraw);
            map.on('load', loadInputToDraw);
            clearButton?.addEventListener('click', () => {
                draw.deleteAll();
                setInputValue(polygonInput, '[]');
            });
            loadJsonButton?.addEventListener('click', loadInputToDraw);

            window.setTimeout(() => map.resize(), 250);
            window.setTimeout(() => map.resize(), 900);
        };

        document.addEventListener('livewire:navigated', init);
        document.addEventListener('DOMContentLoaded', init);
        [150, 600, 1500].forEach((delay) => setTimeout(init, delay));
    })();
</script>
