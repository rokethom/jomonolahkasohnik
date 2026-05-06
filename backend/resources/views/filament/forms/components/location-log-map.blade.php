@php
    $record = $this->record;
    $mapId = 'location-log-map-'.\Illuminate\Support\Str::uuid();
    $lat = (float) $record->latitude;
    $lng = (float) $record->longitude;
    $geofence = $record->geofenceArea;
    $circleLat = $geofence ? (float) $geofence->center_latitude : $lat;
    $circleLng = $geofence ? (float) $geofence->center_longitude : $lng;
    $radius = $geofence ? (int) $geofence->radius_meters : 100;
    $color = $record->is_valid ? '#16a34a' : '#dc2626';
@endphp

@once
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
@endonce

<div
    id="{{ $mapId }}"
    style="height: 420px; border-radius: 8px; overflow: hidden; border: 1px solid rgba(148, 163, 184, .35);"
></div>

<script>
    (() => {
        const init = () => {
            const mapElement = document.getElementById(@json($mapId));

            if (!mapElement || mapElement.dataset.loaded === '1' || typeof L === 'undefined') {
                return;
            }

            mapElement.dataset.loaded = '1';

            const map = L.map(mapElement).setView([@json($lat), @json($lng)], 15);

            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; OpenStreetMap',
            }).addTo(map);

            const marker = L.circleMarker([@json($lat), @json($lng)], {
                radius: 9,
                color: @json($color),
                fillColor: @json($color),
                fillOpacity: 0.9,
            }).addTo(map);

            const circle = L.circle([@json($circleLat), @json($circleLng)], {
                radius: @json($radius),
                color: @json($color),
                fillColor: @json($color),
                fillOpacity: 0.12,
            }).addTo(map);

            marker.bindPopup(@json($record->is_valid ? 'Valid location' : 'Invalid location'));

            setTimeout(() => {
                map.invalidateSize();
                map.fitBounds(L.featureGroup([marker, circle]).getBounds(), { padding: [24, 24], maxZoom: 16 });
            }, 250);
        };

        document.addEventListener('livewire:navigated', init);
        document.addEventListener('DOMContentLoaded', init);
        setTimeout(init, 250);
    })();
</script>
