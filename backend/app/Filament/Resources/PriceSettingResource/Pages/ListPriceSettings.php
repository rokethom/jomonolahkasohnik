<?php

namespace App\Filament\Resources\PriceSettingResource\Pages;

use App\Filament\Resources\PriceSettingResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListPriceSettings extends ListRecords
{
    protected static string $resource = PriceSettingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
