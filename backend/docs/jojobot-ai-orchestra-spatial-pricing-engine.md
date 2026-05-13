# Jojobot AI Orchestra Spatial Pricing Engine

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
3. `SpatialAgent` mendeteksi GeoJSON Region memakai bounding box dan ray casting.
4. `PricingAgent` menghitung Haversine distance.
5. `PricingRuleService` memilih `pricing_rings` berdasarkan min/max KM.
6. `PricingFormulaService` menghitung harga.
7. `PricingCalculated` event mengirim queue job untuk `pricing_logs`.
8. `AnalyticsAgent` mengirim queue job untuk `ai_logs`.

Aturan penting:

- GeoJSON tidak digunakan untuk menentukan harga.
- GeoJSON hanya untuk spatial intelligence, coverage, geofence, region mapping, dan area detection.
- Harga selalu ditentukan oleh Haversine distance dan `pricing_rings`.
