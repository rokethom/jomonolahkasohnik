<?php

namespace App\Filament\Resources\GeofenceAreaResource\Pages;

use App\Filament\Resources\GeofenceAreaResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListGeofenceAreas extends ListRecords
{
    protected static string $resource = GeofenceAreaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
