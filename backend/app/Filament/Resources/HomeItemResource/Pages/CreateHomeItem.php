<?php

namespace App\Filament\Resources\HomeItemResource\Pages;

use App\Filament\Resources\HomeItemResource;
use Filament\Resources\Pages\CreateRecord;

class CreateHomeItem extends CreateRecord
{
    protected static string $resource = HomeItemResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
