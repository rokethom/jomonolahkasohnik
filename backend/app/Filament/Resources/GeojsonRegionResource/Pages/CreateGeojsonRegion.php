<?php

namespace App\Filament\Resources\GeojsonRegionResource\Pages;

use App\Filament\Resources\GeojsonRegionResource;
use Filament\Resources\Pages\CreateRecord;

class CreateGeojsonRegion extends CreateRecord
{
    protected static string $resource = GeojsonRegionResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return GeojsonRegionResource::normalizeGeojsonData($data);
    }
}
