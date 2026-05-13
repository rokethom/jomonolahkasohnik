<?php

namespace App\Filament\Resources\GeojsonRegionResource\Pages;

use App\Filament\Resources\GeojsonRegionResource;
use App\Models\GeojsonRegion;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateGeojsonRegion extends CreateRecord
{
    protected static string $resource = GeojsonRegionResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $records = collect(GeojsonRegionResource::normalizeGeojsonRows($data))
            ->map(fn (array $row): GeojsonRegion => GeojsonRegion::query()->create($row));

        return $records->firstOrFail();
    }
}
