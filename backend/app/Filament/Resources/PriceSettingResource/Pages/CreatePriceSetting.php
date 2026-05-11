<?php

namespace App\Filament\Resources\PriceSettingResource\Pages;

use App\Filament\Resources\PriceSettingResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePriceSetting extends CreateRecord
{
    protected static string $resource = PriceSettingResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return PriceSettingResource::normalizePricingData($data);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
