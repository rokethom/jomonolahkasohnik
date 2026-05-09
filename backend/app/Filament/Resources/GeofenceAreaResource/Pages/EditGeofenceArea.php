<?php

namespace App\Filament\Resources\GeofenceAreaResource\Pages;

use App\Filament\Resources\GeofenceAreaResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditGeofenceArea extends EditRecord
{
    protected static string $resource = GeofenceAreaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
