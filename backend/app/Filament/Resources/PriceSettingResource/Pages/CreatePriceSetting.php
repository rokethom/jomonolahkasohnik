<?php

namespace App\Filament\Resources\PriceSettingResource\Pages;

use App\Filament\Resources\PriceSettingResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePriceSetting extends CreateRecord
{
    protected static string $resource = PriceSettingResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
