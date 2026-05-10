<?php

namespace App\Filament\Resources\ZonePricingRuleResource\Pages;

use App\Filament\Resources\ZonePricingRuleResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListZonePricingRules extends ListRecords
{
    protected static string $resource = ZonePricingRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
