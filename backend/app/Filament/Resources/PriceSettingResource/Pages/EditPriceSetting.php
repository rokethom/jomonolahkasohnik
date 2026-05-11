<?php

namespace App\Filament\Resources\PriceSettingResource\Pages;

use App\Filament\Resources\PriceSettingResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPriceSetting extends EditRecord
{
    protected static string $resource = PriceSettingResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return PriceSettingResource::normalizePricingData($data);
    }

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
