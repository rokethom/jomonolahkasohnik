<?php

namespace App\Filament\Resources\GeofenceAreaResource\Pages;

use App\Filament\Resources\GeofenceAreaResource;
use Filament\Resources\Pages\CreateRecord;

class CreateGeofenceArea extends CreateRecord
{
    protected static string $resource = GeofenceAreaResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
