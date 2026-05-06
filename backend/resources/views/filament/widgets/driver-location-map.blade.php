@php
    $mapId = 'driver-dashboard-map-'.\Illuminate\Support\Str::uuid();
    $driversData = $drivers->toArray();
    $first = $driversData[0] ?? ['lat' => -6.20000000, 'lng' => 106.81666600];
@endphp

<x-filament-widgets::widget>
    @once
        <style>
            .jojo-dashboard-card {
                background: linear-gradient(135deg, #0f172a 0%, #111827 58%, rgba(120, 53, 15, 0.74) 100%);
                border: 1px solid rgba(148, 163, 184, 0.18);
                border-radius: 18px;
                box-shadow: 0 24px 60px rgba(2, 6, 23, 0.32);
                overflow: hidden;
                padding: 1px;
            }

            .jojo-dashboard-card__inner {
                background: rgba(15, 23, 42, 0.96);
                border-radius: 17px;
                color: #f8fafc;
                padding: 1.25rem;
            }

            .jojo-dashboard-card__meta {
                background: rgba(245, 158, 11, 0.14);
                border: 1px solid rgba(245, 158, 11, 0.22);
                border-radius: 12px;
                color: #fcd34d;
                font-size: 0.875rem;
                font-weight: 700;
                padding: 0.5rem 1rem;
                white-space: nowrap;
            }

            .jojo-dashboard-map {
                background: #020617;
                border: 1px solid rgba(148, 163, 184, 0.18);
                border-radius: 14px;
                height: 460px;
                overflow: hidden;
                width: 100%;
            }

            .jojo-dashboard-map .leaflet-control,
            .jojo-dashboard-map .leaflet-popup-content-wrapper,
            .jojo-dashboard-map .leaflet-popup-tip {
                color: #0f172a;
            }

            @media (max-width: 768px) {
                .jojo-dashboard-card__inner {
                    padding: 1rem;
                }

                .jojo-dashboard-map {
                    height: 360px;
                }
            }
        </style>
    @endonce

    <x-filament::section>
        <div class="jojo-dashboard-card">
            <div class="jojo-dashboard-card__inner">
                <div class="mb-4 flex items-center justify-between gap-4">
                    <div>
                        <h2 class="text-lg font-bold tracking-tight text-white">Driver Live Map</h2>
                        <p class="text-sm text-slate-300">Hijau normal, merah suspicious.</p>
                    </div>
                    <div class="jojo-dashboard-card__meta">
                        {{ count($driversData) }} driver
                    </div>
                </div>

                @once
                    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
                    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
                @endonce

                <div id="{{ $mapId }}" class="jojo-dashboard-map"></div>
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>

<script>
    (() => {
        const init = () => {
            const mapElement = document.getElementById(@json($mapId));

            if (!mapElement || mapElement.dataset.loaded === '1' || typeof L === 'undefined') {
                return;
            }

            mapElement.dataset.loaded = '1';

            const drivers = @json($driversData);
            const map = L.map(mapElement).setView([@json($first['lat']), @json($first['lng'])], 12);

            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; OpenStreetMap',
            }).addTo(map);

            const markers = [];

            drivers.forEach((driver) => {
                const color = driver.is_suspicious ? '#dc2626' : '#16a34a';
                const marker = L.circleMarker([driver.lat, driver.lng], {
                    radius: 10,
                    color,
                    fillColor: color,
                    fillOpacity: 0.9,
                }).addTo(map);

                marker.bindPopup(`
                    <strong>${driver.name}</strong><br>
                    ${driver.username || '-'}<br>
                    ${driver.branch || 'Global'}<br>
                    ${driver.is_suspicious ? 'Suspicious: ' + (driver.reason || '-') : 'Normal'}<br>
                    ${driver.logged_at || ''}
                `);

                markers.push(marker);
            });

            setTimeout(() => {
                map.invalidateSize();

                if (markers.length > 0) {
                    map.fitBounds(L.featureGroup(markers).getBounds(), { padding: [24, 24], maxZoom: 15 });
                }
            }, 250);
        };

        document.addEventListener('livewire:navigated', init);
        document.addEventListener('DOMContentLoaded', init);
        setTimeout(init, 250);
    })();
</script>
