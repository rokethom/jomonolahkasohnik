# Jojobot AI Orchestra Spatial Pricing Engine

## Status Pricing Saat Ini

Sistem pricing aktif saat ini memakai engine baru:

`POST /api/v1/pricing/calculate`

Engine ini membaca tabel:

- `pricing_rings` untuk aturan harga Ring 1, Ring 2, Ring 3.
- `geojson_regions` hanya untuk mendeteksi cabang, area, coverage, dan spatial intelligence.
- `pricing_logs` untuk menyimpan hasil kalkulasi.
- `ai_logs` untuk menyimpan jejak workflow AI Orchestra.

Menu lama `Master Ring` / `ring_pricing_rules` tidak dipakai untuk flow pricing baru dan disembunyikan dari navigasi Filament. Jika masih terlihat dari recent page/browser cache, menu itu bukan sumber harga aktif. Sumber harga aktif adalah menu **Pricing Management > Pricing Rings**.

## Aturan Utama

GeoJSON tidak boleh menentukan harga.

GeoJSON hanya dipakai untuk:

- spatial intelligence
- area detection
- geofence
- coverage area
- region mapping
- referensi AI geocode

Harga selalu ditentukan oleh:

- Haversine distance pickup ke destination
- tabel `pricing_rings`
- formula pricing

Endpoint utama:

`POST /api/v1/pricing/calculate`

Request:

```json
{
  "pickup_latitude": -7.8921,
  "pickup_longitude": 113.8211,
  "destination_latitude": -7.9121,
  "destination_longitude": 113.8511
}
```

Flow:

1. Validasi koordinat.
2. `GeocodeAgent` membaca pickup dan destination point.
3. `SpatialAgent` membaca `geojson_regions`.
4. Spatial engine melakukan bounding box filtering.
5. Spatial engine menjalankan ray casting point-in-polygon.
6. Jika titik destination masuk region, sistem mengambil `branch` dan `area` dari region.
7. Jika tidak masuk region, sistem fallback ke cabang terdekat.
8. `PricingAgent` menghitung jarak Haversine pickup ke destination.
9. `PricingRuleService` memilih `pricing_rings` berdasarkan min/max KM.
10. `PricingFormulaService` menghitung harga final.
11. `PricingCalculated` event mengirim queue job untuk `pricing_logs`.
12. `AnalyticsAgent` mengirim queue job untuk `ai_logs`.

## Flow Perhitungan Harga

1. Customer membuat order.
2. Customer mengirim `pickup_latitude`, `pickup_longitude`, `destination_latitude`, `destination_longitude`.
3. Sistem menghitung jarak Haversine dari pickup ke destination.
4. Sistem membaca GeoJSON Region untuk mengenali area/cabang.
5. Sistem membaca Pricing Rings.
6. Sistem memilih ring berdasarkan jarak:

- Ring 1: `0 KM` sampai `4 KM`
- Ring 2: `4.1 KM` sampai `9 KM`
- Ring 3: lebih dari `9 KM`

7. Sistem menjalankan formula:

- Ring 1: `6000 + 1000 = 7000`
- Ring 2: `12000 + 1000 = 13000`
- Ring 3: `(distance_km * 1900) - 7000`

8. Sistem menyimpan log.
9. Sistem mengembalikan harga ke customer.

## Contoh

Pickup: Situbondo Kota

Destination: Agel

Hasil Haversine: `12 KM`

Evaluasi ring:

- Ring 1: false
- Ring 2: false
- Ring 3: true

Formula:

```text
(12 * 1900) - 7000 = 15800
```

Response:

```json
{
  "success": true,
  "data": {
    "branch": "Situbondo",
    "area": "Agel",
    "distance_km": 12,
    "pricing_ring": "Ring 3",
    "formula_type": "DISTANCE",
    "price": 15800
  }
}
```

## Menu Filament

Spatial Management:

- **GeoJSON Regions**: paste/import GeoJSON Polygon atau MultiPolygon untuk area detection.
- **Coverage Area**: master area layanan per cabang.
- **Spatial Analytics**: ringkasan region, centroid, bbox, dan mapping.

Pricing Management:

- **Pricing Rings**: sumber utama harga aktif.
- **Pricing Rules**: tampilan read-only aturan ring.
- **Pricing Logs**: histori kalkulasi harga.

AI Management:

- **AI Orchestra**: monitor workflow pricing.
- **AI Rules**: rule AI native Laravel.
- **AI Models**: konfigurasi model/engine native.
- **AI Logs**: log agent.
- **AI Analytics**: analytics workflow.

Aturan penting:

- GeoJSON tidak digunakan untuk menentukan harga.
- GeoJSON hanya untuk spatial intelligence, coverage, geofence, region mapping, dan area detection.
- Harga selalu ditentukan oleh Haversine distance dan `pricing_rings`.
