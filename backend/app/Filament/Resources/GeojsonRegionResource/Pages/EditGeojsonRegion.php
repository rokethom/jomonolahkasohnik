<?php

namespace App\Filament\Resources\GeojsonRegionResource\Pages;

use App\Filament\Resources\GeojsonRegionResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditGeojsonRegion extends EditRecord
{
    protected static string $resource = GeojsonRegionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return GeojsonRegionResource::normalizeGeojsonData($data);
    }
}
